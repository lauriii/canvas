import * as p from '@clack/prompts';

import { parseAgentSelection } from './lib/agent-selection.js';
import { setupAgentSkills } from './lib/agent-skills-setup.js';
import { printCommandIntro } from './lib/command-intro.js';

import type { Command } from 'commander';

export function agentsCommand(program: Command): void {
  program
    .command('agents')
    .description(
      'set up compatibility symlinks for additional agent skills directories',
    )
    .argument(
      '[agents]',
      'comma-separated list of additional agents to support, or "none" to skip compatibility symlinks',
    )
    .action(async (agentsArg: string | undefined) => {
      printCommandIntro('create agents');

      try {
        const selectedAgents = parseAgentSelection(agentsArg, 'agents');
        const projectDir = process.cwd();
        await setupAgentSkills(projectDir, {
          selectedAgents,
          interactive: Boolean(process.stdin.isTTY && process.stdout.isTTY),
        });

        p.outro('Agent compatibility setup complete.');
      } catch (error) {
        if (error instanceof Error) {
          p.log.error(`Error: ${error.message}`);
        } else {
          p.log.error(`Unknown error: ${String(error)}`);
        }
        process.exit(1);
      }
    });
}
