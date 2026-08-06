import fs from 'fs/promises';
import os from 'os';
import path from 'path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { generateManifest } from '../utils/generate-manifest';
import {
  prepareGlobalAssetLibraryUpdate,
  pushBuiltComponents,
} from '../utils/prepare-push';
import {
  formatDiscoveryWarning,
  formatDiscoveryWarningReport,
  getSyncExclusionMessage,
  getSyncExclusionSource,
  syncManifestArtifacts,
  updateGlobalAssetLibraryForPush,
  uploadManifestArtifacts,
} from './push';

import type { ApiService } from '../services/api';

vi.mock('@clack/prompts', () => ({
  spinner: vi.fn(() => ({
    start: vi.fn(),
    stop: vi.fn(),
    message: vi.fn(),
  })),
  note: vi.fn(),
}));

vi.mock('@drupal-canvas/ui/features/code-editor/utils/ast-utils', () => ({
  getDataDependenciesFromAst: vi.fn(() => ({})),
  getImportsFromAst: vi.fn(() => []),
}));

vi.mock('tailwindcss-in-browser', () => ({
  compilePartialCss: vi.fn(async (source: string) => source),
}));

vi.mock('../utils/build-tailwind', () => ({
  buildTailwindForComponents: vi.fn(),
  getGlobalCss: vi.fn(async () => 'body {}'),
}));

function mockApiService(): ApiService {
  return {
    listComponents: vi.fn(),
    createComponent: vi.fn(),
    updateComponent: vi.fn(),
    deleteComponent: vi.fn(),
  } as unknown as ApiService;
}

describe('push sync exclusion messages', () => {
  const pageOptions = {
    noFlag: '--no-pages',
    includeFlag: '--include-pages',
    envName: 'CANVAS_INCLUDE_PAGES',
    configPath: 'sync.pages',
  };

  it('identifies --no-* flags as the exclusion source', () => {
    expect(getSyncExclusionSource(false, undefined, undefined)).toBe('flag');
    expect(getSyncExclusionMessage('pages', 'flag', pageOptions)).toBe(
      'Local pages were found but excluded by --no-pages. Remove that flag to push them.',
    );
  });

  it('identifies deprecated --include-*=false flags as the exclusion source', () => {
    expect(getSyncExclusionSource(undefined, false, undefined)).toBe(
      'deprecated-flag',
    );
    expect(
      getSyncExclusionMessage('pages', 'deprecated-flag', pageOptions),
    ).toBe(
      'Local pages were found but excluded by deprecated --include-pages=false. Remove that flag, or use --no-pages when you want to exclude them.',
    );
  });

  it('identifies deprecated CANVAS_INCLUDE_*=false env vars as the exclusion source', () => {
    expect(getSyncExclusionSource(undefined, undefined, 'false')).toBe('env');
    expect(getSyncExclusionMessage('pages', 'env', pageOptions)).toBe(
      'Local pages were found but excluded by deprecated CANVAS_INCLUDE_PAGES=false. Remove that environment variable, or set "sync.pages" to true in canvas.config.json to push them.',
    );
  });

  it('falls back to canvas.config.json as the exclusion source', () => {
    expect(getSyncExclusionSource(undefined, undefined, undefined)).toBe(
      'config',
    );
    expect(getSyncExclusionMessage('pages', 'config', pageOptions)).toBe(
      'Local pages were found but excluded by "sync.pages": false in canvas.config.json. Set it to true to push them.',
    );
  });

  it('gives CLI options precedence over deprecated environment variables', () => {
    expect(getSyncExclusionSource(false, undefined, 'false')).toBe('flag');
    expect(getSyncExclusionSource(undefined, false, 'false')).toBe(
      'deprecated-flag',
    );
  });
});

