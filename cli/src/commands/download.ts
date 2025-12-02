import fs from 'fs/promises';
import path from 'path';
import chalk from 'chalk';
import yaml from 'js-yaml';
import * as p from '@clack/prompts';

import { ensureConfig, getConfig } from '../config';
import { createApiService } from '../services/api';
import {
  pluralizeComponent,
  updateConfigFromOptions,
  validateComponentOptions,
} from '../utils/command-helpers';
import { selectRemoteComponents } from '../utils/component-selector';
import { reportResults } from '../utils/report-results';
import { directoryExists } from '../utils/utils';

import type { Command } from 'commander';
import type { Metadata } from '../types/Metadata';
import type { Result } from '../types/Result';

interface DownloadOptions {
  clientId?: string;
  clientSecret?: string;
  siteUrl?: string;
  scope?: string;
  dir?: string;
  components?: string;
  all?: boolean; // Download all components
  yes?: boolean; // Skip all confirmation prompts
  skipOverwrite?: boolean; // Skip downloading components that already exist locally
  verbose?: boolean;
}

export function downloadCommand(program: Command): void {
  program
    .command('download')
    .description('download components to your local filesystem')
    .option('--client-id <id>', 'Client ID')
    .option('--client-secret <secret>', 'Client Secret')
    .option('--site-url <url>', 'Site URL')
    .option('--scope <scope>', 'Scope')
    .option('-d, --dir <directory>', 'Component directory')
    .option(
      '-c, --components <names>',
      'Specific component(s) to download (comma-separated)',
    )
    .option('--all', 'Download all components')
    .option('-y, --yes', 'Skip all confirmation prompts')
    .option(
      '--skip-overwrite',
      'Skip downloading components that already exist locally',
    )
    .option('--verbose', 'Enable verbose output')
    .action(async (options: DownloadOptions) => {
      p.intro(chalk.bold('Drupal Canvas CLI: download'));

      try {
        // Validate options
        validateComponentOptions(options);

        // Update config with CLI options
        updateConfigFromOptions(options);

        // Ensure all required config is present
        await ensureConfig([
          'siteUrl',
          'clientId',
          'clientSecret',
          'scope',
          'componentDir',
        ]);

        const config = getConfig();
        const apiService = await createApiService();

        // Get components
        const s = p.spinner();
        s.start('Fetching components');

        const components = await apiService.listComponents();
        const {
          css: { original: globalCss },
        } = await apiService.getGlobalAssetLibrary();

        if (Object.keys(components).length === 0) {
          s.stop('No components found');
          p.outro('Download cancelled - no components were found');
          return;
        }

        s.stop(`Found ${Object.keys(components).length} components`);

        // Default to --all when --yes is used without --components
        const allFlag =
          options.all || (options.yes && !options.components) || false;

        // Select components to download
        const { components: componentsToDownload } =
          await selectRemoteComponents(components, {
            all: allFlag,
            components: options.components,
            skipConfirmation: options.yes,
            selectMessage: 'Select components to download',
            confirmMessage: `Download to ${config.componentDir}?`,
          });

        // Handle singular/plural cases for console messages.
        const componentPluralized = pluralizeComponent(
          Object.keys(componentsToDownload).length,
        );

        // Download components
        const results: Result[] = [];

        s.start(`Downloading ${componentPluralized}`);

        for (const key in componentsToDownload) {
          const component = componentsToDownload[key];
          try {
            // Create component directory structure
            const componentDir = path.join(
              config.componentDir,
              component.machineName,
            );
            // Check if the directory exists and is non-empty
            const dirExists = await directoryExists(componentDir);
            if (dirExists) {
              const files = await fs.readdir(componentDir);
              if (files.length > 0) {
                // Skip downloading if --skip-overwrite is set
                if (options.skipOverwrite) {
                  results.push({
                    itemName: component.machineName,
                    success: true,
                    details: [
                      {
                        content: 'Skipped (already exists)',
                      },
                    ],
                  });
                  continue;
                }

                // Prompt for confirmation if --yes is not set
                if (!options.yes) {
                  const confirmDelete = await p.confirm({
                    message: `The "${componentDir}" directory is not empty. Are you sure you want to delete and overwrite this directory?`,
                    initialValue: true,
                  });
                  if (p.isCancel(confirmDelete) || !confirmDelete) {
                    p.cancel('Operation cancelled');
                    process.exit(0);
                  }
                }
              }
            }

            await fs.rm(componentDir, { recursive: true, force: true });
            await fs.mkdir(componentDir, { recursive: true });

            // Create component.yml metadata file
            const metadata: Metadata = {
              name: component.name,
              machineName: component.machineName,
              status: component.status,
              required: component.required || [],
              props: {
                properties: component.props || {},
              },
              slots: component.slots || {},
            };

            await fs.writeFile(
              path.join(componentDir, `component.yml`),
              yaml.dump(metadata),
              'utf-8',
            );

            // Create JS file
            if (component.sourceCodeJs) {
              await fs.writeFile(
                path.join(componentDir, `index.jsx`),
                component.sourceCodeJs,
                'utf-8',
              );
            }

            // Create CSS file
            if (component.sourceCodeCss) {
              await fs.writeFile(
                path.join(componentDir, `index.css`),
                component.sourceCodeCss,
                'utf-8',
              );
            }

            results.push({
              itemName: component.machineName,
              success: true,
            });
          } catch (error) {
            results.push({
              itemName: component.machineName,
              success: false,
              details: [
                {
                  content:
                    error instanceof Error ? error.message : String(error),
                },
              ],
            });
          }
        }
        s.stop(
          chalk.green(
            `Processed ${Object.keys(componentsToDownload).length} ${componentPluralized}`,
          ),
        );

        reportResults(results, 'Downloaded components', 'Component');

        // Create global.css file if it exists.
        if (globalCss) {
          let globalCssResult: Result;
          try {
            const globalCssPath = path.join(config.componentDir, 'global.css');
            await fs.writeFile(globalCssPath, globalCss, 'utf-8');
            globalCssResult = {
              itemName: 'global.css',
              success: true,
            };
          } catch (error) {
            const errorMessage =
              error instanceof Error ? error.message : String(error);
            globalCssResult = {
              itemName: 'global.css',
              success: false,
              details: [
                {
                  content: errorMessage,
                },
              ],
            };
          }
          reportResults([globalCssResult], 'Downloaded assets', 'Asset');
        }

        p.outro(`⬇️ Download command completed`);
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
