import { promises as fs } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
  buildCanvasComponentEntry,
  buildCanvasLocalArtifacts,
  buildCanvasVendorArtifacts,
} from '@drupal-canvas/vite-compat';

import { buildCanvasProject } from './build-project';
import { buildTailwindForComponents } from './build-tailwind';

import type {
  DiscoveredComponent,
  DiscoveryResult,
} from '@drupal-canvas/discovery';
import type * as ViteCompat from '@drupal-canvas/vite-compat';

vi.mock('tailwindcss-in-browser', () => ({
  compilePartialCss: vi.fn(async (source: string) => source),
}));

vi.mock('@drupal-canvas/ui/features/code-editor/utils/ast-utils', () => ({
  getDataDependenciesFromAst: vi.fn(() => ({})),
  getImportsFromAst: vi.fn(() => ['button']),
}));

vi.mock('./validate', async () => {
  const path = await import('node:path');
  return {
    validateComponent: vi.fn(async (componentDir: string) => ({
      itemName: path.basename(componentDir),
      success: true,
      details: [],
    })),
  };
});

vi.mock('./build-tailwind', () => ({
  getGlobalCss: vi.fn(async () => ''),
  buildTailwindForComponents: vi.fn(async () => ({
    itemName: 'Global CSS',
    success: true,
  })),
}));

vi.mock('@drupal-canvas/vite-compat', async (importOriginal) => {
  const { promises: fs } = await import('node:fs');
  const path = await import('node:path');
  const actual = (await importOriginal()) as typeof ViteCompat;
  return {
    ...actual,
    buildCanvasComponentEntry: vi.fn(async (options) => {
      await fs.mkdir(options.entry.outputDir, { recursive: true });
      await fs.writeFile(
        path.join(
          options.entry.outputDir,
          `${options.entry.outputBaseName}.js`,
        ),
        "import { animate } from 'motion/react';\nexport default function Card() { return animate; }\n",
      );

      const metadata = actual.createCanvasDependencyMetadata();
      metadata.thirdPartyPackages.add('motion/react');
      metadata.localAliasImports.set(
        '@/lib/utils',
        path.join(options.projectRoot, 'src/lib/utils.ts'),
      );
      return metadata;
    }),
    buildCanvasLocalArtifacts: vi.fn(async () => ({
      localImportMap: { '@/lib/utils': './local/utils-a1b2c3.js' },
      sharedChunks: ['./local/shared-local.js'],
      thirdPartyPackages: new Set(['lodash-es']),
    })),
    buildCanvasVendorArtifacts: vi.fn(async ({ packages }) => ({
      importMap: {
        imports: Object.fromEntries(
          [...packages].map((packageName) => [
            packageName,
            `./vendor/${packageName.replace('/', '--')}.js`,
          ]),
        ),
      },
      bundledPackages: [...packages],
      sharedChunks: ['./vendor/shared-vendor.js'],
    })),
  };
});

