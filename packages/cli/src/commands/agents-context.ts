import * as p from '@clack/prompts';

import { createApiService, ensureAuthConfig } from '../services/api';
import {
  formatAgentsContextProviders,
  pullAgentsContext,
} from '../utils/agents-context';
import { updateConfigFromOptions } from '../utils/command-helpers';
import { printCommandIntro } from '../utils/command-intro';
import {
  COMMAND_RESULT_REPORT_OPTIONS,
  reportResults,
} from '../utils/report-results';

import type { Command } from 'commander';

interface AgentsContextOptions {
  all?: boolean;
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
  const command = program
    .command('agents-context')
    .description('pull context for local AI agents from the Drupal site')
    .option('--client-id <id>', 'Client ID')
    .option('--client-secret <secret>', 'Client Secret')
    .option('--site-url <url>', 'Site URL')
    .option('--scope <scope>', 'Scope')
    .option('--all', 'Execute all default context providers')
    .argument('[provider]', 'Context provider to execute')
    .argument('[providerArgs...]', 'Arguments passed to the selected provider')
    .addHelpText(
      'after',
      ({ command }) => `\n${formatAgentsContextProviders(command)}`,
    )
    .action(
      async (
        provider: string | undefined,
        providerArgs: string[],
        options: AgentsContextOptions,
      ) => {
        try {
          if (!provider && !options.all) {
            command.outputHelp();
            return;
          }
          if (provider && options.all) {
            throw new Error(
              'Cannot use --all with a provider. Use either --all or a provider key.',
            );
          }

          const configOptions = { ...options };
          delete configOptions.all;
          updateConfigFromOptions(configOptions);

          await ensureAuthConfig();

          const apiService = await createApiService();
          const projectRoot = process.cwd();

          await pullAgentsContext(apiService, projectRoot, {
            provider,
            providerArgs,
            runAll: options.all === true,
          });
        } catch (error) {
          printCommandIntro('agents-context');
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
      },
    );
}
