import { access, lstat, mkdir, readdir, symlink } from 'node:fs/promises';
import { platform } from 'node:os';
import { dirname, join, relative } from 'node:path';
import * as p from '@clack/prompts';

import {
  agents,
  getNonUniversalAgents,
  getUniversalAgents,
  isUniversalAgent,
} from './agents.js';
import {
  cancelSymbol,
  searchMultiselect,
} from './prompts/search-multiselect.js';
import { pluralize } from './text.js';

import type { AgentType } from './agents.js';

type SetupAgentSkillsOptions = {
  promptForAgents?: () => Promise<AgentType[] | symbol>;
  onInfo?: (message: string) => void;
  onWarning?: (message: string) => void;
};

type SymlinkResult = {
  created: Array<{ agent: AgentType; skill: string; linkPath: string }>;
  skipped: Array<{ agent: AgentType; skill: string; linkPath: string }>;
  failed: Array<{
    agent: AgentType;
    skill: string;
    linkPath: string;
    reason: string;
  }>;
};

const DEFAULT_SKILLS_DIR = '.agents/skills';

export async function setupAgentSkills(
  projectDir: string,
  options: SetupAgentSkillsOptions = {},
): Promise<void> {
  const info = options.onInfo ?? p.log.info;
  const warning = options.onWarning ?? p.log.warn;

  try {
    const canonicalSkillsDir = join(projectDir, DEFAULT_SKILLS_DIR);

    const hasSkillsDir = await pathExists(canonicalSkillsDir);
    if (!hasSkillsDir) {
      return;
    }

    const skillNames = await discoverSkillNames(canonicalSkillsDir);
    if (skillNames.length === 0) {
      info(
        'No skills found in .agents/skills. Skipping agent compatibility setup.',
      );
      return;
    }

    const promptForAgents = options.promptForAgents ?? defaultPromptForAgents;
    const selected = await promptForAgents();

    if (p.isCancel(selected) || typeof selected === 'symbol') {
      info('Skipped agent compatibility setup.');
      return;
    }

    if (selected.length === 0) {
      info('No agents selected. Skipping agent compatibility setup.');
      return;
    }

    const nonUniversalAgents = selected.filter(
      (agent) => !isUniversalAgent(agent),
    );
    if (nonUniversalAgents.length === 0) {
      info('Selected agents already use .agents/skills. No symlinks needed.');
      return;
    }

    const results = await createAgentSkillSymlinks({
      projectDir,
      canonicalSkillsDir,
      skillNames,
      selectedAgents: nonUniversalAgents,
    });

    const selectedAgentNames = selected
      .map((agent) => agents[agent].displayName)
      .join(', ');
    info(`Agent skill support selected: ${selectedAgentNames}.`);

    if (results.created.length > 0) {
      info(
        `Created compatibility ${pluralize('symlink', results.created.length)} (${skillNames.length} ${pluralize('skill', skillNames.length)} × ${nonUniversalAgents.length} ${pluralize('agent', nonUniversalAgents.length)}).`,
      );
    }

    if (results.skipped.length > 0) {
      warning(
        `Skipped ${results.skipped.length} existing ${pluralize('path', results.skipped.length)}: ${results.skipped
          .map((entry) => entry.linkPath)
          .join(', ')}`,
      );
    }

    if (results.failed.length > 0) {
      warning(
        `Failed to create ${results.failed.length} ${pluralize('symlink', results.failed.length)}: ${results.failed
          .map((entry) => `${entry.linkPath} (${entry.reason})`)
          .join(', ')}`,
      );
    }
  } catch (error) {
    const warning = options.onWarning ?? p.log.warn;
    const message =
      error instanceof Error
        ? error.message
        : 'Unknown error during agent compatibility setup';
    warning(`Agent compatibility setup skipped: ${message}`);
  }
}

export async function defaultPromptForAgents(): Promise<AgentType[] | symbol> {
  const universalAgents = getUniversalAgents();
  const otherAgents = getNonUniversalAgents();

  const selected = await searchMultiselect({
    message: 'Which coding agents should this codebase support?',
    items: otherAgents.map((agent) => ({
      value: agent,
      label: agents[agent].displayName,
      hint: agents[agent].skillsDir,
    })),
    lockedSection: {
      title: 'Universal (.agents/skills)',
      items: universalAgents.map((agent) => ({
        value: agent,
        label: agents[agent].displayName,
      })),
    },
  });

  if (selected === cancelSymbol) {
    return selected;
  }

  return selected as AgentType[];
}

export async function discoverSkillNames(
  canonicalSkillsDir: string,
): Promise<string[]> {
  const entries = await readdir(canonicalSkillsDir, { withFileTypes: true });

  return entries
    .filter((entry) => entry.isDirectory())
    .map((entry) => entry.name)
    .sort((a, b) => a.localeCompare(b));
}

async function createAgentSkillSymlinks(options: {
  projectDir: string;
  canonicalSkillsDir: string;
  skillNames: string[];
  selectedAgents: AgentType[];
}): Promise<SymlinkResult> {
  const created: SymlinkResult['created'] = [];
  const skipped: SymlinkResult['skipped'] = [];
  const failed: SymlinkResult['failed'] = [];

  for (const agent of options.selectedAgents) {
    const agentSkillsDir = join(options.projectDir, agents[agent].skillsDir);

    for (const skill of options.skillNames) {
      const targetPath = join(options.canonicalSkillsDir, skill);
      const linkPath = join(agentSkillsDir, skill);

      await mkdir(dirname(linkPath), { recursive: true });

      if (await pathExists(linkPath)) {
        skipped.push({ agent, skill, linkPath });
        continue;
      }

      try {
        const relativeTarget = relative(dirname(linkPath), targetPath);
        const symlinkType = platform() === 'win32' ? 'junction' : undefined;
        await symlink(relativeTarget, linkPath, symlinkType);
        created.push({ agent, skill, linkPath });
      } catch (error) {
        failed.push({
          agent,
          skill,
          linkPath,
          reason: error instanceof Error ? error.message : 'Unknown error',
        });
      }
    }
  }

  return { created, skipped, failed };
}

async function pathExists(path: string): Promise<boolean> {
  try {
    await access(path);
    return true;
  } catch {
    return false;
  }
}

export async function isSymlink(path: string): Promise<boolean> {
  try {
    const stats = await lstat(path);
    return stats.isSymbolicLink();
  } catch {
    return false;
  }
}
