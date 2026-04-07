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
import { sortByDependencies } from './dependency-sort';
import {
  createComponentPayload,
  processComponentFiles,
} from './process-component-files';
import { createProgressCallback, processInPool } from './request-pool';
import { fileExists } from './utils';

import type { DiscoveredComponent } from '@drupal-canvas/discovery';
import type { DataDependencies } from '@drupal-canvas/ui/types/CodeComponent';
import type { ApiService } from '../services/api.js';
import type { Component } from '../types/Component.js';
import type { Result } from '../types/Result.js';

type ComponentOperation = 'create' | 'update' | 'delete';

type ComponentUploadTask =
  | {
      machineName: string;
      operation: 'create' | 'update';
      componentPayload: Component;
      // Dependencies needed to sort creates in dependency-first order
      importedJsComponents: string[];
    }
  | {
      machineName: string;
      operation: 'delete';
      // Dependencies needed to sort deletes in reverse order (dependents first)
      // to avoid "component still referenced" errors from backend
      importedJsComponents: string[];
    };

interface ComponentUploadResult {
  machineName: string;
  success: boolean;
  operation: ComponentOperation;
  error?: Error;
}

interface PreparedComponent {
  machineName: string;
  componentName: string;
  componentPayload: ReturnType<typeof createComponentPayload>;
  importedJsComponents: string[];
}

/**
 * Determine the operation for each component (create, update, or delete)
 * and build upload tasks with payloads attached.
 */
export async function buildComponentUploadTasks(
  preparedByName: Map<string, PreparedComponent>,
  apiService: { listComponents: () => Promise<Record<string, unknown>> },
  onProgress: () => void,
): Promise<ComponentUploadTask[]> {
  const existingComponents = await apiService.listComponents();
  const remoteNames = new Set(Object.keys(existingComponents));

  const tasks: ComponentUploadTask[] = [];
  for (const [machineName, prepared] of preparedByName.entries()) {
    onProgress();
    if (remoteNames.has(machineName)) {
      tasks.push({
        machineName,
        operation: 'update',
        componentPayload: prepared.componentPayload,
        importedJsComponents: prepared.importedJsComponents,
      });
    } else {
      tasks.push({
        machineName,
        operation: 'create',
        componentPayload: prepared.componentPayload,
        importedJsComponents: prepared.importedJsComponents,
      });
    }
  }

  // Parse dependencies for components being deleted during push.
  // When deleting components, we need to know their dependencies to delete them
  // in reverse order (dependents first) to avoid server errors about components still
  // being referenced.
  //
  // Example: If 'card' imports 'button', both being deleted:
  //   - Must delete 'card' first (the dependent)
  //   - Then delete 'button' (the dependency)
  for (const name of remoteNames) {
    if (!preparedByName.has(name)) {
      const serverComponent = existingComponents[name] as Component;

      // Parse imports from server component's source code (no local file exists)
      let importedJsComponents: string[] = [];
      try {
        const ast = parse(serverComponent.sourceCodeJs, {
          sourceType: 'module',
          plugins: ['jsx', 'typescript'],
        });
        importedJsComponents = getImportsFromAst(ast, '@/components/');
      } catch (error) {
        p.note(chalk.red(`Error: ${error}`));
      }
      tasks.push({
        machineName: name,
        operation: 'delete',
        importedJsComponents,
      });
    }
  }

  return tasks;
}

/**
 * Upload (create, update, or delete) multiple components.
 *
 * Creates: Processed in dependency-first waves (Icon → Button → Card)
 *   - Within each wave, tasks run in parallel
 *   - Waves run sequentially to respect dependencies
 *
 * Updates: Processed in parallel (components already exist, order doesn't matter)
 *
 * Deletes: Processed in reverse dependency waves (Card → Button → Icon)
 *   - Dependents must be deleted before dependencies
 *   - Prevents "component still referenced" backend errors
 */
