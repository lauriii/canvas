#!/usr/bin/env node

import { Command } from 'commander';
import chalk from 'chalk';
import { downloadCommand } from './commands/download';
import { scaffoldCommand } from './commands/scaffold';
import { uploadCommand } from './commands/upload';
import { buildCommand } from './commands/build';
import packageJson from '../package.json';
const version = packageJson.version;

const program = new Command();
program
  .name('xb')
  .description(
    'CLI tool for managing Drupal Experience Builder code components',
  )
  .version(version ?? '0.0.0');

// Register commands
downloadCommand(program);
scaffoldCommand(program);
uploadCommand(program);
buildCommand(program);

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
