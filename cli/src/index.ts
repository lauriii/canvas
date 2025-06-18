#!/usr/bin/env node

import { Command } from 'commander';
import chalk from 'chalk';
import { downloadCommand } from './commands/download';
import { scaffoldCommand } from './commands/scaffold';
import { uploadCommand } from './commands/upload';
import { buildCommand } from './commands/build';

const program = new Command();
program
  .name('xb')
  .description('Experience Builder CLI for component management')
  .version('0.0.0')
  .addHelpText(
    'after',
    `
Environment Variables:
  EXPERIENCE_BUILDER_SITE_URL     The URL of your Drupal site
  EXPERIENCE_BUILDER_AUTH_TOKEN   Your authentication token
  EXPERIENCE_BUILDER_FRAMEWORK    Component framework (react)
  EXPERIENCE_BUILDER_COMPONENT_DIR Default directory for components
  EXPERIENCE_BUILDER_VERBOSE      Enable verbose output (true/false)

Environment variables can be set in:
  - .env file in your current working directory
  - .xbrc file in your home directory
  - Command line arguments (take precedence over environment variables)
`,
  );

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
