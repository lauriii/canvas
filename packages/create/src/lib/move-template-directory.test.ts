import { lstat, mkdir, mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

import { moveTemplateDirectory } from './move-template-directory.js';

describe('moveTemplateDirectory', () => {
  it('moves only the configured repository directory', async () => {
    const testDir = await mkdtemp(join(tmpdir(), 'create-template-path-'));
    const repositoryDir = join(testDir, 'repository');
    const projectDir = join(testDir, 'project');

    try {
      await mkdir(join(repositoryDir, 'templates', 'nextjs'), {
        recursive: true,
      });
      await mkdir(join(repositoryDir, 'templates', 'astro'));
      await writeFile(
        join(repositoryDir, 'templates', 'nextjs', 'package.json'),
        '{}',
      );
      await writeFile(
        join(repositoryDir, 'templates', 'astro', 'package.json'),
        '{}',
      );
      await writeFile(join(repositoryDir, 'README.md'), '# Templates');

      await moveTemplateDirectory(
        repositoryDir,
        projectDir,
        'templates/nextjs',
      );

      expect((await lstat(join(projectDir, 'package.json'))).isFile()).toBe(
        true,
      );
      await expect(lstat(join(projectDir, 'templates'))).rejects.toMatchObject({
        code: 'ENOENT',
      });
      expect(
        (await lstat(join(repositoryDir, 'templates', 'astro'))).isDirectory(),
      ).toBe(true);
      expect((await lstat(join(repositoryDir, 'README.md'))).isFile()).toBe(
        true,
      );
    } finally {
      await rm(testDir, { recursive: true, force: true });
    }
  });

  it.each(['', '.', '..', '../template', '/template'])(
    'rejects repository path %j when it is not within the repository',
    async (repositoryPath) => {
      const testDir = await mkdtemp(join(tmpdir(), 'create-template-path-'));
      const repositoryDir = join(testDir, 'repository');

      try {
        await mkdir(repositoryDir);

        await expect(
          moveTemplateDirectory(
            repositoryDir,
            join(testDir, 'project'),
            repositoryPath,
          ),
        ).rejects.toThrow('must be a relative path within the repository');
      } finally {
        await rm(testDir, { recursive: true, force: true });
      }
    },
  );

  it('rejects a repository path that does not exist', async () => {
    const testDir = await mkdtemp(join(tmpdir(), 'create-template-path-'));
    const repositoryDir = join(testDir, 'repository');

    try {
      await mkdir(repositoryDir);

      await expect(
        moveTemplateDirectory(
          repositoryDir,
          join(testDir, 'project'),
          'templates/missing',
        ),
      ).rejects.toThrow(
        'Template repository path "templates/missing" does not exist',
      );
    } finally {
      await rm(testDir, { recursive: true, force: true });
    }
  });

  it('rejects a repository path that is not a directory', async () => {
    const testDir = await mkdtemp(join(tmpdir(), 'create-template-path-'));
    const repositoryDir = join(testDir, 'repository');

    try {
      await mkdir(repositoryDir);
      await writeFile(join(repositoryDir, 'template.txt'), 'Template');

      await expect(
        moveTemplateDirectory(
          repositoryDir,
          join(testDir, 'project'),
          'template.txt',
        ),
      ).rejects.toThrow('must point to a directory');
    } finally {
      await rm(testDir, { recursive: true, force: true });
    }
  });

  it('does not replace an existing project directory', async () => {
    const testDir = await mkdtemp(join(tmpdir(), 'create-template-path-'));
    const repositoryDir = join(testDir, 'repository');
    const projectDir = join(testDir, 'project');

    try {
      await mkdir(join(repositoryDir, 'template'), { recursive: true });
      await mkdir(projectDir);

      await expect(
        moveTemplateDirectory(repositoryDir, projectDir, 'template'),
      ).rejects.toThrow('already exists');
    } finally {
      await rm(testDir, { recursive: true, force: true });
    }
  });
});
