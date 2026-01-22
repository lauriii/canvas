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
import type { Component } from '../types/Component';
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
  skipCss?: boolean;
  cssOnly?: boolean;
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
    .option('--skip-css', 'Skip downloading global CSS')
    .option('--css-only', 'Download only global CSS (skip components)')
    .action(async (options: DownloadOptions) => {
      p.intro(chalk.bold('Drupal Canvas CLI: download'));

      try {
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
        await ensureConfig([
          'siteUrl',
          'clientId',
          'clientSecret',
          'scope',
          'componentDir',
        ]);

        const config = getConfig();
        const apiService = await createApiService();

        let components: Record<string, Component> = {};
        let globalCss: string;

        const s = p.spinner();

        // Handle --css-only case differently to skip component fetching
        if (options.cssOnly) {
          s.start('Fetching global CSS');
          const globalAssetLibrary = await apiService.getGlobalAssetLibrary();
          globalCss = globalAssetLibrary?.css?.original || '';
          s.stop('Global CSS fetched');
        } else {
          // Regular flow: fetch both components and global CSS
          s.start('Fetching components and global CSS');

          const [fetchedComponents, globalAssetLibrary] = await Promise.all([
            apiService.listComponents(),
            apiService.getGlobalAssetLibrary(),
          ]);

          components = fetchedComponents;
          globalCss = globalAssetLibrary?.css?.original || '';

          if (Object.keys(components).length === 0) {
            s.stop('No components found');
            p.outro('Download cancelled - no components were found');
            return;
          }

          s.stop(`Found ${Object.keys(components).length} components`);
        }

        // Default to --all when --yes is used without --components
        const allFlag =
          options.all || (options.yes && !options.components) || false;

        // Select components to download
        const { components: componentsToDownload, includeGlobalCss } =
          await selectRemoteComponents(components, {
            all: allFlag,
            components: options.components,
            skipConfirmation: options.yes,
            skipCss: options.skipCss,
            cssOnly: options.cssOnly,
            includeGlobalCss: !options.skipCss,
            globalCssDefault: true,
            selectMessage: 'Select items to download',
            confirmMessage: `Download to ${config.componentDir}?`,
          });

        // Handle singular/plural cases for console messages.
        const componentCount = Object.keys(componentsToDownload).length;
        const componentPluralized = pluralizeComponent(componentCount);

        // Download components
        const results: Result[] = [];

        // Update spinner message based on what's being downloaded
        const downloadMessage = options.cssOnly
          ? 'Downloading global CSS'
          : componentCount > 0
            ? `Downloading ${componentPluralized}`
            : 'Processing request';

        s.start(downloadMessage);

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
        const successMessage =
          options.cssOnly && componentCount === 0
            ? 'Global CSS download completed'
            : `Processed ${componentCount} ${componentPluralized}`;

        s.stop(chalk.green(successMessage));

        if (componentCount > 0) {
          reportResults(results, 'Downloaded components', 'Component');
        }

        // Create global.css file if selected for download (even if empty).
        if (includeGlobalCss && typeof globalCss === 'string') {
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

        // Display appropriate outro message
        const outroMessage =
          options.cssOnly && componentCount === 0
            ? '⬇️ Global CSS downloaded successfully'
            : includeGlobalCss && componentCount > 0
              ? '⬇️ Components and global CSS downloaded successfully'
              : componentCount > 0
                ? '⬇️ Components downloaded successfully'
                : '⬇️ Download command completed';

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