describe('push discovery warning output', () => {
  const missingJsWarning = {
    code: 'missing_js_entry',
    message: 'Missing JavaScript entry file for pricing-table.component.yml.',
    path: path.join(
      process.cwd(),
      'src/components/pricing-table.component.yml',
    ),
  } as const;

  it('formats discovery warnings with their relative location', () => {
    expect(formatDiscoveryWarning(missingJsWarning)).toBe(
      'Missing JavaScript entry file for pricing-table.component.yml. (src/components/pricing-table.component.yml)',
    );
  });

  it('formats discovery warnings as an inline warning report', () => {
    expect(formatDiscoveryWarningReport([missingJsWarning])).toContain(
      [
        'Warnings',
        '  ! Missing JavaScript entry file for pricing-table.component.yml. (src/components/pricing-table.component.yml)',
      ].join('\n'),
    );
  });
});

describe('Push component dependencies', () => {
  let tmpDir: string;

  beforeEach(async () => {
    tmpDir = await fs.mkdtemp(path.join(os.tmpdir(), 'push-manifest-test-'));
  });

  afterEach(async () => {
    await fs.rm(tmpDir, { recursive: true, force: true });
  });

  it('uploads component dependencies, syncs the dependency map, and verifies temp files exist', async () => {
    const outputDir = path.join(tmpDir, 'dist');
    await fs.mkdir(path.join(outputDir, 'vendor'), { recursive: true });
    await fs.mkdir(path.join(outputDir, 'local'), { recursive: true });

    await fs.writeFile(
      path.join(outputDir, 'vendor/lodash-abc123.js'),
      'export default {}',
      'utf-8',
    );
    await fs.writeFile(
      path.join(outputDir, 'local/utils-def456.js'),
      'export const cn = () => "";',
      'utf-8',
    );
    await fs.writeFile(
      path.join(outputDir, 'vendor/chunk-shared-ghi789.js'),
      'export const chunk = true;',
      'utf-8',
    );

    await generateManifest({
      outputDir,
      vendorImportMap: { imports: { lodash: './vendor/lodash-abc123.js' } },
      localImportMap: { '@/lib/utils': './local/utils-def456.js' },
      sharedChunks: ['./vendor/chunk-shared-ghi789.js'],
    });

    const uploadArtifact = vi.fn(async (filename: string) => ({
      uri: `public://canvas/artifacts/${filename}`,
      fid: 1,
    }));
    const syncManifest = vi.fn().mockResolvedValue({ ok: true });

    const result = await syncManifestArtifacts(outputDir, {
      apiService: { uploadArtifact, syncManifest },
      createSpinner: () => ({
        start: vi.fn(),
        stop: vi.fn(),
        message: vi.fn(),
      }),
      logInfo: vi.fn(),
    });

    expect(uploadArtifact).toHaveBeenCalledTimes(3);
    expect(syncManifest).toHaveBeenCalledTimes(1);
    expect(syncManifest).toHaveBeenCalledWith({
      vendor: [
        {
          name: 'lodash',
          uri: 'public://canvas/artifacts/lodash-abc123.js',
        },
      ],
      local: [
        {
          name: '@/lib/utils',
          uri: 'public://canvas/artifacts/utils-def456.js',
        },
      ],
      shared: [
        {
          name: './vendor/chunk-shared-ghi789.js',
          uri: 'public://canvas/artifacts/chunk-shared-ghi789.js',
        },
      ],
    });
    expect(result.artifactCount).toBe(3);

    await expect(
      fs.access(path.join(outputDir, 'canvas-manifest.json')),
    ).resolves.toBeUndefined();
    await expect(
      fs.access(path.join(outputDir, 'vendor/lodash-abc123.js')),
    ).resolves.toBeUndefined();
    await expect(
      fs.access(path.join(outputDir, 'local/utils-def456.js')),
    ).resolves.toBeUndefined();
    await expect(
      fs.access(path.join(outputDir, 'vendor/chunk-shared-ghi789.js')),
    ).resolves.toBeUndefined();
  });

  it('uploads duplicate manifest artifact files once and reuses the URI', async () => {
    const outputDir = path.join(tmpDir, 'dist');
    await fs.mkdir(path.join(outputDir, 'local'), { recursive: true });

    await fs.writeFile(
      path.join(outputDir, 'local/hero-abc123.webp'),
      'webp fixture',
      'utf-8',
    );

    await generateManifest({
      outputDir,
      vendorImportMap: { imports: {} },
      localImportMap: {
        '@/components/card/hero.webp': './local/hero-abc123.webp',
        '@/components/local-image-example/image-1.webp':
          './local/hero-abc123.webp',
      },
      sharedChunks: [],
    });

    const uploadArtifact = vi.fn(async (filename: string) => ({
      uri: `public://canvas/artifacts/${filename}`,
      fid: 1,
    }));
    const syncManifest = vi.fn().mockResolvedValue({ ok: true });

    const result = await syncManifestArtifacts(outputDir, {
      apiService: { uploadArtifact, syncManifest },
      createSpinner: () => ({
        start: vi.fn(),
        stop: vi.fn(),
        message: vi.fn(),
      }),
      logInfo: vi.fn(),
    });

    expect(uploadArtifact).toHaveBeenCalledTimes(1);
    expect(uploadArtifact).toHaveBeenCalledWith(
      'hero-abc123.webp',
      Buffer.from('webp fixture'),
    );
    expect(syncManifest).toHaveBeenCalledWith({
      vendor: [],
      local: [
        {
          name: '@/components/card/hero.webp',
          uri: 'public://canvas/artifacts/hero-abc123.webp',
        },
        {
          name: '@/components/local-image-example/image-1.webp',
          uri: 'public://canvas/artifacts/hero-abc123.webp',
        },
      ],
      shared: [],
    });
    expect(result.artifactCount).toBe(2);
  });

  it('carries localSources path onto all local entries and source onto text modules only', async () => {
    const outputDir = path.join(tmpDir, 'dist');
    await fs.mkdir(path.join(outputDir, 'vendor'), { recursive: true });
    await fs.mkdir(path.join(outputDir, 'local'), { recursive: true });

    await fs.writeFile(
      path.join(outputDir, 'vendor/lodash-abc123.js'),
      'export default {}',
      'utf-8',
    );
    await fs.writeFile(
      path.join(outputDir, 'local/utils-def456.js'),
      'export const cn = () => "";',
      'utf-8',
    );
    await fs.writeFile(
      path.join(outputDir, 'local/poster-ghi789.webp'),
      'webp fixture',
      'utf-8',
    );

    await generateManifest({
      outputDir,
      vendorImportMap: { imports: { lodash: './vendor/lodash-abc123.js' } },
      localImportMap: {
        '@/lib/utils': './local/utils-def456.js',
        '@/assets/poster.webp': './local/poster-ghi789.webp',
      },
      localSources: {
        '@/lib/utils': {
          path: 'src/lib/utils.ts',
          source: 'export const cn = () => "";\n',
        },
        '@/assets/poster.webp': { path: 'src/assets/poster.webp' },
      },
      sharedChunks: [],
    });

    const uploadArtifact = vi.fn(async (filename: string) => ({
      uri: `public://canvas/artifacts/${filename}`,
      fid: 1,
    }));
    const syncManifest = vi.fn().mockResolvedValue({ ok: true });

    await syncManifestArtifacts(outputDir, {
      apiService: { uploadArtifact, syncManifest },
      createSpinner: () => ({
        start: vi.fn(),
        stop: vi.fn(),
        message: vi.fn(),
      }),
      logInfo: vi.fn(),
    });

    expect(syncManifest).toHaveBeenCalledWith({
      // Vendor entries stay {name, uri} — no path/source.
      vendor: [
        {
          name: 'lodash',
          uri: 'public://canvas/artifacts/lodash-abc123.js',
        },
      ],
      // Sorted by specifier: assets before lib.
      local: [
        // Binary asset: path only, no source.
        {
          name: '@/assets/poster.webp',
          uri: 'public://canvas/artifacts/poster-ghi789.webp',
          path: 'src/assets/poster.webp',
        },
        // Text module: path + verbatim source.
        {
          name: '@/lib/utils',
          uri: 'public://canvas/artifacts/utils-def456.js',
          path: 'src/lib/utils.ts',
          source: 'export const cn = () => "";\n',
        },
      ],
      shared: [],
    });
  });

  it('uploads component dependencies without syncing the dependency map', async () => {
    const outputDir = path.join(tmpDir, 'dist');
    await fs.mkdir(path.join(outputDir, 'vendor'), { recursive: true });
    await fs.mkdir(path.join(outputDir, 'local'), { recursive: true });

    await fs.writeFile(
      path.join(outputDir, 'vendor/lodash-abc123.js'),
      'export default {}',
      'utf-8',
    );
    await fs.writeFile(
      path.join(outputDir, 'local/utils-def456.js'),
      'export const cn = () => "";',
      'utf-8',
    );

    await generateManifest({
      outputDir,
      vendorImportMap: { imports: { lodash: './vendor/lodash-abc123.js' } },
      localImportMap: { '@/lib/utils': './local/utils-def456.js' },
      sharedChunks: [],
    });

    const uploadArtifact = vi.fn(async (filename: string) => ({
      uri: `public://canvas/artifacts/${filename}`,
      fid: 1,
    }));

    const result = await uploadManifestArtifacts(outputDir, {
      apiService: { uploadArtifact },
      createSpinner: () => ({
        start: vi.fn(),
        stop: vi.fn(),
        message: vi.fn(),
      }),
    });

    expect(uploadArtifact).toHaveBeenCalledTimes(2);
    expect(result).toEqual({
      artifactCount: 2,
      groupedManifest: {
        vendor: [
          {
            name: 'lodash',
            uri: 'public://canvas/artifacts/lodash-abc123.js',
          },
        ],
        local: [
          {
            name: '@/lib/utils',
            uri: 'public://canvas/artifacts/utils-def456.js',
          },
        ],
        shared: [],
        bundledSources: [],
      },
    });
  });

  it('updates the global asset library once with CSS and dependency manifest fields', async () => {
    const getGlobalAssetLibrary = vi.fn().mockResolvedValue({
      bundledSources: null,
      packageJson: null,
    });
    const updateGlobalAssetLibrary = vi.fn().mockResolvedValue({});

    await updateGlobalAssetLibraryForPush(
      { getGlobalAssetLibrary, updateGlobalAssetLibrary } as unknown as Pick<
        ApiService,
        'getGlobalAssetLibrary' | 'updateGlobalAssetLibrary'
      >,
      {
        css: {
          original: ':root { --canvas-test-color: #123456; }',
          compiled: ':root{--canvas-test-color:#123456}',
        },
        js: {
          original: '/* class candidates */',
          compiled: '',
        },
      },
      {
        artifactCount: 3,
        groupedManifest: {
          vendor: [
            {
              name: 'lodash',
              uri: 'public://canvas/artifacts/lodash-abc123.js',
            },
          ],
          local: [
            {
              name: '@/lib/utils',
              uri: 'public://canvas/artifacts/utils-def456.js',
            },
          ],
          shared: [
            {
              name: './vendor/chunk-shared-ghi789.js',
              uri: 'public://canvas/artifacts/chunk-shared-ghi789.js',
            },
          ],
          bundledSources: [
            {
              path: 'src/lib/lib-a.ts',
              source: 'export const a = 1;\n',
            },
          ],
        },
      },
    );

    expect(updateGlobalAssetLibrary).toHaveBeenCalledTimes(1);
    expect(updateGlobalAssetLibrary).toHaveBeenCalledWith({
      css: {
        original: ':root { --canvas-test-color: #123456; }',
        compiled: ':root{--canvas-test-color:#123456}',
      },
      js: {
        original: '/* class candidates */',
        compiled: '',
      },
      imports: [
        {
          name: 'lodash',
          uri: 'public://canvas/artifacts/lodash-abc123.js',
        },
      ],
      assets: [
        {
          name: '@/lib/utils',
          uri: 'public://canvas/artifacts/utils-def456.js',
        },
      ],
      shared: [
        {
          name: './vendor/chunk-shared-ghi789.js',
          uri: 'public://canvas/artifacts/chunk-shared-ghi789.js',
        },
      ],
      bundledSources: [
        {
          path: 'src/lib/lib-a.ts',
          source: 'export const a = 1;\n',
        },
      ],
    });
  });

  it('sends empty manifest groups so deleted imports are cleared on the server', async () => {
    const getGlobalAssetLibrary = vi.fn().mockResolvedValue({
      bundledSources: null,
      packageJson: null,
    });
    const updateGlobalAssetLibrary = vi.fn().mockResolvedValue({});

    await updateGlobalAssetLibraryForPush(
      { getGlobalAssetLibrary, updateGlobalAssetLibrary } as unknown as Pick<
        ApiService,
        'getGlobalAssetLibrary' | 'updateGlobalAssetLibrary'
      >,
      undefined,
      {
        artifactCount: 0,
        groupedManifest: {
          vendor: [],
          local: [],
          shared: [],
          bundledSources: [],
        },
      },
    );

    expect(updateGlobalAssetLibrary).toHaveBeenCalledTimes(1);
    expect(updateGlobalAssetLibrary).toHaveBeenCalledWith({
      imports: [],
      assets: [],
      shared: [],
      bundledSources: [],
    });
  });

  it('uses the legacy asset library payload for older Canvas sites', async () => {
    const getGlobalAssetLibrary = vi.fn().mockResolvedValue({
      id: 'global',
      label: 'Global asset library',
      css: null,
      js: null,
      imports: null,
      assets: null,
      shared: null,
    });
    const updateGlobalAssetLibrary = vi.fn().mockResolvedValue({});

    await updateGlobalAssetLibraryForPush(
      { getGlobalAssetLibrary, updateGlobalAssetLibrary } as unknown as Pick<
        ApiService,
        'getGlobalAssetLibrary' | 'updateGlobalAssetLibrary'
      >,
      {
        packageJson: '{"name":"example"}',
      },
      {
        artifactCount: 1,
        groupedManifest: {
          vendor: [],
          local: [
            {
              name: '@/lib/example',
              uri: 'public://canvas/assets/example.js',
              path: 'src/lib/example.ts',
              source: 'export const example = true;\n',
            },
          ],
          shared: [],
          bundledSources: [
            {
              path: 'src/lib/bundled.ts',
              source: 'export const bundled = true;\n',
            },
          ],
        },
      },
    );

    expect(updateGlobalAssetLibrary).toHaveBeenCalledWith({
      imports: [],
      assets: [
        {
          name: '@/lib/example',
          uri: 'public://canvas/assets/example.js',
        },
      ],
      shared: [],
    });
  });

  it('stops the dependency spinner with failure status when artifact upload fails', async () => {
    const outputDir = path.join(tmpDir, 'dist');
    await fs.mkdir(path.join(outputDir, 'vendor'), { recursive: true });

    await fs.writeFile(
      path.join(outputDir, 'vendor/lodash-abc123.js'),
      'export default {}',
      'utf-8',
    );

    await generateManifest({
      outputDir,
      vendorImportMap: { imports: { lodash: './vendor/lodash-abc123.js' } },
      localImportMap: {},
      sharedChunks: [],
    });

    const uploadArtifact = vi
      .fn()
      .mockRejectedValue(new Error('Destination file path is not writable'));
    const syncManifest = vi.fn();
    const spinner = {
      start: vi.fn(),
      stop: vi.fn(),
      message: vi.fn(),
    };

    await expect(
      syncManifestArtifacts(outputDir, {
        apiService: { uploadArtifact, syncManifest },
        createSpinner: () => spinner,
        logInfo: vi.fn(),
      }),
    ).rejects.toThrow(
      'Failed to upload lodash: Destination file path is not writable',
    );

    expect(syncManifest).not.toHaveBeenCalled();
    expect(spinner.stop).toHaveBeenCalledWith('Pushed dependencies', 2);
  });

  it('preserves structured artifact failures for dependency names with separators', async () => {
    const outputDir = path.join(tmpDir, 'dist');
    const dependencyName = '@/components/card: hero\nimage';
    await fs.mkdir(path.join(outputDir, 'local'), { recursive: true });

    await fs.writeFile(
      path.join(outputDir, 'local/hero.js'),
      'export const hero = true;',
      'utf-8',
    );

    await generateManifest({
      outputDir,
      vendorImportMap: null,
      localImportMap: { [dependencyName]: './local/hero.js' },
      sharedChunks: [],
    });

    const uploadError = 'Destination: not writable\nTry again';
    const uploadArtifact = vi.fn().mockRejectedValue(new Error(uploadError));
    const syncManifest = vi.fn();
    const spinner = {
      start: vi.fn(),
      stop: vi.fn(),
      message: vi.fn(),
    };

    const caught = await syncManifestArtifacts(outputDir, {
      apiService: { uploadArtifact, syncManifest },
      createSpinner: () => spinner,
      logInfo: vi.fn(),
    }).catch((error: unknown) => error);

    expect(caught).toBeInstanceOf(Error);
    expect((caught as { failedResults?: unknown }).failedResults).toEqual([
      {
        itemName: dependencyName,
        itemType: 'Artifact',
        success: false,
        details: [{ content: uploadError }],
      },
    ]);
    expect(syncManifest).not.toHaveBeenCalled();
    expect(spinner.stop).toHaveBeenCalledWith('Pushed dependencies', 2);
  });

  it('skips dependency map sync when there are no component dependencies to upload', async () => {
    const outputDir = path.join(tmpDir, 'dist');
    await fs.mkdir(outputDir, { recursive: true });

    await generateManifest({
      outputDir,
      vendorImportMap: { imports: {} },
      localImportMap: {},
      sharedChunks: [],
    });

    const uploadArtifact = vi.fn();
    const syncManifest = vi.fn();
    const logInfo = vi.fn();

    const result = await syncManifestArtifacts(outputDir, {
      apiService: { uploadArtifact, syncManifest },
      createSpinner: () => ({
        start: vi.fn(),
        stop: vi.fn(),
        message: vi.fn(),
      }),
      logInfo,
    });

    expect(uploadArtifact).not.toHaveBeenCalled();
    expect(syncManifest).not.toHaveBeenCalled();
    expect(logInfo).toHaveBeenCalledWith('No component dependencies to upload');
    expect(result.artifactCount).toBe(0);
    expect(result.groupedManifest).toEqual({
      vendor: [],
      local: [],
      shared: [],
      bundledSources: [],
    });
  });
});

