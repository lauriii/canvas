import chalk from 'chalk';
import * as p from '@clack/prompts';

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
    .action(async (options: ValidateOptions) => {
      try {
        p.intro(chalk.bold('Drupal Canvas CLI: validate'));

        // Validate options
        validateComponentOptions(options);

        // Default to --all when --yes is used without --components
        const allFlag =
          options.all || (options.yes && !options.components) || false;

        // Update config with CLI options
        updateConfigFromOptions(options);

        // Select components to validate
        const { directories: componentsToValidate } =
          await selectLocalComponents({
            all: allFlag,
            components: options.components,
            skipConfirmation: options.yes,
            selectMessage: 'Select components to validate',
          });

        const componentPluralized = pluralizeComponent(
          componentsToValidate.length,
        );

        const s = p.spinner();
        s.start(`Validating ${componentPluralized}`);

        const results = [];
        for (const componentDir of componentsToValidate) {
          results.push(await validateComponent(componentDir, options.fix));
        }

        s.stop(
          chalk.green(
            `Processed ${componentsToValidate.length} ${componentPluralized}`,
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
