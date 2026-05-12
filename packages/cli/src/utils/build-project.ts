import { promises as fs } from 'node:fs';
import path from 'node:path';
import { compilePartialCss } from 'tailwindcss-in-browser';
import { parse } from '@babel/parser';
import {
  getDataDependenciesFromAst,
  getImportsFromAst,
} from '@drupal-canvas/ui/features/code-editor/utils/ast-utils';
import {
  buildCanvasComponentEntry,
  buildCanvasLocalArtifacts,
  buildCanvasVendorArtifacts,
  createCanvasDependencyMetadata,
  validateCanvasImportRoots,
} from '@drupal-canvas/vite-compat';

import { transformCss } from '../lib/transform-css';
import { buildTailwindForComponents, getGlobalCss } from './build-tailwind';
import { generateManifest } from './generate-manifest';
import {
  createComponentPayload,
  readComponentMetadata,
} from './process-component-files';
import { validateComponent } from './validate';

import type {
  DiscoveredComponent,
  DiscoveryResult,
} from '@drupal-canvas/discovery';
import type {
  CanvasDependencyMetadata,
  CanvasVendorArtifactBuildResult,
} from '@drupal-canvas/vite-compat';
import type { Component, DataDependencies } from '../types/Component';
import type { Result } from '../types/Result';
import type { Manifest } from './generate-manifest';

export interface BuiltComponent {
  machineName: string;
  componentName: string;
  componentPayload: Component;
  importedJsComponents: string[];
}

export interface CanvasProjectBuildResult {
  discoveryResult: DiscoveryResult;
  componentResults: Result[];
  builtComponents: BuiltComponent[];
  manifest: Manifest;
  manifestPath: string;
  artifactCount: number;
  vendorImportCount: number;
  localImportCount: number;
  tailwindResult: Result | null;
}

export interface CanvasProjectBuildOptions {
  projectRoot: string;
  componentDir: string;
  aliasBaseDir: string;
  outputDir: string;
  discoveryResult: DiscoveryResult;
  cleanOutputDir?: boolean;
  buildTailwind?: boolean;
  requireJsEntries?: boolean;
  useLocalGlobalCss?: boolean;
}

function getOutputBaseName(component: DiscoveredComponent): string {
  return component.kind === 'index' ? 'index' : component.name;
}

function mergeDependencyMetadata(
  target: CanvasDependencyMetadata,
  source: CanvasDependencyMetadata,
): void {
  for (const packageName of source.thirdPartyPackages) {
    target.thirdPartyPackages.add(packageName);
  }
  for (const [specifier, filePath] of source.localAliasImports) {
    target.localAliasImports.set(specifier, filePath);
  }
  for (const [specifier, filePath] of source.assetImports) {
    target.assetImports.set(specifier, filePath);
  }
  for (const specifier of source.unresolvedAliasImports) {
    target.unresolvedAliasImports.add(specifier);
  }
}

function parseBackendMetadata(sourceCodeJs: string): {
  importedJsComponents: string[];
  dataDependencies: DataDependencies;
} {
  const ast = parse(sourceCodeJs, {
    sourceType: 'module',
    plugins: ['jsx', 'typescript'],
  });

  return {
    importedJsComponents: getImportsFromAst(ast, '@/components/'),
    dataDependencies: getDataDependenciesFromAst(ast),
  };
}

async function prepareComponentBuild(options: {
  component: DiscoveredComponent;
  outputDir: string;
  globalSourceCodeCss: string;
  requireJsEntries: boolean;
}): Promise<
  | {
      result: Result;
      sourceCodeJs: string;
      sourceCodeCss: string;
      compiledCss: string;
      distDir: string;
      outputBaseName: string;
      importedJsComponents: string[];
      dataDependencies: DataDependencies;
    }
  | { result: Result }
