import { promises as fs } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { afterEach, describe, expect, it } from 'vitest';
import { resolveCanvasConfig } from '@drupal-canvas/discovery';

import {
  buildCanvasComponentEntry,
  buildCanvasLocalArtifacts,
  createCanvasViteBuildConfig,
  drupalCanvasCompat,
  drupalCanvasCompatServer,
  ensureHostGlobalCssExists,
  extractComponentPreviewMetadataFromComponentYaml,
  extractFirstExamplePropsFromComponentYaml,
  getWorkbenchHostGlobalCssVirtualUrl,
  resolveHostGlobalCssPath,
  rewriteCanvasAssetImports,
} from './index';

const tempDirs: string[] = [];

afterEach(async () => {
  await Promise.all(
    tempDirs.map((dir) => fs.rm(dir, { recursive: true, force: true })),
  );
  tempDirs.length = 0;
});

async function makeTempDir(): Promise<string> {
  const dir = await fs.mkdtemp(path.join(os.tmpdir(), 'canvas-vite-compat-'));
  tempDirs.push(dir);
  return dir;
}

async function withWorkingDirectory<T>(
  directory: string,
  callback: () => Promise<T> | T,
): Promise<T> {
  const previousDirectory = process.cwd();
  process.chdir(directory);

  try {
    return await callback();
  } finally {
    process.chdir(previousDirectory);
  }
}

function getResolveIdHook(plugin: { resolveId?: unknown }) {
  const resolveId = plugin.resolveId as unknown;
  if (!resolveId) {
    return null;
  }

  if (typeof resolveId === 'function') {
    return resolveId as (
      source: string,
      importer?: string,
      options?: unknown,
    ) => unknown;
  }

  const objectHook = resolveId as { handler?: unknown };
  if (typeof objectHook.handler !== 'function') {
    return null;
  }

  return objectHook.handler as (
    source: string,
    importer?: string,
    options?: unknown,
  ) => unknown;
}

