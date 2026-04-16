import chalk from 'chalk';
import * as p from '@clack/prompts';

import { ensureConfig, getConfig } from '../config.js';
import { createApiService, ensureAuthConfig } from '../services/api.js';
import { buildTailwindForComponents } from '../utils/build-tailwind-deprecated';
import {
  pluralizeComponent,
  updateConfigFromOptions,
  validateComponentOptions,
} from '../utils/command-helpers';
import { selectLocalComponents } from '../utils/component-selector.js';
import { reportResults } from '../utils/report-results';
import {
  buildAndUploadComponents,
  uploadGlobalAssetLibrary,
} from '../utils/upload-components.js';

import type { Command } from 'commander';
import type { Result } from '../types/Result.js';

interface UploadOptions {
  clientId?: string;
  clientSecret?: string;
  siteUrl?: string;
  scope?: string;
  dir?: string;
  all?: boolean;
  components?: string;
  tailwind?: boolean;
  yes?: boolean;
  skipCss?: boolean;
  cssOnly?: boolean;
}

/**
 * Registers the upload command. Scripts that run on CI should use the --all flag.
 */
export function uploadCommand(program: Command): void {
  program
    .command('upload')
    .description('build and upload local components and global CSS assets')
    .option('--client-id <id>', 'Client ID')
    .option('--client-secret <secret>', 'Client Secret')
    .option('--site-url <url>', 'Site URL')
    .option('--scope <scope>', 'Scope')
    .option('-d, --dir <directory>', 'Component directory')
    .option(
      '-c, --components <names>',
      'Specific component(s) to upload (comma-separated)',
    )
    .option('--all', 'Upload all components')
    .option('-y, --yes', 'Skip confirmation prompts')
    .option('--no-tailwind', 'Skip Tailwind CSS building')
    .option('--skip-css', 'Skip global CSS upload')
    .option('--css-only', 'Upload only global CSS (skip components)')
    .action(async (options: UploadOptions) => {
      // Default to --all when --yes is used without --components
      const allFlag =
        options.all || (options.yes && !options.components) || false;
      const skipTailwind = !options.tailwind;

      try {
        p.intro(chalk.bold('Drupal Canvas CLI: upload'));
        p.log.warn(
          chalk.yellow(
            '⚠️ [DEPRECATED]: This command is deprecated and will be removed in favor of the new push command. Please use `npx canvas push` instead.',
          ),
        );
        // Validate options
        validateComponentOptions(options);

        // Validate CSS-related options
        if (options.skipCss && options.cssOnly) {
          throw new Error(
            'Cannot use both --skip-css and --css-only flags together',
          );
        }

        // Update config with CLI options
        updateConfigFromOptions(options);

        // Ensure all required config is present
        await ensureAuthConfig();
        await ensureConfig(['deprecatedComponentDir']);
        const config = getConfig();

        // Select components and global CSS to upload
        const { directories: componentsToUpload, includeGlobalCss } =
          await selectLocalComponents({
            all: allFlag,
            components: options.components,
            skipConfirmation: options.yes,
            skipCss: options.skipCss,
            cssOnly: options.cssOnly,
            includeGlobalCss: !options.skipCss,
            globalCssDefault: true,
            selectMessage: 'Select items to upload',
            componentDir: options.dir,
          });

        // Create API service
        const apiService = await createApiService();

        // Verify API connection and authentication before proceeding
        await apiService.listComponents();

        let componentResults: Result[] = [];

        // Handle component uploads (skip if --css-only)
        if (!options.cssOnly && componentsToUpload.length > 0) {
          componentResults = await buildAndUploadComponents(
            componentsToUpload as string[],
            apiService,
            includeGlobalCss ?? false,
            'Uploading',
          );

          // Display component upload results
          reportResults(componentResults, 'Uploaded components', 'Component');

          // Exit with error if any component failed
          if (componentResults.some((result) => !result.success)) {
            process.exit(1);
          }
        }

        if (skipTailwind) {
          p.log.info('Skipping Tailwind CSS build');
        } else {
          // Build Tailwind CSS with appropriate global CSS source
          const s2 = p.spinner();
          s2.start('Building Tailwind CSS');
          const tailwindResult = await buildTailwindForComponents(
            componentsToUpload as string[],
            includeGlobalCss,
          );
          const componentLabelPluralized = pluralizeComponent(
            componentsToUpload.length,
          );
          s2.stop(
            chalk.green(
              `Processed Tailwind CSS classes from ${componentsToUpload.length} selected local ${componentLabelPluralized} and all online components`,
            ),
          );

          if (!tailwindResult.success && tailwindResult.details) {
            reportResults([tailwindResult], 'Built assets', 'Asset');
            p.note(
              chalk.red(`Tailwind build failed, global assets upload aborted.`),
            );
          } else {
            if (includeGlobalCss) {
              const globalCssResult = await uploadGlobalAssetLibrary(
                apiService,
                config.deprecatedComponentDir,
              );
              reportResults([globalCssResult], 'Uploaded assets', 'Asset');
            } else {
              p.log.info('Skipping global CSS upload');
            }
          }
        }
        // Display appropriate outro message
        const componentCount = componentsToUpload.length;
        const outroMessage =
          options.cssOnly && componentCount === 0
            ? '⬆️ Global CSS uploaded successfully'
            : includeGlobalCss && componentCount > 0
              ? '⬆️ Components and global CSS uploaded successfully'
              : componentCount > 0
                ? '⬆️ Components uploaded successfully'
                : '⬆️ Upload command completed';

        p.outro(outroMessage);
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
