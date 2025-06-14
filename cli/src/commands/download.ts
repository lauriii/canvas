import type { Command } from 'commander';
import * as p from '@clack/prompts';
import fs from 'fs/promises';
import path from 'path';
import chalk from 'chalk';
import { getConfig, setConfig } from '../config';
import { createApiService } from '../services/api';
import yaml from 'js-yaml';
import type { Component } from '../types/Component';

interface DownloadOptions {
  token?: string;
  url?: string;
  dir?: string;
  component?: string;
  all?: boolean; // Download all components
}

export function downloadCommand(program: Command): void {
  program
    .command('download')
    .description('Download components from Experience Builder')
    .option('-t, --token <token>', 'Authentication token')
    .option('-u, --url <url>', 'Site URL')
    .option('-d, --dir <directory>', 'Component directory')
    .option('-c, --component <name>', 'Specific component to download')
    .option('--all', 'Download all components')
    .action(async (options: DownloadOptions) => {
      p.intro('Experience Builder Component Download');

      try {
        // Update config with CLI options
        if (options.token) setConfig({ auth_token: options.token });
        if (options.url) setConfig({ site_url: options.url });
        if (options.dir) setConfig({ component_dir: options.dir });
        if (options.all) setConfig({ all: options.all });

        const config = getConfig();

        // Prompt for any missing required configurations
        await promptForMissingConfig();

        const apiService = createApiService();

        // Get components
        const s = p.spinner();
        s.start('Fetching components');

        const components = await apiService.listComponents();
        const {
          css: { original: globalCss },
        } = await apiService.getGlobalCss();

        if (Object.keys(components).length === 0) {
          s.stop('No components found');
          p.outro('Download cancelled - no components were found');
          return;
        }

        s.stop(`Found ${Object.keys(components).length} components`);

        // If a specific component was requested, filter for it
        let componentsToDownload: Record<string, Component> = {};

        // If --all option is used, download all components.
        if (options.all) {
          // Download all components
          componentsToDownload = components;
        } else if (options.component) {
          const component = Object.values(components).find(
            (c) =>
              c.machineName === options.component ||
              c.name === options.component,
          );
          if (!component) {
            p.note(chalk.red(`Component "${options.component}" not found`));
            p.outro('Download cancelled');
            return;
          }
          componentsToDownload = { component };
        } else {
          // Choose components to download
          const selectedComponents = await p.multiselect({
            message: 'Select components to download',
            options: [
              {
                value: '_allComponents',
                label: 'All components',
              },
              ...Object.keys(components).map((key) => ({
                value: components[key].machineName,
                label: components[key].name,
              })),
            ],
            required: true,
          });

          if (p.isCancel(selectedComponents)) {
            p.cancel('Operation cancelled');
            return;
          }

          // Check if "all" option is selected
          if (selectedComponents.includes('_allComponents')) {
            componentsToDownload = components;
          } else {
            componentsToDownload = Object.fromEntries(
              Object.entries(components).filter(([_, component]) =>
                (selectedComponents as string[]).includes(
                  component.machineName,
                ),
              ),
            );
          }
        }

        // Handle singular/plural cases for console messages.
        const componentPluralized = `component${Object.keys(componentsToDownload).length > 1 ? 's' : ''}`;

        // Confirm download
        const confirmDownload = await p.confirm({
          message: `Download ${Object.keys(componentsToDownload).length} ${componentPluralized} to ${config.component_dir}?`,
          initialValue: true,
        });

        if (p.isCancel(confirmDownload) || !confirmDownload) {
          p.cancel('Operation cancelled');
          return;
        }

        // Download components
        const results = [];

        s.start(`Downloading ${componentPluralized}`);

        for (const key in componentsToDownload) {
          const component = componentsToDownload[key];
          try {
            // Create component directory structure
            const componentDir = path.join(
              config.component_dir,
              component.machineName,
            );
            // Check if the directory exists and is non-empty to confirm deletion.
            const dirExists = await fs
              .stat(componentDir)
              .then(() => true)
              .catch(() => false);
            if (dirExists) {
              const files = await fs.readdir(componentDir);
              if (files.length > 0) {
                const confirmDelete = await p.confirm({
                  message: `The "${componentDir}" is not empty. Are you sure you want to delete and overwrite this directory?`,
                  initialValue: true,
                });
                if (p.isCancel(confirmDelete) || !confirmDelete) {
                  p.cancel('Operation cancelled');
                  process.exit(0);
                }
              }
            }

            await fs.rm(componentDir, { recursive: true, force: true });
            await fs.mkdir(componentDir, { recursive: true });

            // Create component.yml metadata file
            const metadata = {
              name: component.name,
              machineName: component.machineName,
              status: component.status,
              required: component.required || [],
              props: component.props || {},
              slots: component.slots || {},
              blockOverride: component.block_override || null,
              importedJsComponents: component.imported_js_components || [],
            };

            await fs.writeFile(
              path.join(componentDir, `${component.machineName}.component.yml`),
              yaml.dump(metadata),
              'utf-8',
            );

            // Create JS file
            if (component.source_code_js) {
              await fs.writeFile(
                path.join(componentDir, `${component.machineName}.jsx`),
                component.source_code_js,
                'utf-8',
              );
            }

            // Create CSS file
            if (component.source_code_css) {
              await fs.writeFile(
                path.join(componentDir, `${component.machineName}.css`),
                component.source_code_css,
                'utf-8',
              );
            }

            results.push({
              name: component.name,
              success: true,
              path: componentDir,
            });
          } catch (error) {
            results.push({
              name: component.name,
              success: false,
              error: error instanceof Error ? error.message : String(error),
            });
          }
        }
        let globalCssResult;
        // Create global.css file if it exists.
        if (globalCss) {
          try {
            const globalCssPath = path.join(config.component_dir, 'global.css');
            await fs.writeFile(globalCssPath, globalCss, 'utf-8');
            globalCssResult = {
              name: 'Global CSS',
              success: true,
              path: globalCssPath,
            };
          } catch (error) {
            globalCssResult = {
              name: 'Global CSS',
              success: false,
              error: error instanceof Error ? error.message : String(error),
            };
          }
        }

        s.stop('Download completed');

        // Report results
        const successful = results.filter((r) => r.success).length;
        const failed = results.filter((r) => !r.success).length;

        // Handle singular/plural cases for console messages.
        const successfulComponentPluralized = `component${successful > 1 ? 's' : ''}`;

        p.note(
          `${successful} ${successfulComponentPluralized} downloaded successfully, ${failed} failed`,
        );

        // Output failures with details
        if (failed > 0) {
          console.log(chalk.red('Failed downloads:'));
          results
            .filter((r) => !r.success)
            .forEach((r) => {
              console.log(chalk.red(`  - ${r.name}: ${r.error}`));
            });
        }
        if (globalCssResult && !globalCssResult.success) {
          console.log(
            chalk.red(`Global CSS download failed: ${globalCssResult.error}`),
          );
        }

        // Output successful downloads
        if (successful > 0) {
          console.log(chalk.green('Successfully downloaded:'));
          results
            .filter((r) => r.success)
            .forEach((r) => {
              console.log(chalk.green(`  - ${r.name}: ${r.path}`));
            });
        }
        if (globalCssResult && globalCssResult.success) {
          console.log('');
          console.log(chalk.green(`  - Global CSS: ${globalCssResult.path}`));
        }

        p.outro(`✅ ${successfulComponentPluralized} download completed`);
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

async function promptForMissingConfig(): Promise<void> {
  const config = getConfig();

  // If any required config is missing, prompt for it
  if (!config.site_url) {
    const url = await p.text({
      message: 'Enter the site URL',
      placeholder: 'https://example.com',
      validate: (value) => {
        if (!value) return 'Site URL is required';
        if (!value.startsWith('http'))
          return 'URL must start with http:// or https://';
        return;
      },
    });

    if (p.isCancel(url)) {
      p.cancel('Operation cancelled');
      process.exit(0);
    }

    setConfig({ site_url: url });
  }

  if (!config.auth_token) {
    const token = await p.password({
      message: 'Enter your authentication token',
      validate: (value) => {
        if (!value) return 'Authentication token is required';
        return;
      },
    });

    if (p.isCancel(token)) {
      p.cancel('Operation cancelled');
      process.exit(0);
    }

    setConfig({ auth_token: token });
  }
}
