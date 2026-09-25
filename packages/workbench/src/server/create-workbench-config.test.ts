import { promises as fs } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { optimizeDeps, resolveConfig } from 'vite';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { createWorkbenchConfig } from './create-workbench-config';

const { paths } = vi.hoisted(() => ({ paths: {} as Record<string, unknown> }));
vi.mock('./paths', () => ({ resolveWorkbenchPaths: () => paths }));
vi.mock('./create-workbench-plugin', () => ({
  createWorkbenchPlugin: () => ({ name: 'test-workbench' }),
}));
vi.mock('./auth-proxy', () => ({ createAuthProxy: () => undefined }));

const directories: string[] = [];
afterEach(async () => {
  await Promise.all(
    directories
      .splice(0)
      .map((dir) => fs.rm(dir, { recursive: true, force: true })),
  );
});

async function write(root: string, name: string, contents: string) {
  const file = path.join(root, name);
  await fs.mkdir(path.dirname(file), { recursive: true });
  await fs.writeFile(file, contents);
}

describe('Workbench initial dependency scan', () => {
  it.each([false, true])(
    'scans published client modules and host imports (optional dependency: %s)',
    async (optional) => {
      const root = await fs.mkdtemp(path.join(os.tmpdir(), 'workbench-scan-'));
      directories.push(root);
      const source = path.join(root, 'node_modules/workbench/dist/client/src');
      Object.assign(paths, {
        clientRoot: path.join(source, 'client'),
        workbenchSourceRoot: source,
        componentDiscoveryRoot: path.join(root, 'custom/components'),
        hostProjectRoot: root,
        allowedFsRoots: [root],
      });
      await write(
        source,
        'client/index.html',
        '<script type="module" src="/main.tsx"></script>',
      );
      await write(
        source,
        'client/main.tsx',
        'import "@wb/client/nested/view";',
      );
      await write(
        source,
        'client/nested/view.tsx',
        'import "@wb/lib/utils"; import "@wb/client/hooks/use-mobile";',
      );
      await write(
        source,
        'client/hooks/use-mobile.ts',
        'export const mobile = false;',
      );
      await write(source, 'lib/utils.ts', 'export const util = true;');
      await write(source, 'client/view.test.tsx', 'import "not-installed";');
      await write(source, 'lib/utils.test.ts', 'import "not-installed";');
      await write(
        root,
        'custom/components/example.jsx',
        optional ? 'import "optional-fetcher";' : 'export default () => null;',
      );
      // Test-only dependencies must not become required by the preview scan.
      await write(
        root,
        'custom/components/example.test.ts',
        'import "not-installed";',
      );
      if (optional) {
        await write(
          root,
          'node_modules/optional-fetcher/package.json',
          JSON.stringify({ name: 'optional-fetcher', main: 'index.js' }),
        );
        await write(
          root,
          'node_modules/optional-fetcher/index.js',
          'module.exports = {};',
        );
      }
      const config = await createWorkbenchConfig({
        useWorkbenchSourceAlias: true,
      });
      expect(config.optimizeDeps?.include).not.toContain('optional-fetcher');
      expect(config.resolve?.dedupe).toContain('react');
      // Exercise Vite's real scanner, including its node_modules entry handling.
      // Plugins and the CJS shim includes are unrelated to this fixture.
      const resolved = await resolveConfig(
        {
          ...config,
          configFile: false,
          plugins: [],
          cacheDir: path.join(root, 'cache'),
          optimizeDeps: { ...config.optimizeDeps, include: [] },
          logLevel: 'silent',
        },
        'serve',
      );
      const metadata = await optimizeDeps(resolved);
      expect(metadata.optimized).toHaveProperty('@wb/lib/utils');
      expect(metadata.optimized).toHaveProperty('@wb/client/hooks/use-mobile');
      expect(Object.keys(metadata.optimized).includes('optional-fetcher')).toBe(
        optional,
      );
    },
  );
});
