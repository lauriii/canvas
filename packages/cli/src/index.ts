#!/usr/bin/env node
import chalk from 'chalk';
import { Command } from 'commander';

import packageJson from '../package.json';
import { buildCommand } from './commands/build';
import { buildDeprecatedCommand } from './commands/build-deprecated';
import { downloadCommand } from './commands/download-deprecated';
import { loginCommand, logoutCommand } from './commands/login';
import { pullCommand } from './commands/pull';
import { pushCommand } from './commands/push';
import { reconcileMediaCommand } from './commands/reconcile-media';
import { scaffoldCommand } from './commands/scaffold';
import { uploadCommand } from './commands/upload-deprecated';
import { validateCommand } from './commands/validate';
import { handleLegacyComponentDirMigration } from './config';

const version = (packageJson as { version?: string }).version;

const program = new Command();
program
  .name('canvas')
  .description('CLI tool for managing Drupal Canvas code components')
  .version(version ?? '0.0.0');

// Register commands
loginCommand(program);
logoutCommand(program);
downloadCommand(program);
pullCommand(program);
pushCommand(program);
reconcileMediaCommand(program);
scaffoldCommand(program);
uploadCommand(program);
buildDeprecatedCommand(program);
validateCommand(program);
buildCommand(program);

program.hook('preAction', async (command, actionCommand) => {
  // Skip canvas.config.json migration for login/logout — they don't
  // use a component directory and have no need for legacy config migration.
  if (['login', 'logout'].includes(actionCommand.name())) {
    return;
  }
  const commandOptions = command.opts?.() as { yes?: boolean };
  const actionOptions = actionCommand.opts?.() as { dir?: string };
  if (!actionOptions?.dir) {
    await handleLegacyComponentDirMigration({
      skipPrompt: Boolean(commandOptions?.yes),
    });
  }
});

// Handle errors
program.showHelpAfterError();
program.showSuggestionAfterError(true);

try {
  // Parse command line arguments and execute the command
  await program.parseAsync(process.argv);
} catch (error) {
  if (error instanceof Error) {
    console.error(chalk.red(`Error: ${error.message}`));
    process.exit(1);
  }
}
