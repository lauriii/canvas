import type { Command } from 'commander';
import * as p from '@clack/prompts';
import path from 'path';
import chalk from 'chalk';
import { setConfig, getConfig, ensureConfig } from '../config.js';
import type { ApiService } from '../services/api.js';
import { createApiService } from '../services/api.js';
import {
  processComponentFiles,
  createComponentPayload,
} from '../utils/process-component-files.js';
import { findComponentDirectories } from '../utils/find-component-directories.js';
import type { Result } from '../types/Result.js';
import { buildComponent } from '../utils/build';
import fs from 'fs/promises';
import { reportResults } from '../utils/report-results';

interface UploadOptions {
  clientId?: string;
  clientSecret?: string;
  siteUrl?: string;
  scope?: string;
  dir?: string;
  verbose?: boolean;
  all?: boolean;
}

/**
 * Registers the upload command. Scripts that run on CI should use the --all flag.
 */
export function uploadCommand(program: Command): void {
  program
    .command('upload')
    .description('Upload components to Experience Builder')
    .option('--client-id <id>', 'Client ID')
    .option('--client-secret <secret>', 'Client Secret')
    .option('--site-url <url>', 'Site URL')
    .option('--scope <scope>', 'Scope')
    .option('-d, --dir <directory>', 'Component directory')
    .option('--all', 'Upload all components')
    .option('--verbose', 'Verbose output')
    .action(async (options: UploadOptions) => {
      const allFlag = options.all || false;
      try {
        p.intro('Experience Builder Component Upload');

        // Update config with CLI options
        if (options.clientId) setConfig({ clientId: options.clientId });
        if (options.clientSecret)
          setConfig({ clientSecret: options.clientSecret });
        if (options.siteUrl) setConfig({ siteUrl: options.siteUrl });
        if (options.dir) setConfig({ componentDir: options.dir });
        if (options.scope) setConfig({ scope: options.scope });
        if (options.all) setConfig({ all: options.all });
        if (options.verbose) setConfig({ verbose: options.verbose });
        // Ensure all required config is present
        await ensureConfig([
          'siteUrl',
          'clientId',
          'clientSecret',
          'scope',
          'componentDir',
        ]);
        const config = getConfig();

        // Find component directories
        const componentDirs = await findComponentDirectories(
          config.componentDir,
        );
        if (componentDirs.length === 0) {
          p.outro('Upload cancelled - no components were found');
          return;
        }
        // Select components to upload
        const componentsToUpload = await selectComponentsToUpload(
          componentDirs,
          allFlag,
        );
        if (!componentsToUpload || componentsToUpload.length === 0) {
          return;
        }

        // Create API service
        const apiService = await createApiService();

        // Build and upload components
        const componentResults = await getBuildAndUploadResults(
          componentsToUpload as string[],
          apiService,
        );
        const globalCssResult = await uploadGlobalCss(
          apiService,
          config.componentDir,
        );
        // Display results
        reportResults(componentResults, 'Uploaded components', 'Component');
        if (globalCssResult) {
          reportResults([globalCssResult], 'Uploaded assets', 'Asset');
        }
        p.outro('🥳 Upload completed');
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

/**
 * Select components to upload.
 */
async function selectComponentsToUpload(
  componentDirs: string[],
  allFlag: boolean,
): Promise<string[] | null> {
  // Select all components if the --all flag is set.
  if (allFlag) {
    console.log(`Selected all ${componentDirs.length} components to upload`);
    return componentDirs;
  }
  const selectedDirs = await p.multiselect({
    message: 'Select components to upload',
    options: [
      {
        value: '_allComponents',
        label: 'All components',
      },
      ...componentDirs.map((dir) => ({
        value: dir,
        label: path.basename(dir),
      })),
    ],
    required: true,
  });

  if (p.isCancel(selectedDirs)) {
    p.cancel('Operation cancelled');
    return null;
  }

  const count = selectedDirs.includes('_allComponents')
    ? componentDirs.length
    : selectedDirs.length;

  // Confirm upload
  const config = getConfig();
  const confirmUpload = await p.confirm({
    message: `Upload ${count} components to ${config.siteUrl}?`,
    initialValue: true,
  });

  if (p.isCancel(confirmUpload) || !confirmUpload) {
    p.cancel('Operation cancelled');
    return null;
  }

  // If 'all' is selected, return all component directories.
  if (selectedDirs.includes('_allComponents')) {
    return componentDirs;
  }
  return selectedDirs;
}

// Get the build and upload results.
async function getBuildAndUploadResults(
  componentsToUpload: string[],
  apiService: ApiService,
): Promise<Result[]> {
  const results: Result[] = [];

  // Build components
  const buildResults = await buildSelectedComponents(componentsToUpload);

  // Filter successful builds
  const successfulBuilds = buildResults.filter((build) => build.success);
  const failedBuilds = buildResults.filter((build) => !build.success);

  // // If no successful builds, return early
  if (successfulBuilds.length === 0) {
    const message = 'All component builds failed. Upload process aborted.';
    p.note(chalk.red(message));
    process.exit(1);
  }
  let spinner: any;
  spinner = p.spinner();
  spinner.start('Uploading components');

  // Only upload the successfully built components.
  for (const buildResult of successfulBuilds) {
    const dir = buildResult.itemName
      ? (componentsToUpload.find(
          (d) => path.basename(d) === buildResult.itemName,
        ) as string)
      : undefined;

    if (!dir) continue;

    try {
      // Process component files
      const componentName = path.basename(dir);

      // Process all component files
      const { sourceCodeJs, compiledJs, sourceCodeCss, compiledCss, metadata } =
        await processComponentFiles(dir);

      const machineName =
        buildResult.itemName ||
        metadata.machineName ||
        componentName.toLowerCase().replace(/[^a-z0-9_-]/g, '_');

      // @todo: Add code from /ui to automatically detect first party imports
      //   without relying on yml metadata.
      const importedJsComponents = metadata.importedJsComponents || [];

      const componentPayload = createComponentPayload({
        metadata,
        machineName,
        componentName,
        sourceCodeJs,
        compiledJs,
        sourceCodeCss,
        compiledCss,
        importedJsComponents,
      });

      // Check if component exists already
      let componentExists = false;

      try {
        await apiService.getComponent(machineName);
        componentExists = true;
      } catch (error) {
        // Component does not exist, will create new.
      }

      // Create or update the component
      if (componentExists) {
        await apiService.updateComponent(machineName, componentPayload);
      } else {
        await apiService.createComponent(componentPayload);
      }
      results.push({
        itemName: componentName,
        success: true,
        details: [
          {
            content: componentExists ? 'Updated' : 'Created',
          },
        ],
      });
    } catch (error) {
      const errorMessage =
        error instanceof Error ? error.message : String(error);
      results.push({
        itemName: buildResult.itemName,
        success: false,
        details: [
          {
            content: errorMessage,
          },
        ],
      });
    }
  }
  // Add the failed builds to the upload results to get the correct count.
  results.push(...failedBuilds);
  spinner.stop('Upload completed');
  return results;
}

/**
 * Build all selected components
 */
async function buildSelectedComponents(
  componentDirs: string[],
): Promise<Result[]> {
  const buildResults: Result[] = [];
  for (const dir of componentDirs) {
    buildResults.push(await buildComponent(dir));
  }
  return buildResults;
}

/**
 * Uploads global CSS if it exists
 */
async function uploadGlobalCss(
  apiService: ApiService,
  componentDir: string,
): Promise<Result | null> {
  try {
    const globalCssPath = path.join(componentDir, 'global.css');
    const globalCssExists = await fs
      .access(globalCssPath)
      .then(() => true)
      .catch(() => false);
    if (globalCssExists) {
      const globalCssContent = await fs.readFile(globalCssPath, 'utf-8');

      // Upload the global CSS
      await apiService.updateGlobalCss({
        css: {
          original: globalCssContent,
          compiled: '',
        },
      });
      return {
        success: true,
        itemName: 'global.css',
      };
    } else {
      return null;
    }
  } catch (error) {
    const errorMessage = error instanceof Error ? error.message : String(error);
    return {
      success: false,
      itemName: 'global.css',
      details: [
        {
          content: errorMessage,
        },
      ],
    };
  }
}