export async function uploadComponents(
  uploadTasks: ComponentUploadTask[],
  apiService: Pick<
    ApiService,
    'createComponent' | 'updateComponent' | 'deleteComponent'
  >,
  onProgress: () => void,
): Promise<ComponentUploadResult[]> {
  const createTasks = uploadTasks.filter((t) => t.operation === 'create');
  const updateTasks = uploadTasks.filter((t) => t.operation === 'update');
  const deleteTasks = uploadTasks.filter((t) => t.operation === 'delete');

  const results: ComponentUploadResult[] = [];
  const taskIndexMap = new Map<string, number>();
  uploadTasks.forEach((task, index) => {
    taskIndexMap.set(`${task.operation}:${task.machineName}`, index);
  });

  // Process creates in dependency waves. Components that import other components
  // must be created after their dependencies exist on the server. We group
  // components into waves by dependency level - each wave runs in parallel,
  // but waves run sequentially.
  //
  // Example: product-card → button → icon, badge (no deps)
  //   Wave 1: [icon, badge]   ← run in parallel
  //   Wave 2: [button]        ← waits for wave 1
  //   Wave 3: [product-card]   ← waits for wave 2
  if (createTasks.length > 0) {
    const waves =
      createTasks.length > 1
        ? sortByDependencies(createTasks, (task) => task.importedJsComponents)
        : [createTasks];

    for (let i = 0; i < waves.length; i++) {
      const wave = waves[i];
      const waveResults = await processTaskBatch(wave, apiService, onProgress);
      results.push(...waveResults);
    }
  }

  // Process updates in parallel
  if (updateTasks.length > 0) {
    const updateResults = await processTaskBatch(
      updateTasks,
      apiService,
      onProgress,
    );
    results.push(...updateResults);
  }

  // Process deletes in reverse dependency waves.
  // Components that import others must be deleted before their dependencies
  // (opposite of creates). Otherwise, backend rejects: "component still referenced".
  //
  // Example: card → button → icon
  //   Wave 1: [card]    ← deletes first (imports button)
  //   Wave 2: [button]  ← waits for wave 1 (imports icon)
  //   Wave 3: [icon]    ← waits for wave 2 (no imports)
  if (deleteTasks.length > 0) {
    const waves =
      deleteTasks.length > 1
        ? sortByDependencies(deleteTasks, (task) => task.importedJsComponents)
        : [deleteTasks];

    // sortByDependencies returns dependency-first order, but for deletes we want
    // the opposite. Reverse both wave order and items within each wave.
    waves.reverse();
    waves.forEach((wave) => wave.reverse());

    for (let i = 0; i < waves.length; i++) {
      const wave = waves[i];
      const waveResults = await processTaskBatch(wave, apiService, onProgress);
      results.push(...waveResults);
    }
  }

  // Sort results back to original task order for consistent output
  return results.sort((a, b) => {
    const indexA = taskIndexMap.get(`${a.operation}:${a.machineName}`) ?? 0;
    const indexB = taskIndexMap.get(`${b.operation}:${b.machineName}`) ?? 0;
    return indexA - indexB;
  });
}
/**
 * Execute a single upload task with fallback handling.
 */
async function executeUploadTask(
  task: ComponentUploadTask,
  apiService: Pick<
    ApiService,
    'createComponent' | 'updateComponent' | 'deleteComponent'
  >,
): Promise<ComponentUploadResult> {
  const execute = (raw: boolean) => {
    switch (task.operation) {
      case 'create':
        return apiService.createComponent(task.componentPayload, raw);
      case 'update':
        return apiService.updateComponent(
          task.machineName,
          task.componentPayload,
        );
      case 'delete':
        return apiService.deleteComponent(task.machineName);
    }
  };

  let error: Error | undefined;
  try {
    await execute(true);
  } catch {
    try {
      await execute(false);
    } catch (fallbackError) {
      error =
        fallbackError instanceof Error
          ? fallbackError
          : new Error(String(fallbackError));
    }
  }

  return {
    machineName: task.machineName,
    success: !error,
    operation: task.operation,
    error,
  };
}

/**
 * Process a batch of tasks in parallel and collect results.
 */
async function processTaskBatch(
  uploadTasks: ComponentUploadTask[],
  apiService: Pick<
    ApiService,
    'createComponent' | 'updateComponent' | 'deleteComponent'
  >,
  onProgress: () => void,
): Promise<ComponentUploadResult[]> {
  const results = await processInPool(uploadTasks, async (task) => {
    const result = await executeUploadTask(task, apiService);
    onProgress();
    return result;
  });

  return results.map((result, index) => {
    if (result.success && result.result) {
      return result.result;
    }
    return {
      machineName: uploadTasks[index].machineName,
      success: false,
      operation: uploadTasks[index].operation,
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

      prepared.push({
        machineName,
        componentName,
        componentPayload,
        importedJsComponents,
      });
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

  const preparedByName = new Map(prepared.map((c) => [c.machineName, c]));
  const existenceProgress = createProgressCallback(
    spinner,
    'Checking component existence',
    preparedByName.size,
  );

  spinner.message('Checking component operations');
  const uploadTasks = await buildComponentUploadTasks(
    preparedByName,
    apiService,
    existenceProgress,
  );

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

  const operationLabels: Record<ComponentOperation, string> = {
    create: 'Created',
    update: chalk.cyan('Updated'),
    delete: chalk.dim('Deleted'),
  };
  for (let i = 0; i < uploadResults.length; i++) {
    const uploadResult = uploadResults[i];

    results.push({
      itemName: prepared[i]?.componentName ?? uploadResult.machineName,
      success: uploadResult.success,
      details: [
        {
          content: uploadResult.success
            ? operationLabels[uploadResult.operation]
            : uploadResult.error?.message?.trim() || 'Unknown upload error',
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