describe('Push components', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('uploads built component payloads in dependency order', async () => {
    const api = mockApiService();
    vi.mocked(api.listComponents).mockResolvedValue({});
    vi.mocked(api.createComponent).mockResolvedValue({} as never);

    const results = await pushBuiltComponents(
      [
        {
          machineName: 'card',
          componentName: 'card',
          importedJsComponents: ['button'],
          componentPayload: {
            machineName: 'card',
            name: 'card',
            sourceCodeJs: "import Button from '@/components/button';",
            compiledJs: "import Button from '@/components/button';",
          } as never,
        },
        {
          machineName: 'button',
          componentName: 'button',
          importedJsComponents: [],
          componentPayload: {
            machineName: 'button',
            name: 'button',
            sourceCodeJs: 'export default function Button() {}',
            compiledJs: 'export default function Button() {}',
          } as never,
        },
      ],
      api,
      'Pushing',
    );

    expect(api.createComponent).toHaveBeenCalledTimes(2);
    expect(api.createComponent).toHaveBeenNthCalledWith(
      1,
      expect.objectContaining({ machineName: 'button' }),
      true,
    );
    expect(api.createComponent).toHaveBeenNthCalledWith(
      2,
      expect.objectContaining({ machineName: 'card' }),
      true,
    );
    expect(results.map((result) => result.itemName)).toEqual([
      'card',
      'button',
    ]);
  });

  it('returns component upload failures as component results', async () => {
    const api = mockApiService();
    const uploadError = [
      'The component "canvas:button" uses non-string types for properties: image.',
      '',
      "[props.image.$ref] '$ref' is not a supported key.",
    ].join('\n');
    const spinner = {
      start: vi.fn(),
      stop: vi.fn(),
      message: vi.fn(),
    };

    vi.mocked(api.listComponents).mockResolvedValue({});
    vi.mocked(api.createComponent).mockRejectedValue(new Error(uploadError));

    const results = await pushBuiltComponents(
      [
        {
          machineName: 'button',
          componentName: 'button',
          importedJsComponents: [],
          componentPayload: {
            machineName: 'button',
            name: 'button',
            sourceCodeJs: 'export default function Button() {}',
            compiledJs: 'export default function Button() {}',
          } as never,
        },
      ],
      api,
      'Pushing',
      spinner,
    );

    expect(spinner.stop).toHaveBeenCalledWith('Pushed components', 2);
    expect(results).toEqual([
      {
        itemName: 'button',
        success: false,
        details: [{ content: uploadError }],
      },
    ]);
  });
});

