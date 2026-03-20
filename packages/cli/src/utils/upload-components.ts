import fs from 'fs/promises';
import path from 'path';
import chalk from 'chalk';
import { parse } from '@babel/parser';
import * as p from '@clack/prompts';
import {
  getDataDependenciesFromAst,
  getImportsFromAst,
} from '@drupal-canvas/ui/features/code-editor/utils/ast-utils';

import { buildComponent } from './build-deprecated';
import { getGlobalCss } from './build-tailwind.js';
import { pluralizeComponent } from './command-helpers';
import {
  createComponentPayload,
  processComponentFiles,
} from './process-component-files-deprecated';
import { createProgressCallback, processInPool } from './request-pool';
import { fileExists } from './utils';

import type { DataDependencies } from '@drupal-canvas/ui/types/CodeComponent';
import type { ApiService } from '../services/api.js';
import type { Result } from '../types/Result.js';

interface ComponentExistsResult {
  machineName: string;
  exists: boolean;
  error?: Error;
}

interface ComponentUploadResult {
  machineName: string;
  success: boolean;
  operation: 'create' | 'update';
  error?: Error;
}

interface PreparedComponent {
  machineName: string;
  componentName: string;
  componentPayload: ReturnType<typeof createComponentPayload>;
}

/**
 * Check if components exist on the remote.
 */
export async function checkComponentsExist(
  machineNames: string[],
  apiService: { listComponents: () => Promise<Record<string, unknown>> },
  onProgress: () => void,
): Promise<ComponentExistsResult[]> {
  try {
    const existingComponents = await apiService.listComponents();
    const existingMachineNames = new Set(Object.keys(existingComponents));

    return machineNames.map((machineName) => {
      onProgress();
      return {
        machineName,
        exists: existingMachineNames.has(machineName),
      };
    });
  } catch (error) {
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
 */
export async function uploadComponents<T>(
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
      const componentName = path.basename(dir);
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
      try {
        const ast = parse(sourceCodeJs, {
          sourceType: 'module',
          plugins: ['jsx'],
        });
        importedJsComponents = getImportsFromAst(ast, '@/components/');
        dataDependencies = getDataDependenciesFromAst(ast);
      } catch (error) {
        p.note(chalk.red(`Error: ${error}`));
      }

      const componentPayload = createComponentPayload({
        metadata,
        machineName,
        componentName,
        sourceCodeJs,
        compiledJs,
        sourceCodeCss,
        compiledCss,
        importedJsComponents,
        dataDependencies,
      });

      prepared.push({ machineName, componentName, componentPayload });
    } catch (error) {
      const errorMessage =
        error instanceof Error ? error.message : String(error);
      failed.push({
        itemName: buildResult.itemName,
        success: false,
        details: [{ content: errorMessage }],
      });
    }
  }

  return { prepared, failed };
}

/**
 * Build, prepare, and upload components to Drupal.
 *
 * Shared by both the push and upload commands.
 */
export async function buildAndUploadComponents(
  componentsToUpload: string[],
  apiService: ApiService,
  includeGlobalCss: boolean,
  actionLabel: string = 'Uploading',
  skipBuild?: boolean,
): Promise<Result[]> {
  const results: Result[] = [];
  const spinner = p.spinner();

  let buildResults: Result[];
  if (skipBuild) {
    p.log.info('Skipping component builds');
    buildResults = componentsToUpload.map((dir) => ({
      itemName: path.basename(dir),
      success: true,
    }));
  } else {
    spinner.start('Building components');
    buildResults = [];
    for (const dir of componentsToUpload) {
      buildResults.push(await buildComponent(dir, includeGlobalCss));
    }
  }

  const successfulBuilds = buildResults.filter((build) => build.success);
  const failedBuilds = buildResults.filter((build) => !build.success);

  results.push(...failedBuilds);

  if (successfulBuilds.length === 0) {
    spinner.stop(chalk.red('All component builds failed.'));
    return results;
  }

  spinner.message(`Preparing components for ${actionLabel.toLowerCase()}`);
  const { prepared, failed: preparationFailures } =
    await prepareComponentsForUpload(successfulBuilds, componentsToUpload);

  results.push(...preparationFailures);

  if (prepared.length === 0) {
    spinner.stop(chalk.red('All component preparations failed'));
    return results;
  }

  const machineNames = prepared.map((c) => c.machineName);
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

  const uploadTasks = prepared.map((component, index) => ({
    machineName: component.machineName,
    componentPayload: component.componentPayload,
    shouldUpdate: existenceResults[index]?.exists || false,
  }));

  const uploadProgress = createProgressCallback(
    spinner,
    `${actionLabel} components`,
    uploadTasks.length,
  );

  spinner.message(`${actionLabel} components`);
  const uploadResults = await uploadComponents(
    uploadTasks,
    apiService,
    uploadProgress,
  );
  results.push(
    ...prepared.map((component, i) => {
      const uploadResult = uploadResults[i];

      return {
        itemName: component.componentName,
        success: uploadResult.success,
        details: [
          {
            content: uploadResult.success
              ? uploadResult.operation === 'update'
                ? 'Updated'
                : 'Created'
              : uploadResult.error?.message?.trim() || 'Unknown upload error',
          },
        ],
      };
    }),
  );
  spinner.stop(
    chalk.green(
      `Processed ${results.length} ${pluralizeComponent(results.length)}`,
    ),
  );
  return results;
}

/**
 * Upload the global asset library (CSS/JS) to Drupal.
 *
 * Shared by both the push and upload commands.
 */
export async function uploadGlobalAssetLibrary(
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
      const originalCss = await getGlobalCss();
      await apiService.updateGlobalAssetLibrary({
        css: { original: originalCss, compiled: globalCompiledCss },
        js: { original: classNameCandidateIndexFile, compiled: '' },
      });
      return { success: true, itemName: 'Global CSS' };
    }
    return {
      success: false,
      itemName: 'Global CSS',
      details: [
        { content: `Global CSS file not found at ${globalCompiledCssPath}.` },
      ],
    };
  } catch (error) {
    const errorMessage = error instanceof Error ? error.message : String(error);
    return {
      success: false,
      itemName: 'Global CSS',
      details: [{ content: errorMessage }],
    };
  }
}