describe('vite-compat', () => {
  it('creates fs allow config for host root', () => {
    const server = drupalCanvasCompatServer({
      hostRoot: '/tmp/host',
    });
    expect(server).toBeDefined();
    expect(server?.fs?.allow).toEqual(['/tmp/host']);
  });

  it('extracts first example values from component.yml props', async () => {
    const root = await makeTempDir();
    const metadataPath = path.join(root, 'component.yml');
    await fs.writeFile(
      metadataPath,
      [
        'name: Example',
        'props:',
        '  properties:',
        '    title:',
        '      type: string',
        '      examples:',
        '        - Hello',
        '        - Hi',
        '    count:',
        '      type: number',
        '      examples:',
        '        - 5',
      ].join('\n'),
      'utf-8',
    );

    const result =
      await extractFirstExamplePropsFromComponentYaml(metadataPath);
    expect(result).toEqual({
      title: 'Hello',
      count: 5,
    });
  });

  it('resolves host global css path from canvas.config.json', async () => {
    const root = await makeTempDir();
    await fs.writeFile(
      path.join(root, 'canvas.config.json'),
      JSON.stringify({ globalCssPath: 'app/components/global.css' }),
      'utf-8',
    );
    const resolved = resolveHostGlobalCssPath(root);
    expect(resolved).toBe(path.join(root, 'app/components/global.css'));
  });

  it('resolves pagesDir from canvas.config.json', async () => {
    const root = await makeTempDir();
    await fs.writeFile(
      path.join(root, 'canvas.config.json'),
      JSON.stringify({ pagesDir: 'content/pages' }),
      'utf-8',
    );

    expect(resolveCanvasConfig({ hostRoot: root }).pagesDir).toBe(
      'content/pages',
    );
  });

  it('uses default pagesDir when canvas.config.json is missing', async () => {
    const root = await makeTempDir();

    expect(resolveCanvasConfig({ hostRoot: root }).pagesDir).toBe('pages');
  });

  it('extracts component labels and example props from component metadata', async () => {
    const root = await makeTempDir();
    const metadataPath = path.join(root, 'component.yml');
    await fs.writeFile(
      metadataPath,
      [
        'name: Hero',
        'required:',
        '  - title',
        'props:',
        '  properties:',
        '    title:',
        '      type: string',
        '      examples:',
        '        - Hello',
      ].join('\n'),
      'utf-8',
    );

    const result =
      await extractComponentPreviewMetadataFromComponentYaml(metadataPath);
    expect(result).toEqual({
      label: 'Hero',
      exampleProps: {
        title: 'Hello',
      },
      requiredPropNames: ['title'],
    });
  });

  it('validates host global css existence', async () => {
    const root = await makeTempDir();
    const cssPath = path.join(root, 'src/global.css');
    await fs.mkdir(path.dirname(cssPath), { recursive: true });
    await fs.writeFile(cssPath, '@import "tailwindcss";', 'utf-8');

    const resolved = await ensureHostGlobalCssExists(root);
    expect(resolved).toBe(cssPath);
  });

  it('validates legacy host global css existence when new default is missing', async () => {
    const root = await makeTempDir();
    const cssPath = path.join(root, 'src/components/global.css');
    await fs.mkdir(path.dirname(cssPath), { recursive: true });
    await fs.writeFile(cssPath, '@import "tailwindcss";', 'utf-8');

    const resolved = await ensureHostGlobalCssExists(root);
    expect(resolved).toBe(cssPath);
  });

  it('throws when host global css is missing', async () => {
    const root = await makeTempDir();
    await expect(ensureHostGlobalCssExists(root)).rejects.toThrow(
      'Missing required host Tailwind entrypoint',
    );
  });

  it('returns stable virtual module URL for host global css', () => {
    expect(getWorkbenchHostGlobalCssVirtualUrl()).toBe(
      '/@id/virtual:canvas-host-global.css',
    );
  });

  it('resolves host alias imports only for host-root importers', () => {
    const [resolverPlugin] = drupalCanvasCompat({
      hostRoot: '/tmp/host',
    });
    const resolveId = getResolveIdHook(resolverPlugin);
    expect(resolveId).not.toBeNull();

    const resolvedHostImport = resolveId?.(
      '@/lib/utils',
      '/tmp/host/components/card/index.tsx',
    );
    expect(resolvedHostImport).toBe('/tmp/host/src/lib/utils');

    const resolvedWorkbenchImport = resolveId?.(
      '@/lib/utils',
      '/tmp/workbench/src/App.tsx',
    );
    expect(resolvedWorkbenchImport).toBeNull();
  });

  it('resolves host alias imports for Vite @fs importer ids with query', () => {
    const [resolverPlugin] = drupalCanvasCompat({
      hostRoot: '/tmp/host',
    });
    const resolveId = getResolveIdHook(resolverPlugin);
    expect(resolveId).not.toBeNull();

    const resolvedHostImport = resolveId?.(
      '@/lib/utils',
      '/@fs/tmp/host/components/card/index.jsx?import',
    );
    expect(resolvedHostImport).toBe('/tmp/host/src/lib/utils');
  });

  it('resolves alias imports for assets and side-effect CSS', () => {
    const [resolverPlugin] = drupalCanvasCompat({
      hostRoot: '/tmp/host',
    });
    const resolveId = getResolveIdHook(resolverPlugin);
    expect(resolveId).not.toBeNull();

    expect(
      resolveId?.(
        '@/components/hero/hero.jpg',
        '/tmp/host/components/hero/index.tsx',
      ),
    ).toBe('/tmp/host/src/components/hero/hero.jpg');
    expect(
      resolveId?.(
        '@/components/cart/cart.svg',
        '/tmp/host/components/cart/index.tsx',
      ),
    ).toBe('/tmp/host/src/components/cart/cart.svg');
    expect(
      resolveId?.(
        '@/utils/styles/carousel.css',
        '/tmp/host/components/carousel/index.tsx',
      ),
    ).toBe('/tmp/host/src/utils/styles/carousel.css');
  });

  it('preserves Vite query suffixes when resolving host aliases', () => {
    const [resolverPlugin] = drupalCanvasCompat({
      hostRoot: '/tmp/host',
    });
    const resolveId = getResolveIdHook(resolverPlugin);
    expect(resolveId).not.toBeNull();

    expect(
      resolveId?.(
        '@/icons/logo.svg?react',
        '/tmp/host/src/components/card.tsx',
      ),
    ).toBe('/tmp/host/src/icons/logo.svg?react');
    expect(
      resolveId?.('@/icons/logo.svg#icon', '/tmp/host/src/components/card.tsx'),
    ).toBe('/tmp/host/src/icons/logo.svg#icon');
  });

  it('rewrites deployable asset imports to Canvas import map lookups', async () => {
    const hostRoot = await makeTempDir();
    const componentEntry = path.join(hostRoot, 'src/components/card/index.tsx');
    const imagePath = path.join(hostRoot, 'src/lib/cafe.webp');
    await fs.mkdir(path.dirname(componentEntry), { recursive: true });
    await fs.mkdir(path.dirname(imagePath), { recursive: true });
    await fs.writeFile(imagePath, 'image', 'utf-8');

    const result = rewriteCanvasAssetImports(
      [
        "import cafeUrl from '../../lib/cafe.webp';",
        "import Icon from '../../lib/icon.svg?react';",
      ].join('\n'),
      {
        filePath: componentEntry,
        hostRoot,
        hostAliasBaseDir: 'src',
      },
    );

    expect(result.code).toContain(
      'const cafeUrl = import.meta.resolve("@/lib/cafe.webp");',
    );
    expect(result.code).toContain(
      "import Icon from '../../lib/icon.svg?react'",
    );
    expect(result.assets).toEqual([
      {
        specifier: '@/lib/cafe.webp',
        filePath: imagePath,
      },
    ]);
  });

  it('builds relative SVG React component imports as component code', async () => {
    const hostRoot = await makeTempDir();
    const componentEntry = path.join(hostRoot, 'src/components/card/index.tsx');
    const outputDir = path.join(hostRoot, 'dist/components/card');
    await fs.mkdir(path.dirname(componentEntry), { recursive: true });
    await fs.writeFile(
      path.join(path.dirname(componentEntry), 'icon.svg'),
      '<svg viewBox="0 0 10 10"><circle cx="5" cy="5" r="4" /></svg>',
      'utf-8',
    );
    await fs.writeFile(
      componentEntry,
      [
        "import Icon from './icon.svg?react';",
        'export default function Card() {',
        '  return <Icon aria-hidden />;',
        '}',
      ].join('\n'),
      'utf-8',
    );

    await buildCanvasComponentEntry({
      projectRoot: hostRoot,
      aliasBaseDir: 'src',
      entry: {
        entryPath: componentEntry,
        outputDir,
        outputBaseName: 'index',
      },
    });

    const output = await fs.readFile(path.join(outputDir, 'index.js'), 'utf-8');
    expect(output).toContain('"svg"');
    expect(output).toContain('"circle"');
    expect(output).not.toContain('data:image/svg+xml');
  });

  it('creates a shared Canvas Vite config with host compatibility plugins', () => {
    const config = createCanvasViteBuildConfig({
      hostRoot: '/tmp/host',
      hostAliasBaseDir: 'src',
    });

    expect(config.root).toBe('/tmp/host');
    expect(config.esbuild).toEqual({ jsx: 'automatic' });
    const pluginNames = (config.plugins ?? []).flatMap((plugin) => {
      if (plugin && typeof plugin === 'object' && 'name' in plugin) {
        return [plugin.name];
      }
      return [];
    });

    expect(pluginNames).toEqual(
      expect.arrayContaining([
        'canvas-vite-compat-host-alias',
        'drupal-canvas',
      ]),
    );
  });

  it('emits direct local assets without duplicating them as shared chunks', async () => {
    const hostRoot = await makeTempDir();
    const outputDir = path.join(hostRoot, 'dist');
    const imagePath = path.join(hostRoot, 'src/lib/cafe.jpg');
    await fs.mkdir(path.dirname(imagePath), { recursive: true });
    await fs.writeFile(imagePath, 'image', 'utf-8');

    const result = await buildCanvasLocalArtifacts({
      projectRoot: hostRoot,
      aliasBaseDir: 'src',
      outputDir,
      localImports: new Map(),
      assetImports: new Map([['@/lib/cafe.jpg', imagePath]]),
    });

    expect(result.localImportMap['@/lib/cafe.jpg']).toMatch(
      /^\.\/local\/cafe-[\w-]+\.jpg$/,
    );
    expect(result.sharedChunks).toEqual([]);
    // Binary asset meta records the original relative disk path, derived from
    // the resolved absolute path, and carries no source.
    expect(result.localSources['@/lib/cafe.jpg']).toEqual({
      path: 'src/lib/cafe.jpg',
    });
    await expect(
      fs.access(
        path.join(
          outputDir,
          result.localImportMap['@/lib/cafe.jpg'].replace('./', ''),
        ),
      ),
    ).resolves.toBeUndefined();
  });

  it('records local module meta with the original disk path and verbatim source', async () => {
    const hostRoot = await makeTempDir();
    const outputDir = path.join(hostRoot, 'dist');
    // A text module reachable via the `@/…` alias.
    const helperPath = path.join(hostRoot, 'src/lib/helper.ts');
    await fs.mkdir(path.dirname(helperPath), { recursive: true });
    const helperSource = 'export const greet = (n: string) => `hi ${n}`;\n';
    await fs.writeFile(helperPath, helperSource, 'utf-8');
    // A binary asset reached via a relative import — its specifier is synthetic
    // but the recorded path must still reflect the true disk location.
    const posterPath = path.join(hostRoot, 'src/components/card/poster.webp');
    await fs.mkdir(path.dirname(posterPath), { recursive: true });
    await fs.writeFile(posterPath, 'webp', 'utf-8');

    const result = await buildCanvasLocalArtifacts({
      projectRoot: hostRoot,
      aliasBaseDir: 'src',
      outputDir,
      localImports: new Map([['@/lib/helper', helperPath]]),
      assetImports: new Map([['@/components/card/poster.webp', posterPath]]),
    });

    // Text module: path derived from the resolved path, plus verbatim source.
    expect(result.localSources['@/lib/helper']).toEqual({
      path: 'src/lib/helper.ts',
      source: helperSource,
    });
    // Binary asset resolved from a relative import: path is the true disk
    // location, never the synthetic specifier; no source.
    expect(result.localSources['@/components/card/poster.webp']).toEqual({
      path: 'src/components/card/poster.webp',
    });
  });

  it('keeps transitively-imported local helpers bundled but captures their sources', async () => {
    const hostRoot = await fs.realpath(await makeTempDir());
    const outputDir = path.join(hostRoot, 'dist');
    // `@/lib/a` is imported by a component (so it is in `localImports`).
    const aPath = path.join(hostRoot, 'src/lib/a.ts');
    await fs.mkdir(path.dirname(aPath), { recursive: true });
    const aSource =
      "import { b } from '@/lib/b';\nexport const a = `a-${b}`;\n";
    await fs.writeFile(aPath, aSource, 'utf-8');
    // `@/lib/b` is imported only by `@/lib/a`, never directly by a component,
    // so it is NOT in `localImports`. Rollup inlines it into `a`'s bundle; it
    // gets no runtime artifact, but its source must still be captured so a pull
    // can restore the editable file.
    const bPath = path.join(hostRoot, 'src/lib/b.ts');
    const bSource =
      "import { c } from '@/lib/c';\nexport const b = `b-${c}`;\n";
    await fs.writeFile(bPath, bSource, 'utf-8');
    // `@/lib/c` is a second-level transitive import (reached only via `b`). It
    // verifies the `buildEnd` walk sees the whole graph, so `c` is captured even
    // though it surfaces two levels below a component import.
    const cPath = path.join(hostRoot, 'src/lib/c.ts');
    const cSource = "export const c = 'c';\n";
    await fs.writeFile(cPath, cSource, 'utf-8');

    const result = await buildCanvasLocalArtifacts({
      projectRoot: hostRoot,
      aliasBaseDir: 'src',
      outputDir,
      localImports: new Map([['@/lib/a', aPath]]),
      assetImports: new Map(),
    });

    // The entry keeps its own runtime artifact.
    expect(result.localImportMap['@/lib/a']).toMatch(
      /^\.\/local\/a-[\w-]+\.js$/,
    );
    expect(Object.keys(result.localImportMap)).toEqual(['@/lib/a']);
    // The transitive helpers stay bundled: no runtime artifact, so no new
    // network request per helper.
    expect(result.localImportMap['@/lib/b']).toBeUndefined();
    expect(result.localImportMap['@/lib/c']).toBeUndefined();
    // Their verbatim sources are captured separately, keyed by disk path, for
    // pull to restore.
    expect(result.bundledSources).toContainEqual({
      path: 'src/lib/b.ts',
      source: bSource,
    });
    expect(result.bundledSources).toContainEqual({
      path: 'src/lib/c.ts',
      source: cSource,
    });
    // The entry itself is restored via `localSources`, not `bundledSources`.
    expect(
      result.bundledSources.some((entry) => entry.path === 'src/lib/a.ts'),
    ).toBe(false);
  });

  it('captures the source of a relative dep imported inside a local helper', async () => {
    const hostRoot = await fs.realpath(await makeTempDir());
    const outputDir = path.join(hostRoot, 'dist');
    // `@/lib/a` is imported by a component (so it is in `localImports`).
    const aPath = path.join(hostRoot, 'src/lib/a.ts');
    await fs.mkdir(path.dirname(aPath), { recursive: true });
    // The edge case: a transitive dep with a *relative* import. ESLint blocks
    // `./…` module imports in components but not inside local deps, so `a` may
    // legally import `./nested`. `nested` has no alias specifier at all.
    const aSource = "import { b } from './b';\nexport const a = `a-${b}`;\n";
    await fs.writeFile(aPath, aSource, 'utf-8');
    const nestedPath = path.join(hostRoot, 'src/lib/b.ts');
    const nestedSource =
      "import poster from './poster.webp';\nexport const b = poster;\n";
    await fs.writeFile(nestedPath, nestedSource, 'utf-8');
    const posterPath = path.join(hostRoot, 'src/lib/poster.webp');
    await fs.writeFile(posterPath, Buffer.alloc(10_000, 1));

    const result = await buildCanvasLocalArtifacts({
      projectRoot: hostRoot,
      aliasBaseDir: 'src',
      outputDir,
      localImports: new Map([['@/lib/a', aPath]]),
      assetImports: new Map(),
    });

    // The relative module stays bundled into `a` (no runtime artifact of its
    // own), while its static asset becomes a normal import-map entry.
    expect(result.localImportMap['@/lib/a']).toMatch(
      /^\.\/local\/a-[\w-]+\.js$/,
    );
    expect(result.localImportMap['@/lib/poster.webp']).toMatch(
      /^\.\/local\/poster-[\w-]+\.webp$/,
    );
    expect(result.localSources['@/lib/poster.webp']).toEqual({
      path: 'src/lib/poster.webp',
    });
    const builtEntry = await fs.readFile(
      path.join(
        outputDir,
        result.localImportMap['@/lib/a'].replace(/^\.\//, ''),
      ),
      'utf-8',
    );
    expect(builtEntry).toContain('import.meta.resolve("@/lib/poster.webp")');
    // Its source is captured by disk path so a pull can restore the file, even
    // though it never had an import specifier to key on.
    expect(result.bundledSources).toContainEqual({
      path: 'src/lib/b.ts',
      source: nestedSource,
    });
    expect(
      result.bundledSources.some(
        (entry) => entry.path === 'src/lib/poster.webp',
      ),
    ).toBe(false);
    expect(result.sharedChunks).toEqual([]);
  });

  it('supports overriding host alias base dir', () => {
    const [resolverPlugin] = drupalCanvasCompat({
      hostRoot: '/tmp/host',
      hostAliasBaseDir: '',
    });
    const resolveId = getResolveIdHook(resolverPlugin);
    expect(resolveId).not.toBeNull();

    expect(
      resolveId?.('@/lib/utils', '/tmp/host/components/card/index.tsx'),
    ).toBe('/tmp/host/lib/utils');
  });

  it('resolves extensionless host alias directories to index files', async () => {
    const hostRoot = await makeTempDir();
    const componentEntry = path.join(
      hostRoot,
      'src/components/button/index.jsx',
    );
    await fs.mkdir(path.dirname(componentEntry), { recursive: true });
    await fs.writeFile(
      componentEntry,
      'export default function Button() {}',
      'utf-8',
    );

    const [resolverPlugin] = drupalCanvasCompat({
      hostRoot,
    });
    const resolveId = getResolveIdHook(resolverPlugin);
    expect(resolveId).not.toBeNull();

    expect(
      resolveId?.(
        '@/components/button',
        `${hostRoot}/src/components/card/index.jsx`,
      ),
    ).toBe(componentEntry);
  });

  it('resolves component imports through configured componentDir', async () => {
    const hostRoot = await makeTempDir();
    const componentEntry = path.join(hostRoot, 'app/widgets/button/index.jsx');
    await fs.writeFile(
      path.join(hostRoot, 'canvas.config.json'),
      JSON.stringify({
        aliasBaseDir: 'app',
        componentDir: 'app/widgets',
      }),
      'utf-8',
    );
    await fs.mkdir(path.dirname(componentEntry), { recursive: true });
    await fs.writeFile(
      componentEntry,
      'export default function Button() {}',
      'utf-8',
    );

    const [resolverPlugin] = drupalCanvasCompat({
      hostRoot,
    });
    const resolveId = getResolveIdHook(resolverPlugin);
    expect(resolveId).not.toBeNull();

    expect(
      resolveId?.(
        '@/components/button',
        `${hostRoot}/app/widgets/card/index.jsx`,
      ),
    ).toBe(componentEntry);
  });

  it('maps relative component assets under configured componentDir to the components namespace', async () => {
    const hostRoot = await makeTempDir();
    const componentEntry = path.join(hostRoot, 'app/widgets/card/index.tsx');
    const imagePath = path.join(hostRoot, 'app/widgets/card/hero.webp');
    await fs.writeFile(
      path.join(hostRoot, 'canvas.config.json'),
      JSON.stringify({
        aliasBaseDir: 'app',
        componentDir: 'app/widgets',
      }),
      'utf-8',
    );
    await fs.mkdir(path.dirname(componentEntry), { recursive: true });
    await fs.writeFile(imagePath, 'image', 'utf-8');

    const result = rewriteCanvasAssetImports(
      "import heroUrl from './hero.webp';",
      {
        filePath: componentEntry,
        hostRoot,
        hostAliasBaseDir: 'app',
      },
    );

    expect(result.code).toContain(
      'const heroUrl = import.meta.resolve("@/components/card/hero.webp");',
    );
    expect(result.assets).toEqual([
      {
        specifier: '@/components/card/hero.webp',
        filePath: imagePath,
      },
    ]);
  });

  it('rejects componentDir outside aliasBaseDir', async () => {
    const hostRoot = await makeTempDir();
    await fs.writeFile(
      path.join(hostRoot, 'canvas.config.json'),
      JSON.stringify({
        aliasBaseDir: 'app',
        componentDir: 'components',
      }),
      'utf-8',
    );

    expect(() =>
      drupalCanvasCompat({
        hostRoot,
      }),
    ).toThrow(
      'Invalid Canvas config: componentDir "components" must be inside aliasBaseDir "app".',
    );
  });

  it('does not intercept third-party imports', () => {
    const [resolverPlugin] = drupalCanvasCompat({
      hostRoot: '/tmp/host',
    });
    const resolveId = getResolveIdHook(resolverPlugin);
    expect(resolveId).not.toBeNull();

    expect(
      resolveId?.('motion/react', '/tmp/host/components/card/index.tsx'),
    ).toBeNull();
    expect(
      resolveId?.('@fontsource/inter', '/tmp/host/components/card/index.tsx'),
    ).toBeNull();
  });

  it('reuses the host vite plugin for html bootstrap', async () => {
    const hostRoot = await makeTempDir();
    await fs.writeFile(
      path.join(hostRoot, '.env'),
      [
        'CANVAS_SITE_URL=http://canvas.ddev.site',
        'CANVAS_JSONAPI_PREFIX=api',
      ].join('\n'),
      'utf-8',
    );

    const plugins = drupalCanvasCompat({
      hostRoot,
    });
    const drupalCanvasPlugin = plugins.find(
      (plugin) => plugin.name === 'drupal-canvas',
    );
    expect(drupalCanvasPlugin).toBeDefined();

    await withWorkingDirectory(hostRoot, async () => {
      const config = drupalCanvasPlugin?.config as
        | ((config: Record<string, unknown>, env: { mode: string }) => unknown)
        | undefined;
      config?.(
        {
          root: '/tmp/workbench-client',
        },
        { mode: 'development' },
      );

      const transformIndexHtml = drupalCanvasPlugin?.transformIndexHtml as
        | ((html: string) => { tags?: Array<{ children?: string }> })
        | undefined;
      const transformed = transformIndexHtml?.('<html></html>');
      expect(transformed?.tags).toBeDefined();
      expect(transformed?.tags?.[0]?.children).toContain(
        'http://canvas.ddev.site',
      );
      expect(transformed?.tags?.[0]?.children).toContain('"api"');
    });
  });

  it('always adds svgr plugin', () => {
    const plugins = drupalCanvasCompat({
      hostRoot: '/tmp/host',
    });
    const pluginNames = plugins.map((plugin) => plugin.name);
    expect(pluginNames).toContain('canvas-vite-compat-host-alias');
    expect(pluginNames).toContain('drupal-canvas');
    expect(pluginNames.some((name) => name.includes('svgr'))).toBe(true);
  });

  it('enables host alias and svgr by default', () => {
    const plugins = drupalCanvasCompat({
      hostRoot: '/tmp/host',
    });
    const pluginNames = plugins.map((plugin) => plugin.name);
    expect(pluginNames).toContain('canvas-vite-compat-host-alias');
    expect(pluginNames).toContain('drupal-canvas');
    expect(pluginNames.some((name) => name.includes('svgr'))).toBe(true);

    const resolveId = getResolveIdHook(plugins[0]);
    expect(
      resolveId?.('@/lib/utils', '/tmp/host/components/card/index.tsx'),
    ).toBe('/tmp/host/src/lib/utils');
  });
});
