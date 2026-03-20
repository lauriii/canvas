import fs from 'fs/promises';
import os from 'os';
import path from 'path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { buildComponent } from '../utils/build-component';
import { generateManifest } from '../utils/generate-manifest';
import { buildAndPushComponents } from '../utils/prepare-push';
import { processComponentFiles } from '../utils/process-component-files';
import { syncManifestArtifacts } from './push';

import type { DiscoveredComponent } from '@drupal-canvas/discovery';
import type { ApiService } from '../services/api';
import type { Metadata } from '../types/Metadata';
import type { Result } from '../types/Result';

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

vi.mock('../utils/build-tailwind', () => ({
  buildTailwindForComponents: vi.fn(),
}));

vi.mock('../utils/build-component', () => ({
  buildComponent: vi.fn(),
}));

vi.mock('../utils/process-component-files', () => ({
  processComponentFiles: vi.fn(),
  createComponentPayload: vi.fn((args) => ({
    machineName: args.machineName,
    name: args.componentName,
    sourceCodeJs: args.sourceCodeJs,
    compiledJs: args.compiledJs,
    sourceCodeCss: args.sourceCodeCss,
    compiledCss: args.compiledCss,
  })),
}));

function mockDiscoveredComponent(name: string): DiscoveredComponent {
  return {
    name,
    kind: 'component',
    directory: `/tmp/${name}`,
    jsEntryPath: `/tmp/${name}/index.jsx`,
  } as unknown as DiscoveredComponent;
}

function mockApiService(): ApiService {
  return {
    listComponents: vi.fn(),
    createComponent: vi.fn(),
    updateComponent: vi.fn(),
    deleteComponent: vi.fn(),
  } as unknown as ApiService;
}

function mockMetadata(machineName: string): Metadata {
  return {
    name: machineName,
    machineName,
    status: true,
    required: [],
    slots: {},
    props: {
      properties: {},
    },
  };
}

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

  it('creates new, updates existing, and deletes remote-only components', async () => {
    const components = [
      mockDiscoveredComponent('button'),
      mockDiscoveredComponent('card'),
    ];
    const api = mockApiService();

    vi.mocked(buildComponent).mockImplementation(async (component) => ({
      itemName: component.name,
      success: true,
    }));
    vi.mocked(processComponentFiles)
      .mockResolvedValueOnce({
        sourceCodeJs: 'export default function button() {}',
        compiledJs: 'compiled button',
        sourceCodeCss: '.button {}',
        compiledCss: '.button{ }',
        metadata: mockMetadata('button'),
      })
      .mockResolvedValueOnce({
        sourceCodeJs: 'export default function card() {}',
        compiledJs: 'compiled card',
        sourceCodeCss: '.card {}',
        compiledCss: '.card{ }',
        metadata: mockMetadata('card'),
      });

    // Remote has 'card' (update), no 'button' (create), and 'hero' (delete)
    vi.mocked(api.listComponents).mockResolvedValue({
      card: { machineName: 'card' } as never,
      hero: { machineName: 'hero' } as never,
    });
    vi.mocked(api.createComponent).mockResolvedValue({} as never);
    vi.mocked(api.updateComponent).mockResolvedValue({} as never);
    vi.mocked(api.deleteComponent).mockResolvedValue(undefined);

    const results = await buildAndPushComponents(
      components,
      api,
      false,
      'Pushing',
    );

    expect(api.listComponents).toHaveBeenCalledTimes(1);
    expect(api.createComponent).toHaveBeenCalledTimes(1);
    expect(api.updateComponent).toHaveBeenCalledTimes(1);
    expect(api.deleteComponent).toHaveBeenCalledTimes(1);

    expect(api.createComponent).toHaveBeenCalledWith(
      expect.objectContaining({ machineName: 'button' }),
      true,
    );
    expect(api.updateComponent).toHaveBeenCalledWith(
      'card',
      expect.objectContaining({ machineName: 'card' }),
    );
    expect(api.deleteComponent).toHaveBeenCalledWith('hero');

    expect(results).toEqual([
      {
        itemName: 'button',
        success: true,
        details: [{ content: 'Created' }],
      },
      {
        itemName: 'card',
        success: true,
        details: [{ content: 'Updated' }],
      },
      {
        itemName: 'hero',
        success: true,
        details: [{ content: 'Deleted' }],
      },
    ]);
  });

  it('returns results with failures when any component build fails', async () => {
    const components = [
      mockDiscoveredComponent('button'),
      mockDiscoveredComponent('broken'),
    ];
    const api = mockApiService();

    vi.mocked(buildComponent)
      .mockResolvedValueOnce({
        itemName: 'button',
        success: true,
      } as Result)
      .mockResolvedValueOnce({
        itemName: 'broken',
        success: false,
        details: [{ content: 'Build failed' }],
      } as Result);

    vi.mocked(processComponentFiles).mockResolvedValue({
      sourceCodeJs: 'export default function button() {}',
      compiledJs: 'compiled button',
      sourceCodeCss: '.button {}',
      compiledCss: '.button{ }',
      metadata: mockMetadata('button'),
    });

    vi.mocked(api.listComponents).mockResolvedValue({});
    vi.mocked(api.createComponent).mockResolvedValue({} as never);

    const results = await buildAndPushComponents(components, api, false);
    expect(results.some((r) => !r.success)).toBe(true);

    expect(api.createComponent).not.toHaveBeenCalled();
    expect(api.updateComponent).not.toHaveBeenCalled();
  });

  it('returns results with failures when any component preparation fails', async () => {
    const components = [mockDiscoveredComponent('button')];
    const api = mockApiService();

    vi.mocked(buildComponent).mockResolvedValue({
      itemName: 'button',
      success: true,
    } as Result);
    vi.mocked(processComponentFiles).mockRejectedValue(
      new Error('Invalid metadata file'),
    );

    const results = await buildAndPushComponents(components, api, false);
    expect(results.some((r) => !r.success)).toBe(true);

    expect(api.listComponents).not.toHaveBeenCalled();
    expect(api.createComponent).not.toHaveBeenCalled();
    expect(api.updateComponent).not.toHaveBeenCalled();
  });

  it('throws when any component upload fails', async () => {
    const components = [mockDiscoveredComponent('button')];
    const api = mockApiService();

    vi.mocked(buildComponent).mockResolvedValue({
      itemName: 'button',
      success: true,
    } as Result);
    vi.mocked(processComponentFiles).mockResolvedValue({
      sourceCodeJs: 'export default function button() {}',
      compiledJs: 'compiled button',
      sourceCodeCss: '.button {}',
      compiledCss: '.button{ }',
      metadata: mockMetadata('button'),
    });

    vi.mocked(api.listComponents).mockResolvedValue({});
    vi.mocked(api.createComponent).mockRejectedValue(
      new Error('Upload failed'),
    );

    await expect(
      buildAndPushComponents(components, api, false),
    ).rejects.toThrow(
      'Component upload failed for 1 component: button (Upload failed)',
    );

    expect(api.updateComponent).not.toHaveBeenCalled();
  });
});
