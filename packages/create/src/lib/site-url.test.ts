import { lstat, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

import { validateSiteUrl, writeSiteUrlEnv } from './site-url.js';

async function createProjectDir(): Promise<string> {
  return mkdtemp(join(tmpdir(), 'create-site-url-'));
}

describe('validateSiteUrl', () => {
  it.each(['http://canvas.example.com', 'https://canvas.example.com'])(
    'accepts %s',
    (siteUrl) => {
      expect(validateSiteUrl(siteUrl)).toBeUndefined();
    },
  );

  it.each(['', 'canvas.example.com', 'ftp://canvas.example.com'])(
    'rejects %j',
    (siteUrl) => {
      expect(validateSiteUrl(siteUrl)).toBeTypeOf('string');
    },
  );
});

describe('writeSiteUrlEnv', () => {
  it('writes the Canvas site URL and ignores the environment file', async () => {
    const projectDir = await createProjectDir();

    try {
      await writeFile(join(projectDir, '.gitignore'), 'node_modules\n');

      await expect(
        writeSiteUrlEnv(projectDir, 'https://canvas.example.com'),
      ).resolves.toBe('created');

      expect(await readFile(join(projectDir, '.env'), 'utf-8')).toBe(
        'CANVAS_SITE_URL="https://canvas.example.com"\n',
      );
      expect(await readFile(join(projectDir, '.gitignore'), 'utf-8')).toBe(
        'node_modules\n.env\n',
      );
    } finally {
      await rm(projectDir, { recursive: true, force: true });
    }
  });

  it.each([
    ['https://canvas.ddev.site', 'http://canvas.ddev.site'],
    ['https://sub.canvas.ddev.site/path', 'http://sub.canvas.ddev.site/path'],
    ['https://ddev.site', 'http://ddev.site'],
  ])('writes %s as plain HTTP for DDEV sites', async (siteUrl, written) => {
    const projectDir = await createProjectDir();

    try {
      await writeSiteUrlEnv(projectDir, siteUrl);

      expect(await readFile(join(projectDir, '.env'), 'utf-8')).toBe(
        `CANVAS_SITE_URL="${written}"\n`,
      );
    } finally {
      await rm(projectDir, { recursive: true, force: true });
    }
  });

  it('keeps HTTPS for hosts that merely end in ddev.site', async () => {
    const projectDir = await createProjectDir();

    try {
      await writeSiteUrlEnv(projectDir, 'https://myddev.site');

      expect(await readFile(join(projectDir, '.env'), 'utf-8')).toBe(
        'CANVAS_SITE_URL="https://myddev.site"\n',
      );
    } finally {
      await rm(projectDir, { recursive: true, force: true });
    }
  });

  it('copies .env.example and replaces the Canvas site URL', async () => {
    const projectDir = await createProjectDir();
    const example =
      '# Drupal connection\r\n' +
      'export CANVAS_SITE_URL = http://example.com\r\n' +
      'API_TOKEN=\r\n';

    try {
      await writeFile(join(projectDir, '.env.example'), example);

      await expect(
        writeSiteUrlEnv(projectDir, 'https://canvas.example.com'),
      ).resolves.toBe('copied-example');

      expect(await readFile(join(projectDir, '.env'), 'utf-8')).toBe(
        '# Drupal connection\r\n' +
          'export CANVAS_SITE_URL = "https://canvas.example.com"\r\n' +
          'API_TOKEN=\r\n',
      );
      expect(await readFile(join(projectDir, '.env.example'), 'utf-8')).toBe(
        example,
      );
    } finally {
      await rm(projectDir, { recursive: true, force: true });
    }
  });

  it('appends the Canvas site URL when .env.example does not define it', async () => {
    const projectDir = await createProjectDir();

    try {
      await writeFile(
        join(projectDir, '.env.example'),
        '# CANVAS_SITE_URL=http://example.com\nAPI_TOKEN=\n',
      );

      await writeSiteUrlEnv(projectDir, 'https://canvas.example.com');

      expect(await readFile(join(projectDir, '.env'), 'utf-8')).toBe(
        '# CANVAS_SITE_URL=http://example.com\n' +
          'API_TOKEN=\n' +
          'CANVAS_SITE_URL="https://canvas.example.com"\n',
      );
    } finally {
      await rm(projectDir, { recursive: true, force: true });
    }
  });

  it('rejects duplicate Canvas site URL assignments in .env.example', async () => {
    const projectDir = await createProjectDir();

    try {
      await writeFile(
        join(projectDir, '.env.example'),
        'CANVAS_SITE_URL=http://one.example.com\n' +
          'CANVAS_SITE_URL=http://two.example.com\n',
      );

      await expect(
        writeSiteUrlEnv(projectDir, 'https://canvas.example.com'),
      ).rejects.toThrow(
        '.env.example contains more than one CANVAS_SITE_URL assignment',
      );
      await expect(lstat(join(projectDir, '.env'))).rejects.toMatchObject({
        code: 'ENOENT',
      });
    } finally {
      await rm(projectDir, { recursive: true, force: true });
    }
  });

  it('does not duplicate an existing ignore rule', async () => {
    const projectDir = await createProjectDir();

    try {
      await writeFile(join(projectDir, '.gitignore'), '.env\n');

      await writeSiteUrlEnv(projectDir, 'https://canvas.example.com');

      expect(await readFile(join(projectDir, '.gitignore'), 'utf-8')).toBe(
        '.env\n',
      );
    } finally {
      await rm(projectDir, { recursive: true, force: true });
    }
  });

  it('creates .gitignore when it does not exist', async () => {
    const projectDir = await createProjectDir();

    try {
      await writeSiteUrlEnv(projectDir, 'https://canvas.example.com');

      expect(await readFile(join(projectDir, '.gitignore'), 'utf-8')).toBe(
        '.env\n',
      );
    } finally {
      await rm(projectDir, { recursive: true, force: true });
    }
  });

  it('adds the Canvas site URL to an existing environment file', async () => {
    const projectDir = await createProjectDir();

    try {
      await writeFile(join(projectDir, '.env'), 'EXISTING=true\n');

      await expect(
        writeSiteUrlEnv(projectDir, 'https://canvas.example.com'),
      ).resolves.toBe('configured-existing');
      expect(await readFile(join(projectDir, '.env'), 'utf-8')).toBe(
        'EXISTING=true\nCANVAS_SITE_URL="https://canvas.example.com"\n',
      );
      expect(await readFile(join(projectDir, '.gitignore'), 'utf-8')).toBe(
        '.env\n',
      );
    } finally {
      await rm(projectDir, { recursive: true, force: true });
    }
  });

  it('fills an empty Canvas site URL in an existing environment file', async () => {
    const projectDir = await createProjectDir();

    try {
      await writeFile(
        join(projectDir, '.env'),
        'CANVAS_SITE_URL=\nEXISTING=true\n',
      );

      await expect(
        writeSiteUrlEnv(projectDir, 'https://canvas.example.com'),
      ).resolves.toBe('configured-existing');
      expect(await readFile(join(projectDir, '.env'), 'utf-8')).toBe(
        'CANVAS_SITE_URL="https://canvas.example.com"\nEXISTING=true\n',
      );
    } finally {
      await rm(projectDir, { recursive: true, force: true });
    }
  });

  it('keeps a Canvas site URL already configured in .env', async () => {
    const projectDir = await createProjectDir();
    const existingEnv =
      'CANVAS_SITE_URL="https://existing.example.com"\nEXISTING=true\n';

    try {
      await writeFile(join(projectDir, '.env'), existingEnv);

      await expect(
        writeSiteUrlEnv(projectDir, 'https://canvas.example.com'),
      ).resolves.toBe('existing-site-url');
      expect(await readFile(join(projectDir, '.env'), 'utf-8')).toBe(
        existingEnv,
      );
      expect(await readFile(join(projectDir, '.gitignore'), 'utf-8')).toBe(
        '.env\n',
      );
    } finally {
      await rm(projectDir, { recursive: true, force: true });
    }
  });
});
