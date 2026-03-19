import { bundleLocalAliasImports } from '../lib/build-local-import';
import { bundleVendorDependencies } from '../lib/build-vendor';
import { collectImports } from '../lib/import-analyzer';

import type { LocalImportBuildResult } from '../lib/build-local-import';
import type { VendorBundleResult } from '../lib/build-vendor';
import type { CollectDependenciesResult } from '../lib/import-analyzer';

export interface AnalyzeAndBundleOptions {
  entryFiles: string[];
  componentDir: string;
  aliasBaseDir: string;
  outputDir: string;
}

export interface AnalyzeAndBundleResult {
  imports: CollectDependenciesResult;
  vendorResult: VendorBundleResult;
  localResult: LocalImportBuildResult;
  sharedChunks: string[];
}

/**
 * Analyzes component imports and bundles vendor and local alias dependencies.
 *
 * This consolidates the import analysis and bundling steps that are shared
 * between the build and push commands.
 */
export async function analyzeAndBundleImports(
  options: AnalyzeAndBundleOptions,
): Promise<AnalyzeAndBundleResult> {
  const { entryFiles, componentDir, aliasBaseDir, outputDir } = options;

  // Step 1: Collect and analyze imports from all entry files
  const imports = await collectImports(entryFiles, componentDir, aliasBaseDir);

  if (imports.unresolvedAliasImports.size > 0) {
    const unresolved = Array.from(imports.unresolvedAliasImports).sort();
    throw new Error(
      `Unresolved alias imports (${unresolved.length}): ${unresolved.join(', ')}`,
    );
  }

  // Step 2: Bundle vendor (third-party) dependencies
  let vendorResult: VendorBundleResult = {
    success: true,
    importMap: { imports: {} },
    bundledPackages: [],
    sharedChunks: [],
  };
  let localResult: LocalImportBuildResult = {
    success: true,
    localImportMap: {},
    sharedChunks: [],
  };

  // @todo: Remove the separate vite builds and just use one process
  //    to bundle everything all at once.
  if (imports.thirdPartyPackages.size > 0) {
    vendorResult = await bundleVendorDependencies(
      imports.thirdPartyPackages,
      componentDir,
      aliasBaseDir,
      outputDir,
    );
  }
  if (imports.aliasImports.size > 0) {
    // Step 3: Bundle local alias imports
    localResult = await bundleLocalAliasImports(
      imports.aliasImports,
      componentDir,
      aliasBaseDir,
      outputDir,
    );
  }
  return {
    imports,
    vendorResult,
    localResult,
    sharedChunks: [...vendorResult.sharedChunks, ...localResult.sharedChunks],
  };
}
