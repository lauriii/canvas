import chalk from 'chalk';
import * as p from '@clack/prompts';

import { createApiService, ensureAuthConfig } from '../services/api';
import { AGENTS_CONTEXT_DIR, pullAgentsContext } from '../utils/agents-context';
import { updateConfigFromOptions } from '../utils/command-helpers';

import type { Command } from 'commander';

interface AgentsContextOptions {
  clientId?: string;
  clientSecret?: string;
  siteUrl?: string;
  scope?: string;
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
      p.intro(chalk.bold('Drupal Canvas CLI: agents-context'));

      try {
        updateConfigFromOptions(options);

        await ensureAuthConfig();

        const apiService = await createApiService();
        const projectRoot = process.cwd();

        const s = p.spinner();
        s.start('Pulling agents context');

        await pullAgentsContext(apiService, projectRoot);

        s.stop(chalk.green(`Saved agents context to ${AGENTS_CONTEXT_DIR}`));

        p.outro(chalk.green('Done'));
      } catch (error) {
        if (error instanceof Error) {
          p.log.error(chalk.red(`Error: ${error.message}`));
        } else {
          p.log.error(chalk.red(`Unknown error: ${String(error)}`));
        }
        process.exit(1);
      }
    });
}
