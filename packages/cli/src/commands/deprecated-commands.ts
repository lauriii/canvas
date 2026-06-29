import * as p from '@clack/prompts';

import { printCommandIntro } from '../utils/command-intro';

import type { Command } from 'commander';

const replacements = {
  download: 'pull',
  upload: 'push',
} as const;

type DeprecatedCommandName = keyof typeof replacements;

function registerDeprecatedCommand(
  program: Command,
  commandName: DeprecatedCommandName,
): void {
  const replacement = replacements[commandName];

  program
    .command(commandName, { hidden: true })
    .description(`removed command; use ${replacement} instead`)
    .allowUnknownOption()
    .allowExcessArguments()
    .action(() => {
      printCommandIntro(commandName);
      p.log.warn(
        `The \`canvas ${commandName}\` command has been removed. Use \`canvas ${replacement}\` instead.`,
      );
      p.outro('Command removed');
      process.exitCode = 1;
    });
}

export function deprecatedDownloadUploadCommands(program: Command): void {
  registerDeprecatedCommand(program, 'download');
  registerDeprecatedCommand(program, 'upload');
}
