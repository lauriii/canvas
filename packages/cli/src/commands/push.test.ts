import fs from 'fs/promises';
import os from 'os';
import path from 'path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { generateManifest } from '../utils/generate-manifest';
import { pushBuiltComponents } from '../utils/prepare-push';
import {
  getSyncExclusionMessage,
  getSyncExclusionSource,
  syncManifestArtifacts,
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

describe('Push artifacts', () => {
  let tmpDir: string;

  beforeEach(async () => {
    tmpDir = await fs.mkdtemp(path.join(os.tmpdir(), 'push-manifest-test-'));
  });

  afterEach(async () => {
    await fs.rm(tmpDir, { recursive: true, force: true });
  });

  it('uploads artifacts, syncs manifest, and verifies temp files exist', async () => {
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

  it('skips manifest sync when there are no artifacts to upload', async () => {
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
    expect(logInfo).toHaveBeenCalledWith(
      'No manifest artifacts to upload, skipping manifest sync',
    );
    expect(result.artifactCount).toBe(0);
    expect(result.groupedManifest).toEqual({
      vendor: [],
      local: [],
      shared: [],
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
});
