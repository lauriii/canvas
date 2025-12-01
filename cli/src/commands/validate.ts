import chalk from 'chalk';
import * as p from '@clack/prompts';

import { setConfig } from '../config.js';
import { reportResults } from '../utils/report-results.js';
import { selectLocalComponents } from '../utils/select-local-components.js';
import { validateComponent } from '../utils/validate.js';

import type { Command } from 'commander';

interface ValidateOptions {
  dir?: string;
  all?: boolean;
  verbose?: boolean;
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
    .option('--all', 'Validate all components')
    .option('--verbose', 'Enable verbose output')
    .option(
      '--fix',
      'Apply available automatic fixes for linting issues',
      false,
    )
    .action(async (options: ValidateOptions) => {
      p.intro(chalk.bold('Drupal Canvas CLI: validate'));

      const allFlag = options.all || false;

      if (options.dir) setConfig({ componentDir: options.dir });
      if (options.verbose) setConfig({ verbose: true });

      // Select components to validate
      const selectedComponents = await selectLocalComponents(
        allFlag,
        'Select components to validate',
      );

      if (!selectedComponents || selectedComponents.length === 0) {
        return;
      }

      const results = [];
      for (const componentDir of selectedComponents) {
        results.push(await validateComponent(componentDir, options.fix));
      }

      reportResults(results, 'Validation results');

      const hasErrors = results.some((r) => !r.success);
      if (hasErrors) {
        p.outro(`❌ Validation completed with errors`);
        process.exit(1);
      }

      p.outro(`✅ Validation completed`);
    });
}