describe('prepareGlobalAssetLibraryUpdate package.json', () => {
  let outputDir: string;
  let projectRoot: string;

  beforeEach(async () => {
    outputDir = await fs.mkdtemp(path.join(os.tmpdir(), 'push-out-test-'));
    projectRoot = await fs.mkdtemp(path.join(os.tmpdir(), 'push-root-test-'));
    // The compiled global CSS/JS must exist for a successful prepare.
    await fs.writeFile(path.join(outputDir, 'index.css'), 'body {}', 'utf-8');
    await fs.writeFile(path.join(outputDir, 'index.js'), '', 'utf-8');
  });

  afterEach(async () => {
    await fs.rm(outputDir, { recursive: true, force: true });
    await fs.rm(projectRoot, { recursive: true, force: true });
  });

  it('includes the project package.json verbatim when present', async () => {
    const packageJson = '{\n  "name": "my-project"\n}\n';
    await fs.writeFile(
      path.join(projectRoot, 'package.json'),
      packageJson,
      'utf-8',
    );

    const { result, assetLibrary } = await prepareGlobalAssetLibraryUpdate(
      outputDir,
      projectRoot,
    );

    expect(result.success).toBe(true);
    expect(assetLibrary?.packageJson).toBe(packageJson);
  });

  it('omits the packageJson key when no package.json exists', async () => {
    const { result, assetLibrary } = await prepareGlobalAssetLibraryUpdate(
      outputDir,
      projectRoot,
    );

    expect(result.success).toBe(true);
    expect(assetLibrary).toBeDefined();
    expect('packageJson' in (assetLibrary ?? {})).toBe(false);
  });

  it('omits the packageJson key when no projectRoot is given', async () => {
    const { assetLibrary } = await prepareGlobalAssetLibraryUpdate(outputDir);

    expect('packageJson' in (assetLibrary ?? {})).toBe(false);
  });
});