> {
  const { component, outputDir, globalSourceCodeCss, requireJsEntries } =
    options;
  const result: Result = {
    itemName: component.name,
    success: true,
    details: [],
  };
  const outputBaseName = getOutputBaseName(component);
  const distDir = path.join(outputDir, 'components', component.name);

  const validationResult = await validateComponent(
    path.dirname(component.metadataPath),
  );
  if (!validationResult.success) {
    return { result: validationResult };
  }

  try {
    await fs.mkdir(distDir, { recursive: true });
    await fs.copyFile(
      component.metadataPath,
      path.join(distDir, path.basename(component.metadataPath)),
    );
  } catch (error) {
    return {
      result: {
        ...result,
        success: false,
        details: [
          {
            heading: 'Error while preparing component output',
            content: error instanceof Error ? error.message : String(error),
          },
        ],
      },
    };
  }

  if (!component.jsEntryPath) {
    if (!requireJsEntries) {
      return {
        result,
        sourceCodeJs: '',
        sourceCodeCss: '',
        compiledCss: '',
        distDir,
        outputBaseName,
        importedJsComponents: [],
        dataDependencies: {},
      };
    }

    return {
      result: {
        ...result,
        success: false,
        details: [
          {
            heading: 'Missing JavaScript entry',
            content: `No JS/TS entry point found for ${component.name}.`,
          },
        ],
      },
    };
  }

  try {
    const sourceCodeJs = await fs.readFile(component.jsEntryPath, 'utf-8');
    let importedJsComponents: string[] = [];
    let dataDependencies: DataDependencies = {};
    try {
      const metadata = parseBackendMetadata(sourceCodeJs);
      importedJsComponents = metadata.importedJsComponents;
      dataDependencies = metadata.dataDependencies;
    } catch {
      result.warnings = [
        ...(result.warnings ?? []),
        'Could not parse component imports for backend metadata.',
      ];
    }

    let sourceCodeCss = '';
    let compiledCss = '';
    if (component.cssEntryPath) {
      sourceCodeCss = await fs.readFile(component.cssEntryPath, 'utf-8');
      compiledCss = await transformCss(
        await compilePartialCss(sourceCodeCss, globalSourceCodeCss),
      );
      await fs.writeFile(
        path.join(distDir, `${outputBaseName}.css`),
        compiledCss,
      );
    }

    return {
      result,
      sourceCodeJs,
      sourceCodeCss,
      compiledCss,
      distDir,
      outputBaseName,
      importedJsComponents,
      dataDependencies,
    };
  } catch (error) {
    return {
      result: {
        ...result,
        success: false,
        details: [
          {
            heading: 'Error while preparing component files',
            content: error instanceof Error ? error.message : String(error),
          },
        ],
      },
    };
  }
}

async function buildComponentPayload(options: {
  component: DiscoveredComponent;
  sourceCodeJs: string;
  sourceCodeCss: string;
  compiledCss: string;
  distDir: string;
  outputBaseName: string;
  importedJsComponents: string[];
  dataDependencies: DataDependencies;
}): Promise<Component> {
  const compiledJs = await fs.readFile(
    path.join(options.distDir, `${options.outputBaseName}.js`),
    'utf-8',
  );
  const metadata = await readComponentMetadata(options.component.metadataPath);
  if (!metadata) {
    throw new Error(`Invalid metadata file for ${options.component.name}.`);
  }

  const machineName =
    metadata.machineName ||
    options.component.name.toLowerCase().replace(/[^a-z0-9_-]/g, '_');

  return createComponentPayload({
    metadata,
    machineName,
    componentName: options.component.name,
    sourceCodeJs: options.sourceCodeJs,
    compiledJs,
    sourceCodeCss: options.sourceCodeCss,
    compiledCss: options.compiledCss,
    importedJsComponents: options.importedJsComponents,
    dataDependencies: options.dataDependencies,
  });
}

function emptyVendorResult(): CanvasVendorArtifactBuildResult {
  return {
    importMap: { imports: {} },
    bundledPackages: [],
    sharedChunks: [],
  };
}

