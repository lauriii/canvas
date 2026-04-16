// This command is deprecated and will eventually be removed. Use the new build command instead.
import chalk from 'chalk';
import * as p from '@clack/prompts';

import { ensureAuthConfig } from '../services/api.js';
import { buildComponent } from '../utils/build-deprecated';
import { buildTailwindForComponents } from '../utils/build-tailwind-deprecated';
import {
  pluralizeComponent,
  updateConfigFromOptions,
  validateComponentOptions,
} from '../utils/command-helpers';
import { selectLocalComponents } from '../utils/component-selector.js';
import { reportResults } from '../utils/report-results';

import type { Command } from 'commander';
import type { Result } from '../types/Result.js';

interface BuildOptions {
  dir?: string;
  all?: boolean;
  components?: string;
  tailwind?: boolean;
  clientId?: string;
  clientSecret?: string;
  siteUrl?: string;
  yes?: boolean;
}

/**
 * Command for building all local components and Tailwind CSS.
 */
export function buildDeprecatedCommand(program: Command): void {
  program
    .command('build-d')
    .description('build local components and Tailwind CSS assets')
    .option(
      '-d, --dir <directory>',
      'Component directory to build the components in',
    )
    .option('--all', 'Build all components')
    .option(
      '-c, --components <names>',
      'Specific component(s) to build (comma-separated)',
    )
    .option('--no-tailwind', 'Skip Tailwind CSS building')
    .option('-y, --yes', 'Skip confirmation prompts')
    .option('--client-id <id>', 'Client ID')
    .option('--client-secret <secret>', 'Client Secret')
    .option('--site-url <url>', 'Site URL')
    .action(async (options: BuildOptions) => {
      try {
        p.intro(chalk.bold('Drupal Canvas CLI: build'));
        p.log.warn(
          'This command is deprecated and will be removed in favor of the new build command.',
        );

        // Validate options
        validateComponentOptions(options);

        // Default to --all when --yes is used without --components
        const allFlag =
          options.all || (options.yes && !options.components) || false;
        const skipTailwind = !options.tailwind;

        // Update config with CLI options
        updateConfigFromOptions(options);

        if (!skipTailwind) {
          await ensureAuthConfig();
        }

        // Select components to build
        const { directories: componentsToBuild } = await selectLocalComponents({
          all: allFlag,
          components: options.components,
          skipConfirmation: options.yes,
          selectMessage: 'Select components to build',
          componentDir: options.dir,
        });

        const componentLabelPluralized = pluralizeComponent(
          componentsToBuild.length,
        );

        // Step 1: Build individual components
        const s1 = p.spinner();
        s1.start(`Building ${componentLabelPluralized}`);
        const results: Result[] = [];
        for (const componentDir of componentsToBuild) {
          results.push(await buildComponent(componentDir));
        }

        s1.stop(
          chalk.green(
            `Processed ${componentsToBuild.length} ${componentLabelPluralized}`,
          ),
        );
        // Report component build results
        reportResults(results, 'Built components', 'Component');
        if (results.map((result) => result.success).includes(false)) {
          process.exit(1);
        }

        if (skipTailwind) {
          p.log.info('Skipping Tailwind CSS build');
        } else {
          // Step 2: Build Tailwind CSS
          const s2 = p.spinner();
          s2.start('Building Tailwind CSS');
          const tailwindResult = await buildTailwindForComponents(
            componentsToBuild as string[],
          );
          s2.stop(
            chalk.green(
              `Processed Tailwind CSS classes from ${componentsToBuild.length} selected local ${componentLabelPluralized} and all online components`,
            ),
          );

          // Report Tailwind CSS results in separate table
          reportResults([tailwindResult], 'Built assets', 'Asset');

          if (!tailwindResult.success) {
            return process.exit(1);
          }
        }

        p.outro(`📦 Build completed`);
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
