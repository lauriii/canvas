import { beforeEach, describe, expect, it, vi } from 'vitest';

import { bundleLocalAliasImports } from '../lib/build-local-import';
import { bundleVendorDependencies } from '../lib/build-vendor';
import { collectImports } from '../lib/import-analyzer';
import { analyzeAndBundleImports } from './analyze-and-bundle-imports';

vi.mock('../lib/import-analyzer', () => ({
  collectImports: vi.fn(),
}));

vi.mock('../lib/build-vendor', () => ({
  bundleVendorDependencies: vi.fn(),
}));

vi.mock('../lib/build-local-import', () => ({
  bundleLocalAliasImports: vi.fn(),
}));

describe('analyzeAndBundleImports', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('returns non-null empty-success results when no imports need bundling', async () => {
    vi.mocked(collectImports).mockResolvedValue({
      thirdPartyPackages: new Set(),
      aliasImports: new Map(),
      unresolvedAliasImports: new Set(),
    });

    const result = await analyzeAndBundleImports({
      entryFiles: ['/project/src/components/button/index.tsx'],
      componentDir: '/project/src/components',
      aliasBaseDir: '/project/src',
      outputDir: '/project/dist',
    });

    expect(result.vendorResult).toEqual({
      success: true,
      importMap: { imports: {} },
      bundledPackages: [],
      sharedChunks: [],
    });
    expect(result.localResult).toEqual({
      success: true,
      localImportMap: {},
      sharedChunks: [],
    });
    expect(result.sharedChunks).toEqual([]);
    expect(bundleVendorDependencies).not.toHaveBeenCalled();
    expect(bundleLocalAliasImports).not.toHaveBeenCalled();
  });

  it('returns normalized bundling results and combines shared chunks', async () => {
    vi.mocked(collectImports).mockResolvedValue({
      thirdPartyPackages: new Set(['lodash']),
      aliasImports: new Map([['@/lib/utils', '/project/src/lib/utils.ts']]),
      unresolvedAliasImports: new Set(),
    });
    vi.mocked(bundleVendorDependencies).mockResolvedValue({
      success: true,
      importMap: { imports: { lodash: './vendor/lodash-abc123.js' } },
      bundledPackages: ['lodash'],
      sharedChunks: ['./vendor/shared-abc123.js'],
    });
    vi.mocked(bundleLocalAliasImports).mockResolvedValue({
      success: true,
      localImportMap: { '@/lib/utils': './local/utils-def456.js' },
      sharedChunks: ['./local/shared-def456.js'],
    });

    const result = await analyzeAndBundleImports({
      entryFiles: ['/project/src/components/button/index.tsx'],
      componentDir: '/project/src/components',
      aliasBaseDir: '/project/src',
      outputDir: '/project/dist',
    });

    expect(result.vendorResult.importMap.imports).toEqual({
      lodash: './vendor/lodash-abc123.js',
    });
    expect(result.localResult.localImportMap).toEqual({
      '@/lib/utils': './local/utils-def456.js',
    });
    expect(result.sharedChunks).toEqual([
      './vendor/shared-abc123.js',
      './local/shared-def456.js',
    ]);
  });

  it('throws when vendor bundling fails', async () => {
    vi.mocked(collectImports).mockResolvedValue({
      thirdPartyPackages: new Set(['lodash']),
      aliasImports: new Map(),
      unresolvedAliasImports: new Set(),
    });
    vi.mocked(bundleVendorDependencies).mockRejectedValue(
      new Error('Vite failed'),
    );

    await expect(
      analyzeAndBundleImports({
        entryFiles: ['/project/src/components/button/index.tsx'],
        componentDir: '/project/src/components',
        aliasBaseDir: '/project/src',
        outputDir: '/project/dist',
      }),
    ).rejects.toThrow('Vite failed');
  });
});
