#!/usr/bin/env node
import chalk from 'chalk';
import { Command } from 'commander';

import packageJson from '../package.json';
import { agentsContextCommand } from './commands/agents-context';
import { applyCommand } from './commands/apply';
import { buildCommand } from './commands/build';
import { deprecatedDownloadUploadCommands } from './commands/deprecated-commands';
import {
  changesetCommand,
  fleetCommand,
  libraryCommand,
} from './commands/fleet';
import { loginCommand, logoutCommand } from './commands/login';
import { pullCommand } from './commands/pull';
import { pushCommand } from './commands/push';
import { reconcileMediaCommand } from './commands/reconcile-media';
import { scaffoldCommand } from './commands/scaffold';
import { validateCommand } from './commands/validate';
import {
  emitCanvasConfigWarnings,
  handleLegacyComponentDirMigration,
  handleLegacySyncEnvMigration,
} from './config';

const version = (packageJson as { version?: string }).version;

const program = new Command();
program
  .name('canvas')
  .description('CLI tool for managing Drupal Canvas code components')
  .version(version ?? '0.0.0');

// Register commands
loginCommand(program);
logoutCommand(program);
pullCommand(program);
agentsContextCommand(program);
pushCommand(program);
reconcileMediaCommand(program);
scaffoldCommand(program);
validateCommand(program);
buildCommand(program);
// Fleet management. Additive: `push` and `pull` are unchanged and keep working
// against a single site with no fleet files present.
libraryCommand(program);
fleetCommand(program);
applyCommand(program);
changesetCommand(program);
deprecatedDownloadUploadCommands(program);

program.hook('preAction', async (command, actionCommand) => {
  // Skip canvas.config.json migration for commands that do not use a component
  // directory and have no need for legacy config migration.
  if (
    ['login', 'logout', 'download', 'upload'].includes(actionCommand.name()) ||
    // `fleet` and `changeset` subcommands operate on the inventory and captured
    // state, never on a component directory.
    ['fleet', 'changeset'].includes(actionCommand.parent?.name() ?? '')
  ) {
    return;
  }
  const commandOptions = command.opts?.() as { yes?: boolean };
  const actionOptions = actionCommand.opts?.() as {
    dir?: string;
    yes?: boolean;
  };
  const migrationOptions = {
    skipPrompt: Boolean(actionOptions?.yes ?? commandOptions?.yes),
  };
  let emittedPreActionOutput =
    await handleLegacySyncEnvMigration(migrationOptions);
  if (!actionOptions?.dir) {
    emittedPreActionOutput =
      (await handleLegacyComponentDirMigration(migrationOptions)) ||
      emittedPreActionOutput;
  }
  emittedPreActionOutput = emitCanvasConfigWarnings() || emittedPreActionOutput;
  if (emittedPreActionOutput) {
    console.log();
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