export async function buildCanvasProject(
  options: CanvasProjectBuildOptions,
): Promise<CanvasProjectBuildResult> {
  validateCanvasImportRoots({
    hostRoot: options.projectRoot,
    aliasBaseDir: options.aliasBaseDir,
    componentDir: options.componentDir,
  });

  const outputDir = path.resolve(options.outputDir);
  if (options.cleanOutputDir) {
    await fs.rm(outputDir, { recursive: true, force: true });
  }

  const globalSourceCodeCss = await getGlobalCss(
    options.useLocalGlobalCss ?? true,
  );
  const dependencyMetadata = createCanvasDependencyMetadata();
  const componentResults: Result[] = [];
  const builtComponents: BuiltComponent[] = [];

  for (const component of options.discoveryResult.components) {
    const prepared = await prepareComponentBuild({
      component,
      outputDir,
      globalSourceCodeCss,
      requireJsEntries: options.requireJsEntries ?? false,
    });

    if (!('sourceCodeJs' in prepared)) {
      componentResults.push(prepared.result);
      continue;
    }

    let result = prepared.result;
    if (component.jsEntryPath) {
      try {
        const metadata = await buildCanvasComponentEntry({
          projectRoot: options.projectRoot,
          aliasBaseDir: options.aliasBaseDir,
          entry: {
            entryPath: component.jsEntryPath,
            outputDir: prepared.distDir,
            outputBaseName: prepared.outputBaseName,
          },
        });
        mergeDependencyMetadata(dependencyMetadata, metadata);

        const componentPayload = await buildComponentPayload({
          component,
          sourceCodeJs: prepared.sourceCodeJs,
          sourceCodeCss: prepared.sourceCodeCss,
          compiledCss: prepared.compiledCss,
          distDir: prepared.distDir,
          outputBaseName: prepared.outputBaseName,
          importedJsComponents: prepared.importedJsComponents,
          dataDependencies: prepared.dataDependencies,
        });

        builtComponents.push({
          machineName: componentPayload.machineName,
          componentName: component.name,
          componentPayload,
          importedJsComponents: prepared.importedJsComponents,
        });
      } catch (error) {
        result = {
          ...result,
          success: false,
          details: [
            {
              heading: 'Error while transforming JavaScript',
              content: error instanceof Error ? error.message : String(error),
            },
          ],
        };
      }
    }

    componentResults.push(result);
  }

  if (dependencyMetadata.unresolvedAliasImports.size > 0) {
    const unresolved = [...dependencyMetadata.unresolvedAliasImports].sort();
    throw new Error(
      `Unresolved alias imports (${unresolved.length}): ${unresolved.join(', ')}`,
    );
  }

  const localResult = await buildCanvasLocalArtifacts({
    projectRoot: options.projectRoot,
    aliasBaseDir: options.aliasBaseDir,
    outputDir,
    localImports: dependencyMetadata.localAliasImports,
    assetImports: dependencyMetadata.assetImports,
  });

  const vendorPackages = new Set([
    ...dependencyMetadata.thirdPartyPackages,
    ...localResult.thirdPartyPackages,
  ]);
  const vendorResult =
    vendorPackages.size > 0
      ? await buildCanvasVendorArtifacts({
          projectRoot: options.projectRoot,
          aliasBaseDir: options.aliasBaseDir,
          outputDir,
          packages: vendorPackages,
        })
      : emptyVendorResult();

  const manifestResult = await generateManifest({
    outputDir,
    vendorImportMap: vendorResult.importMap,
    localImportMap: localResult.localImportMap,
    sharedChunks: [...vendorResult.sharedChunks, ...localResult.sharedChunks],
  });
  if (!manifestResult.success) {
    throw new Error(manifestResult.error ?? 'Failed to generate manifest.');
  }
  const { manifest, manifestPath } = manifestResult;

  let tailwindResult: Result | null = null;
  if (options.buildTailwind) {
    tailwindResult = await buildTailwindForComponents(
      options.discoveryResult.components,
      options.useLocalGlobalCss ?? true,
      outputDir,
    );
  }

  return {
    discoveryResult: options.discoveryResult,
    componentResults,
    builtComponents,
    manifest,
    manifestPath,
    artifactCount:
      Object.keys(manifest.vendor).length +
      Object.keys(manifest.local).length +
      (manifest.shared?.length ?? 0),
    vendorImportCount: vendorResult.bundledPackages.length,
    localImportCount: Object.keys(localResult.localImportMap).length,
    tailwindResult,
  };
}
