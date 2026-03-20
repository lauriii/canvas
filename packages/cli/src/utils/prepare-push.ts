import fs from 'fs/promises';
import path from 'path';
import chalk from 'chalk';
import { parse } from '@babel/parser';
import * as p from '@clack/prompts';
import {
  getDataDependenciesFromAst,
  getImportsFromAst,
} from '@drupal-canvas/ui/features/code-editor/utils/ast-utils';

import { buildComponent } from './build-component';
import { getGlobalCss } from './build-tailwind.js';
import { pluralizeComponent } from './command-helpers';
import {
  createComponentPayload,
  processComponentFiles,
} from './process-component-files';
import { createProgressCallback, processInPool } from './request-pool';
import { fileExists } from './utils';

import type { DiscoveredComponent } from '@drupal-canvas/discovery';
import type { DataDependencies } from '@drupal-canvas/ui/types/CodeComponent';
import type { ApiService } from '../services/api.js';
import type { Result } from '../types/Result.js';

interface ComponentExistsResult {
  machineName: string;
  exists: boolean;
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
  const existingComponents = await apiService.listComponents();
  const existingMachineNames = new Set(Object.keys(existingComponents));

  return machineNames.map((machineName) => {
    onProgress();
    return {
      machineName,
      exists: existingMachineNames.has(machineName),
    };
  });
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
      operation: uploadTasks[result.index]?.shouldUpdate ? 'update' : 'create',
      error: result.error || new Error('Unknown error during upload'),
    };
  });
}

async function prepareComponentsForUpload(
  successfulBuilds: Result[],
  componentsToUpload: DiscoveredComponent[],
): Promise<{ prepared: PreparedComponent[]; failed: Result[] }> {
  const prepared: PreparedComponent[] = [];
  const failed: Result[] = [];

  for (const buildResult of successfulBuilds) {
    const component = buildResult.itemName
      ? componentsToUpload.find((c) => c.name === buildResult.itemName)
      : undefined;

    if (!component) continue;

    try {
      const componentName = component.name;
      const { sourceCodeJs, compiledJs, sourceCodeCss, compiledCss, metadata } =
        await processComponentFiles(
          component.directory,
          componentName,
          component.kind,
        );
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
          plugins: ['jsx', 'typescript'],
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
export async function buildAndPushComponents(
  componentsToUpload: DiscoveredComponent[],
  apiService: ApiService,
  includeGlobalCss: boolean,
  actionLabel: string = 'Uploading',
): Promise<Result[]> {
  const results: Result[] = [];
  const spinner = p.spinner();

  const buildResults: Result[] = [];
  spinner.start('Building components');
  for (const component of componentsToUpload) {
    buildResults.push(await buildComponent(component, includeGlobalCss));
  }

  const successfulBuilds = buildResults.filter((build) => build.success);
  const failedBuilds = buildResults.filter((build) => !build.success);

  if (failedBuilds.length > 0) {
    spinner.stop(
      chalk.red(
        `Build failed for ${failedBuilds.length} ${pluralizeComponent(failedBuilds.length)}.`,
      ),
    );
    return buildResults;
  }

  if (successfulBuilds.length === 0) {
    spinner.stop(chalk.red('All component builds failed.'));
    return failedBuilds;
  }

  spinner.message(`Preparing components for ${actionLabel.toLowerCase()}`);
  const { prepared, failed: preparationFailures } =
    await prepareComponentsForUpload(successfulBuilds, componentsToUpload);

  if (preparationFailures.length > 0) {
    spinner.stop(
      chalk.red(
        `Preparation failed for ${preparationFailures.length} ${pluralizeComponent(preparationFailures.length)}.`,
      ),
    );
    return [...successfulBuilds, ...preparationFailures];
  }

  if (prepared.length === 0) {
    spinner.stop(chalk.red('No components were prepared for upload.'));
    return [...successfulBuilds, ...preparationFailures];
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

  const failedUploads = uploadResults
    .map((uploadResult, index) => {
      if (uploadResult.success) {
        return null;
      }
      const componentName =
        prepared[index]?.componentName || uploadResult.machineName || 'unknown';
      const message =
        uploadResult.error?.message?.trim() || 'Unknown upload error';
      return `${componentName} (${message})`;
    })
    .filter((value): value is string => Boolean(value));

  if (failedUploads.length > 0) {
    throw new Error(
      `Component upload failed for ${failedUploads.length} ${pluralizeComponent(failedUploads.length)}: ${failedUploads.join(', ')}`,
    );
  }

  for (let i = 0; i < prepared.length; i++) {
    const component = prepared[i];
    const uploadResult = uploadResults[i];

    results.push({
      itemName: component.componentName,
      success: true,
      details: [
        {
          content: uploadResult.operation === 'update' ? 'Updated' : 'Created',
        },
      ],
    });
  }

  spinner.stop(
    chalk.green(
      `Processed ${results.length} ${pluralizeComponent(results.length)}`,
    ),
  );
  return results;
}

/**
 * Upload the global asset library (CSS/JS) to Drupal.
 */
export async function uploadGlobalAssetLibrary(
  apiService: ApiService,
  outputDir: string,
): Promise<Result> {
  try {
    const globalCompiledCssPath = path.join(outputDir, 'index.css');
    const globalCompiledCssExists = await fileExists(globalCompiledCssPath);
    if (globalCompiledCssExists) {
      const globalCompiledCss = await fs.readFile(
        path.join(outputDir, 'index.css'),
        'utf-8',
      );
      const classNameCandidateIndexFile = await fs.readFile(
        path.join(outputDir, 'index.js'),
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
