#!/usr/bin/env node
import chalk from 'chalk';
import { Command } from 'commander';

import packageJson from '../package.json';
import { buildCommand } from './commands/build';
import { buildDeprecatedCommand } from './commands/build-deprecated';
import { downloadCommand } from './commands/download-deprecated';
import { pullCommand } from './commands/pull';
import { pushCommand } from './commands/push';
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
downloadCommand(program);
pullCommand(program);
pushCommand(program);
scaffoldCommand(program);
uploadCommand(program);
buildDeprecatedCommand(program);
validateCommand(program);
buildCommand(program);

program.hook('preAction', async (command) => {
  const commandOptions = command.opts?.() as { yes?: boolean };
  await handleLegacyComponentDirMigration({
    skipPrompt: Boolean(commandOptions?.yes),
  });
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
