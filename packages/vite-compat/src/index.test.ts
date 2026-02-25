import { promises as fs } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { afterEach, describe, expect, it } from 'vitest';

import {
  drupalCanvasCompat,
  drupalCanvasCompatServer,
  ensureHardcodedHostGlobalCssExists,
  extractFirstExamplePropsFromComponentYaml,
  getWorkbenchHostGlobalCssVirtualUrl,
  isSupportedPreviewModulePath,
  resolveHardcodedHostGlobalCssPath,
  toViteFsUrl,
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

  it('converts absolute paths to Vite @fs URLs', () => {
    expect(toViteFsUrl('/tmp/example/file.tsx')).toBe(
      '/@fs/tmp/example/file.tsx',
    );
  });

  it('checks supported preview module extensions', () => {
    expect(isSupportedPreviewModulePath('/tmp/a.tsx')).toBe(true);
    expect(isSupportedPreviewModulePath('/tmp/a.jpg')).toBe(false);
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

  it('resolves hardcoded host global css path', () => {
    const resolved = resolveHardcodedHostGlobalCssPath('/tmp/host');
    expect(resolved).toBe('/tmp/host/src/components/global.css');
  });

  it('resolves hardcoded host global css path with custom alias base dir', () => {
    const resolved = resolveHardcodedHostGlobalCssPath('/tmp/host', 'app');
    expect(resolved).toBe('/tmp/host/app/components/global.css');
  });

  it('validates hardcoded host global css existence', async () => {
    const root = await makeTempDir();
    const cssPath = path.join(root, 'src/components/global.css');
    await fs.mkdir(path.dirname(cssPath), { recursive: true });
    await fs.writeFile(cssPath, '@import "tailwindcss";', 'utf-8');

    const resolved = await ensureHardcodedHostGlobalCssExists(root);
    expect(resolved).toBe(cssPath);
  });

  it('throws when hardcoded host global css is missing', async () => {
    const root = await makeTempDir();
    await expect(ensureHardcodedHostGlobalCssExists(root)).rejects.toThrow(
      'Missing required host Tailwind entrypoint',
    );
  });

  it('builds @fs URL for hardcoded host css path', () => {
    const resolvedPath = resolveHardcodedHostGlobalCssPath('/tmp/host');
    expect(toViteFsUrl(resolvedPath)).toBe(
      '/@fs/tmp/host/src/components/global.css',
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

  it('always adds svgr plugin', () => {
    const plugins = drupalCanvasCompat({
      hostRoot: '/tmp/host',
    });
    const pluginNames = plugins.map((plugin) => plugin.name);
    expect(pluginNames).toContain('canvas-vite-compat-host-alias');
    expect(pluginNames.some((name) => name.includes('svgr'))).toBe(true);
  });

  it('enables host alias and svgr by default', () => {
    const plugins = drupalCanvasCompat({
      hostRoot: '/tmp/host',
    });
    const pluginNames = plugins.map((plugin) => plugin.name);
    expect(pluginNames).toContain('canvas-vite-compat-host-alias');
    expect(pluginNames.some((name) => name.includes('svgr'))).toBe(true);

    const resolveId = getResolveIdHook(plugins[0]);
    expect(
      resolveId?.('@/lib/utils', '/tmp/host/components/card/index.tsx'),
    ).toBe('/tmp/host/src/lib/utils');
  });
});
