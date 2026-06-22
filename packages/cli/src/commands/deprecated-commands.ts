import chalk from 'chalk';

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
      console.error(
        chalk.yellow(
          `The \`canvas ${commandName}\` command has been removed. Use \`canvas ${replacement}\` instead.`,
        ),
      );
      process.exitCode = 1;
    });
}

export function deprecatedDownloadUploadCommands(program: Command): void {
  registerDeprecatedCommand(program, 'download');
  registerDeprecatedCommand(program, 'upload');
}
