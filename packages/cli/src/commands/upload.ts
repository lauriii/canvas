import fs from 'fs/promises';
import path from 'path';
import chalk from 'chalk';
import { parse } from '@babel/parser';
import * as p from '@clack/prompts';
import {
  getDataDependenciesFromAst,
  getImportsFromAst,
} from '@drupal-canvas/ui/features/code-editor/utils/ast-utils';

import { ensureConfig, getConfig } from '../config.js';
import { createApiService } from '../services/api.js';
import { buildComponent } from '../utils/build';
import {
  buildTailwindForComponents,
  getGlobalCss,
} from '../utils/build-tailwind.js';
import {
  pluralizeComponent,
  updateConfigFromOptions,
  validateComponentOptions,
} from '../utils/command-helpers';
import { selectLocalComponents } from '../utils/component-selector.js';
import {
  createComponentPayload,
  processComponentFiles,
} from '../utils/process-component-files.js';
import { reportResults } from '../utils/report-results';
import { createProgressCallback, processInPool } from '../utils/request-pool';
import { fileExists } from '../utils/utils';

import type { DataDependencies } from '@drupal-canvas/ui/types/CodeComponent';
import type { Command } from 'commander';
import type { ApiService } from '../services/api.js';
import type { Result } from '../types/Result.js';

/**
 * Result type for component existence checks.
 */
interface ComponentExistsResult {
  machineName: string;
  exists: boolean;
  error?: Error;
}

/**
 * Result type for component upload operations.
 */
interface ComponentUploadResult {
  machineName: string;
  success: boolean;
  operation: 'create' | 'update';
  error?: Error;
}

/**
 * Check if components exist.
 *
 * @param machineNames - Array of component machine names to check
 * @param apiService - API service instance
 * @param onProgress - Progress callback function
 * @returns Promise resolving to existence results for each component
 */
async function checkComponentsExist(
  machineNames: string[],
  apiService: { listComponents: () => Promise<Record<string, unknown>> },
  onProgress: () => void,
): Promise<ComponentExistsResult[]> {
  try {
    // Get all existing components in a single API call
    const existingComponents = await apiService.listComponents();
    const existingMachineNames = new Set(Object.keys(existingComponents));

    // Check each requested machine name against the existing components
    return machineNames.map((machineName) => {
      onProgress();
      return {
        machineName,
        exists: existingMachineNames.has(machineName),
      };
    });
  } catch (error) {
    // If listComponents fails, return all as non-existent with error
    return machineNames.map((machineName) => {
      onProgress();
      return {
        machineName,
        exists: false,
        error: error instanceof Error ? error : new Error(String(error)),
      };
    });
  }
}

/**
 * Upload (create or update) multiple components concurrently.
 *
 * @param uploadTasks - Array of upload task objects
 * @param apiService - API service instance
 * @param onProgress - Optional progress callback
 * @returns Promise resolving to upload results for each component
 */
