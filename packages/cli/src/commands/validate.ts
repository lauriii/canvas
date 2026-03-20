import chalk from 'chalk';
import * as p from '@clack/prompts';
import { discoverCanvasProject } from '@drupal-canvas/discovery';

import { getConfig } from '../config.js';
import {
  pluralizeComponent,
  updateConfigFromOptions,
  validateComponentOptions,
} from '../utils/command-helpers';
import { selectLocalComponents } from '../utils/component-selector.js';
import { reportResults } from '../utils/report-results.js';
import { validateComponent } from '../utils/validate.js';

import type { Command } from 'commander';

interface ValidateOptions {
  dir?: string;
  all?: boolean;
  components?: string;
  yes?: boolean;
  fix?: boolean;
  deprecated?: boolean;
}

/**
 * Command for validating local components.
 */
export function validateCommand(program: Command): void {
  program
    .command('validate')
    .description('validate local components')
    .option(
      '-d, --dir <directory>',
      'Component directory to validate the components in',
    )
    .option(
      '-c, --components <names>',
      'Specific component(s) to validate (comma-separated)',
    )
    .option('--all', 'Validate all components')
    .option('-y, --yes', 'Skip confirmation prompts')
    .option(
      '--fix',
      'Apply available automatic fixes for linting issues',
      false,
    )
    .option(
      '--deprecated',
      'Validate using deprecated rules and component selection.',
      false,
    )
    .action(async (options: ValidateOptions) => {
      try {
        p.intro(chalk.bold('Drupal Canvas CLI: validate'));

        // Update config with CLI options
        updateConfigFromOptions(options);

        let componentDirectoriesToValidate: string[] = [];

        if (options.deprecated) {
          // Validate options
          validateComponentOptions(options);

          // Default to --all when --yes is used without --components
          const allFlag =
            options.all || (options.yes && !options.components) || false;

          // Select components to validate
          const { directories } = await selectLocalComponents({
            all: allFlag,
            components: options.components,
            skipConfirmation: options.yes,
            selectMessage: 'Select components to validate',
          });
          componentDirectoriesToValidate = directories;
        } else {
          if (options.all) {
            p.log.warn(
              '--all option is deprecated and ignored unless used with the --deprecated flag.',
            );
          }
          if (options.components) {
            p.log.warn(
              '--components option is deprecated and ignored unless used with the --deprecated flag.',
            );
          }

          const config = getConfig();
          const discoveryResult = await discoverCanvasProject({
            componentRoot: config.componentDir,
            projectRoot: process.cwd(),
          });
          componentDirectoriesToValidate = discoveryResult.components.map(
            (c) => c.directory,
          );
        }

        const componentPluralized = pluralizeComponent(
          componentDirectoriesToValidate.length,
        );

        const s = p.spinner();
        s.start(`Validating ${componentPluralized}`);

        const results = [];
        for (const componentDir of componentDirectoriesToValidate) {
          results.push(
            await validateComponent(
              componentDir,
              options.fix,
              options.deprecated,
            ),
          );
        }

        s.stop(
          chalk.green(
            `Processed ${componentDirectoriesToValidate.length} ${componentPluralized}`,
          ),
        );

        reportResults(results, 'Validation results', 'Component');

        const hasErrors = results.some((r) => !r.success);
        if (hasErrors) {
          p.outro(`❌ Validation completed with errors`);
          process.exit(1);
        }

        p.outro(`✅ Validation completed`);
      } catch (error) {
        if (error instanceof Error) {
          p.note(chalk.red(`Error: ${error.message}`));
        } else {
          p.note(chalk.red(`Unknown error: ${String(error)}`));
        }
        process.exit(1);
      }
    });
}
