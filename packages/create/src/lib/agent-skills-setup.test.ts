import {
  lstat,
  mkdir,
  mkdtemp,
  realpath,
  rm,
  writeFile,
} from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

import { setupAgentSkills } from './agent-skills-setup.js';

async function createProjectDir(prefix: string): Promise<string> {
  return mkdtemp(join(tmpdir(), prefix));
}

async function createCanonicalSkill(
  projectDir: string,
  skillName: string,
): Promise<void> {
  const skillDir = join(projectDir, '.agents', 'skills', skillName);
  await mkdir(skillDir, { recursive: true });
  await writeFile(join(skillDir, 'SKILL.md'), `# ${skillName}\n`, 'utf-8');
}

describe('setupAgentSkills', () => {
  it('no-op when .agents/skills is missing', async () => {
    const projectDir = await createProjectDir('create-agent-skills-');

    try {
      await setupAgentSkills(projectDir, {
        promptForAgents: async () => ['claude-code'],
        onInfo: () => {},
        onWarning: () => {},
      });

      expect(await pathExists(join(projectDir, '.claude', 'skills'))).toBe(
        false,
      );
    } finally {
      await rm(projectDir, { recursive: true, force: true });
    }
  });

  it('creates symlinks for non-universal agents', async () => {
    const projectDir = await createProjectDir('create-agent-skills-');
    const infos: string[] = [];

    try {
      await createCanonicalSkill(projectDir, 'test-skill');

      await setupAgentSkills(projectDir, {
        promptForAgents: async () => ['claude-code'],
        onInfo: (message) => {
          infos.push(message);
        },
        onWarning: () => {},
      });

      const sourcePath = join(projectDir, '.agents', 'skills', 'test-skill');
      const linkPath = join(projectDir, '.claude', 'skills', 'test-skill');

      expect(await pathExists(linkPath)).toBe(true);
      expect(await realpath(linkPath)).toBe(await realpath(sourcePath));
      expect(
        infos.includes('Created compatibility symlink (1 skill × 1 agent).'),
      ).toBe(true);
    } finally {
      await rm(projectDir, { recursive: true, force: true });
    }
  });

  it('does not create symlinks for universal-only selection', async () => {
    const projectDir = await createProjectDir('create-agent-skills-');

    try {
      await createCanonicalSkill(projectDir, 'test-skill');

      await setupAgentSkills(projectDir, {
        promptForAgents: async () => ['codex'],
        onInfo: () => {},
        onWarning: () => {},
      });

      expect(
        await pathExists(join(projectDir, '.claude', 'skills', 'test-skill')),
      ).toBe(false);
    } finally {
      await rm(projectDir, { recursive: true, force: true });
    }
  });

  it('skips existing destination paths and warns', async () => {
    const projectDir = await createProjectDir('create-agent-skills-');
    const warnings: string[] = [];

    try {
      await createCanonicalSkill(projectDir, 'test-skill');

      const existingPath = join(projectDir, '.claude', 'skills', 'test-skill');
      await mkdir(existingPath, { recursive: true });

      await setupAgentSkills(projectDir, {
        promptForAgents: async () => ['claude-code'],
        onInfo: () => {},
        onWarning: (message) => {
          warnings.push(message);
        },
      });

      const stats = await lstat(existingPath);
      expect(stats.isDirectory()).toBe(true);
      expect(stats.isSymbolicLink()).toBe(false);
      expect(
        warnings.some((message) => message.includes('Skipped 1 existing path')),
      ).toBe(true);
    } finally {
      await rm(projectDir, { recursive: true, force: true });
    }
  });

  it('creates symlinks for mixed selection only on non-universal agents', async () => {
    const projectDir = await createProjectDir('create-agent-skills-');
    const infos: string[] = [];

    try {
      await createCanonicalSkill(projectDir, 'test-skill');
      await createCanonicalSkill(projectDir, 'test-skill-two');

      await setupAgentSkills(projectDir, {
        promptForAgents: async () => ['codex', 'claude-code', 'cursor'],
        onInfo: (message) => {
          infos.push(message);
        },
        onWarning: () => {},
      });

      const sourcePath = join(projectDir, '.agents', 'skills', 'test-skill');
      const claudePath = join(projectDir, '.claude', 'skills', 'test-skill');
      const cursorPath = join(projectDir, '.cursor', 'skills', 'test-skill');

      expect(await realpath(claudePath)).toBe(await realpath(sourcePath));
      expect(await realpath(cursorPath)).toBe(await realpath(sourcePath));
      expect(
        infos.includes('Created compatibility symlinks (2 skills × 2 agents).'),
      ).toBe(true);
    } finally {
      await rm(projectDir, { recursive: true, force: true });
    }
  });

  it('skips setup when prompt is canceled', async () => {
    const projectDir = await createProjectDir('create-agent-skills-');

    try {
      await createCanonicalSkill(projectDir, 'test-skill');

      await setupAgentSkills(projectDir, {
        promptForAgents: async () => Symbol('cancel'),
        onInfo: () => {},
        onWarning: () => {},
      });

      expect(
        await pathExists(join(projectDir, '.claude', 'skills', 'test-skill')),
      ).toBe(false);
    } finally {
      await rm(projectDir, { recursive: true, force: true });
    }
  });
});

async function pathExists(path: string): Promise<boolean> {
  try {
    await realpath(path);
    return true;
  } catch {
    return false;
  }
}