describe('buildCanvasProject', () => {
  let tmpDir: string;
  let outputDir: string;
  let componentDir: string;

  beforeEach(async () => {
    vi.clearAllMocks();
    tmpDir = await fs.mkdtemp(path.join(os.tmpdir(), 'canvas-build-project-'));
    outputDir = path.join(tmpDir, 'dist');
    componentDir = path.join(tmpDir, 'src/components');
    const cardDir = path.join(componentDir, 'card');
    await fs.mkdir(path.join(tmpDir, 'src/lib'), { recursive: true });
    await fs.mkdir(cardDir, { recursive: true });
    await fs.writeFile(
      path.join(cardDir, 'component.yml'),
      [
        'name: card',
        'machineName: card',
        'status: true',
        'props:',
        '  properties: {}',
        'slots: {}',
      ].join('\n'),
    );
    await fs.writeFile(
      path.join(cardDir, 'index.tsx'),
      [
        "import Button from '@/components/button';",
        "import { cn } from '@/lib/utils';",
        "import { animate } from 'motion/react';",
        'export default function Card() {',
        '  return <Button className={cn(String(animate))} />;',
        '}',
      ].join('\n'),
    );
    await fs.writeFile(
      path.join(tmpDir, 'src/lib/utils.ts'),
      'export function cn(value: string) { return value; }',
    );
  });

  afterEach(async () => {
    await fs.rm(tmpDir, { recursive: true, force: true });
  });

  it('builds component payloads and writes the Canvas manifest from Vite artifacts', async () => {
    const component = {
      id: 'card',
      name: 'card',
      kind: 'index',
      directory: path.join(componentDir, 'card'),
      relativeDirectory: 'src/components/card',
      metadataPath: path.join(componentDir, 'card/component.yml'),
      jsEntryPath: path.join(componentDir, 'card/index.tsx'),
      cssEntryPath: null,
    } as DiscoveredComponent;
    const discoveryResult = {
      componentRoot: componentDir,
      projectRoot: tmpDir,
      components: [component],
      pages: [],
      contentTemplates: [],
      regions: [],
      warnings: [],
      stats: {
        scannedFiles: 2,
        ignoredFiles: 0,
      },
    } as DiscoveryResult;

    const result = await buildCanvasProject({
      projectRoot: tmpDir,
      componentDir,
      aliasBaseDir: 'src',
      outputDir,
      discoveryResult,
      cleanOutputDir: true,
      buildTailwind: true,
      requireJsEntries: true,
    });

    expect(buildCanvasComponentEntry).toHaveBeenCalledWith(
      expect.objectContaining({
        projectRoot: tmpDir,
        aliasBaseDir: 'src',
        entry: expect.objectContaining({
          entryPath: component.jsEntryPath,
          outputBaseName: 'index',
        }),
      }),
    );
    expect(buildCanvasLocalArtifacts).toHaveBeenCalledWith(
      expect.objectContaining({
        localImports: new Map([
          ['@/lib/utils', path.join(tmpDir, 'src/lib/utils.ts')],
        ]),
      }),
    );
    expect(buildCanvasVendorArtifacts).toHaveBeenCalledWith(
      expect.objectContaining({
        packages: new Set(['motion/react', 'lodash-es']),
      }),
    );
    expect(buildTailwindForComponents).toHaveBeenCalledWith(
      [component],
      true,
      path.resolve(outputDir),
    );
    expect(result.componentResults).toEqual([
      expect.objectContaining({ itemName: 'card', success: true }),
    ]);
    expect(result.builtComponents).toHaveLength(1);
    expect(result.builtComponents[0]).toEqual(
      expect.objectContaining({
        machineName: 'card',
        componentName: 'card',
        importedJsComponents: ['button'],
      }),
    );
    expect(result.builtComponents[0]?.componentPayload).toEqual(
      expect.objectContaining({
        machineName: 'card',
        sourceCodeJs: expect.stringContaining('@/components/button'),
        compiledJs: expect.stringContaining("from 'motion/react'"),
      }),
    );
    expect(result.manifest).toEqual({
      vendor: {
        'lodash-es': './vendor/lodash-es.js',
        'motion/react': './vendor/motion--react.js',
      },
      local: {
        '@/lib/utils': './local/utils-a1b2c3.js',
      },
      shared: ['./vendor/shared-vendor.js', './local/shared-local.js'],
    });
    await expect(
      fs.access(path.join(outputDir, 'canvas-manifest.json')),
    ).resolves.toBeUndefined();
  });

  it('rejects componentDir outside aliasBaseDir', async () => {
    await expect(
      buildCanvasProject({
        projectRoot: tmpDir,
        componentDir: 'components',
        aliasBaseDir: 'src',
        outputDir,
        discoveryResult: {
          componentRoot: path.join(tmpDir, 'components'),
          projectRoot: tmpDir,
          components: [],
          pages: [],
          contentTemplates: [],
          regions: [],
          warnings: [],
          stats: {
            scannedFiles: 0,
            ignoredFiles: 0,
          },
        } as DiscoveryResult,
      }),
    ).rejects.toThrow(
      'Invalid Canvas config: componentDir "components" must be inside aliasBaseDir "src".',
    );
  });
});
