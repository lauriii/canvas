import chalk from 'chalk';
import * as p from '@clack/prompts';

import { createApiService, ensureAuthConfig } from '../services/api';
import { AGENTS_CONTEXT_DIR, pullAgentsContext } from '../utils/agents-context';
import { updateConfigFromOptions } from '../utils/command-helpers';
import { printCommandIntro } from '../utils/command-intro';
import {
  COMMAND_RESULT_REPORT_OPTIONS,
  reportResults,
} from '../utils/report-results';

import type { Command } from 'commander';

interface AgentsContextOptions {
  clientId?: string;
  clientSecret?: string;
  siteUrl?: string;
  scope?: string;
}

function formatErrorMessage(error: unknown): string {
  return error instanceof Error
    ? error.message
    : `Unknown error: ${String(error)}`;
}

export function agentsContextCommand(program: Command): void {
  program
    .command('agents-context')
    .description('pull context for local AI agents from the Drupal site')
    .option('--client-id <id>', 'Client ID')
    .option('--client-secret <secret>', 'Client Secret')
    .option('--site-url <url>', 'Site URL')
    .option('--scope <scope>', 'Scope')
    .action(async (options: AgentsContextOptions) => {
      printCommandIntro('agents-context');
      const s = p.spinner();
      let pullStarted = false;

      try {
        updateConfigFromOptions(options);

        await ensureAuthConfig();

        const apiService = await createApiService();
        const projectRoot = process.cwd();

        s.start('Pulling agents context');
        pullStarted = true;

        await pullAgentsContext(apiService, projectRoot);

        s.stop(`Saved agents context to ${AGENTS_CONTEXT_DIR}`, 0);

        p.outro(chalk.green('Agents context pulled'));
      } catch (error) {
        if (pullStarted) {
          s.stop('Agents context pull failed', 2);
        }
        reportResults(
          [
            {
              itemName: 'Agents context pull failed',
              success: false,
              details: [{ content: formatErrorMessage(error) }],
            },
          ],
          'Agents context pull failed',
          'Item',
          COMMAND_RESULT_REPORT_OPTIONS,
        );
        p.outro('Agents context pull failed');
        process.exitCode = 1;
      }
    });
}