async function uploadComponents<T>(
  uploadTasks: Array<{
    machineName: string;
    componentPayload: T;
    shouldUpdate: boolean;
  }>,
  apiService: {
    createComponent: (payload: T, raw?: boolean) => Promise<unknown>;
    updateComponent: (name: string, payload: T) => Promise<unknown>;
  },
  onProgress?: () => void,
): Promise<ComponentUploadResult[]> {
  const results = await processInPool(uploadTasks, async (task) => {
    try {
      if (task.shouldUpdate) {
        await apiService.updateComponent(
          task.machineName,
          task.componentPayload,
        );
      } else {
        await apiService.createComponent(task.componentPayload, true);
      }
      onProgress?.();
      return {
        machineName: task.machineName,
        success: true,
        operation: task.shouldUpdate
          ? ('update' as const)
          : ('create' as const),
      };
    } catch {
      // Make another attempt to create/update without the 2nd argument so
      // the error is in the format expected by the catch statement that
      // summarizes the success (or lack thereof) of this operation
      try {
        if (task.shouldUpdate) {
          await apiService.updateComponent(
            task.machineName,
            task.componentPayload,
          );
        } else {
          await apiService.createComponent(task.componentPayload);
        }
        onProgress?.();
        return {
          machineName: task.machineName,
          success: true,
          operation: task.shouldUpdate
            ? ('update' as const)
            : ('create' as const),
        };
      } catch (fallbackError) {
        onProgress?.();
        return {
          machineName: task.machineName,
          success: false,
          operation: task.shouldUpdate
            ? ('update' as const)
            : ('create' as const),
          error:
            fallbackError instanceof Error
              ? fallbackError
              : new Error(String(fallbackError)),
        };
      }
    }
  });

  return results.map((result) => {
    if (result.success && result.result) {
      return result.result;
    }
    return {
      machineName: uploadTasks[result.index]?.machineName || 'unknown',
      success: false,
      operation: 'create' as const,
      error: result.error || new Error('Unknown error during upload'),
    };
  });
}

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
          });

        // Create API service
        const apiService = await createApiService();

        // Verify API connection and authentication before proceeding
        // This will throw auth/network errors early before processing components
        await apiService.listComponents();

        let componentResults: Result[] = [];

        // Handle component uploads (skip if --css-only)
        if (!options.cssOnly && componentsToUpload.length > 0) {
          // Build and upload components
          componentResults = await getBuildAndUploadResults(
            componentsToUpload as string[],
            apiService,
            includeGlobalCss ?? false,
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
            includeGlobalCss, // Use local CSS if includeGlobalCss is true
          );
          const componentLabelPluralized = pluralizeComponent(
            componentsToUpload.length,
          );
          s2.stop(
            chalk.green(
              `Processed Tailwind CSS classes from ${componentsToUpload.length} selected local ${componentLabelPluralized} and all online components`,
            ),
          );

          // Capture Tailwind error if any
          if (!tailwindResult.success && tailwindResult.details) {
            // Report failed Tailwind CSS build.
            reportResults([tailwindResult], 'Built assets', 'Asset');
            p.note(
              chalk.red(`Tailwind build failed, global assets upload aborted.`),
            );
          } else {
            // If the Tailwind build was successful, proceed with uploading the global CSS if selected.
            if (includeGlobalCss) {
              const globalCssResult = await uploadGlobalAssetLibrary(
                apiService,
                config.componentDir,
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

interface PreparedComponent {
  machineName: string;
  componentName: string;
  componentPayload: ReturnType<typeof createComponentPayload>;
  dir: string;
  buildResult: Result;
}

async function prepareComponentsForUpload(
  successfulBuilds: Result[],
  componentsToUpload: string[],
): Promise<{ prepared: PreparedComponent[]; failed: Result[] }> {
  const prepared: PreparedComponent[] = [];
  const failed: Result[] = [];

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
      if (!metadata) {
        throw new Error('Invalid metadata file');
      }

      const machineName =
        buildResult.itemName ||
        metadata.machineName ||
        componentName.toLowerCase().replace(/[^a-z0-9_-]/g, '_');

      let importedJsComponents = [] as string[];
      let dataDependencies: DataDependencies = {};
      // Collect first party and data dependency imports from source code.
      try {
        const ast = parse(sourceCodeJs, {
          sourceType: 'module',
          plugins: ['jsx'],
        });
        const scope = '@/components/';
        importedJsComponents = getImportsFromAst(ast, scope);
        dataDependencies = getDataDependenciesFromAst(ast);
      } catch (error) {
        p.note(chalk.red(`Error: ${error}`));
      }
      const componentPayloadArg = {
        metadata,
        machineName,
        componentName,
        sourceCodeJs,
        compiledJs,
        sourceCodeCss,
        compiledCss,
        importedJsComponents,
        dataDependencies,
      };
      const componentPayload = createComponentPayload(componentPayloadArg);

      prepared.push({
        machineName,
        componentName,
        componentPayload,
        dir,
        buildResult,
      });
    } catch (error) {
      const errorMessage =
        error instanceof Error ? error.message : String(error);
      failed.push({
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

  return { prepared, failed };
}

async function getBuildAndUploadResults(
  componentsToUpload: string[],
  apiService: ApiService,
  includeGlobalCss: boolean,
): Promise<Result[]> {
  const results: Result[] = [];
  const spinner = p.spinner();

  spinner.start('Building components');
  const buildResults = await buildSelectedComponents(
    componentsToUpload,
    includeGlobalCss,
  );

  const successfulBuilds = buildResults.filter((build) => build.success);
  const failedBuilds = buildResults.filter((build) => !build.success);

  if (successfulBuilds.length === 0) {
    const message = 'All component builds failed.';
    spinner.stop(chalk.red(message));
    return failedBuilds;
  }

  spinner.message('Preparing components for upload');
  const { prepared: preparedComponents, failed: preparationFailures } =
    await prepareComponentsForUpload(successfulBuilds, componentsToUpload);

  results.push(...preparationFailures);

  if (preparedComponents.length === 0) {
    spinner.stop(chalk.red('All component preparations failed'));
    return [...results, ...failedBuilds];
  }

  const machineNames = preparedComponents.map((c) => c.machineName);
  const existenceProgress = createProgressCallback(
    spinner,
    'Checking component existence',
    machineNames.length,
  );

  spinner.message('Checking component existence');
  const existenceResults = await checkComponentsExist(
    machineNames,
    apiService,
    existenceProgress,
  );

  const uploadTasks = preparedComponents.map((component, index) => ({
    machineName: component.machineName,
    componentPayload: component.componentPayload,
    shouldUpdate: existenceResults[index]?.exists || false,
  }));

  const uploadProgress = createProgressCallback(
    spinner,
    'Uploading components',
    uploadTasks.length,
  );

  spinner.message('Uploading components');
  const uploadResults = await uploadComponents(
    uploadTasks,
    apiService,
    uploadProgress,
  );

  for (let i = 0; i < preparedComponents.length; i++) {
    const component = preparedComponents[i];
    const uploadResult = uploadResults[i];

    if (uploadResult.success) {
      results.push({
        itemName: component.componentName,
        success: true,
        details: [
          {
            content:
              uploadResult.operation === 'update' ? 'Updated' : 'Created',
          },
        ],
      });
    } else {
      const errorMessage =
        uploadResult.error?.message || 'Unknown upload error';
      results.push({
        itemName: component.componentName,
        success: false,
        details: [
          {
            content: errorMessage.trim() || 'Unknown upload error',
          },
        ],
      });
    }
  }

  results.push(...failedBuilds);
  const componentLabelPluralized =
    results.length === 1 ? 'component' : 'components';
  spinner.stop(
    chalk.green(`Processed ${results.length} ${componentLabelPluralized}`),
  );
  return results;
}

/**
 * Build all selected components
 */
async function buildSelectedComponents(
  componentDirs: string[],
  useLocalGlobalCss: boolean = true,
): Promise<Result[]> {
  const buildResults: Result[] = [];
  for (const dir of componentDirs) {
    buildResults.push(await buildComponent(dir, useLocalGlobalCss));
  }
  return buildResults;
}

/**
 * Uploads global CSS if it exists
 */
async function uploadGlobalAssetLibrary(
  apiService: ApiService,
  componentDir: string,
): Promise<Result> {
  try {
    const distDir = path.join(componentDir, 'dist');
    const globalCompiledCssPath = path.join(distDir, 'index.css');
    const globalCompiledCssExists = await fileExists(globalCompiledCssPath);
    if (globalCompiledCssExists) {
      const globalCompiledCss = await fs.readFile(
        path.join(distDir, 'index.css'),
        'utf-8',
      );
      const classNameCandidateIndexFile = await fs.readFile(
        path.join(distDir, 'index.js'),
        'utf-8',
      );
      // Get original CSS - local-first approach
      const originalCss = await getGlobalCss();

      // Upload the global CSS
      await apiService.updateGlobalAssetLibrary({
        css: {
          original: originalCss,
          compiled: globalCompiledCss,
        },
        js: {
          original: classNameCandidateIndexFile,
          compiled: '',
        },
      });
      return {
        success: true,
        itemName: 'Global CSS',
      };
    } else {
      return {
        success: false,
        itemName: 'Global CSS',
        details: [
          {
            content: `Global CSS file not found at ${globalCompiledCssPath}.`,
          },
        ],
      };
    }
  } catch (error) {
    const errorMessage = error instanceof Error ? error.message : String(error);
    return {
      success: false,
      itemName: 'Global CSS',
      details: [
        {
          content: errorMessage,
        },
      ],
    };
  }
}
