import { createRequire } from 'node:module';
import path from 'node:path';
import { loadEnv, normalizePath } from 'vite';
import {
  drupalCanvasCompat,
  drupalCanvasCompatServer,
} from '@drupal-canvas/vite-compat';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';

import { createAuthProxy } from './auth-proxy';
import { createWorkbenchPlugin } from './create-workbench-plugin';
import { resolveWorkbenchPaths } from './paths';

import type { UserConfig } from 'vite';

export interface CreateWorkbenchConfigOptions {
  clientRootRelativePath?: string;
  useWorkbenchSourceAlias?: boolean;
}

export async function createWorkbenchConfig(
  options: CreateWorkbenchConfigOptions,
): Promise<UserConfig> {
  const paths = resolveWorkbenchPaths({
    moduleUrl: import.meta.url,
    clientRootRelativePath: options.clientRootRelativePath,
  });
  const env = loadEnv('development', process.cwd(), 'CANVAS_');
  const siteUrl = env.CANVAS_SITE_URL || undefined;
  const authProxyEntry = siteUrl
    ? await createAuthProxy(siteUrl, env)
    : undefined;
  const require = createRequire(import.meta.url);
  // Workbench owns its React runtime. Resolve both packages from this package's
  // install tree so the app does not mix host React with Workbench React, and
  // keep the package.json versions exact because React requires an exact
  // react/react-dom match at runtime.
  const reactPackageRoot = path.dirname(require.resolve('react/package.json'));
  const reactDomPackageRoot = path.dirname(
    require.resolve('react-dom/package.json'),
  );

  return {
    root: paths.clientRoot,
    server: {
      ...drupalCanvasCompatServer({
        hostRoot: paths.hostProjectRoot,
      }),
      fs: {
        allow: paths.allowedFsRoots,
      },
      ...(siteUrl && authProxyEntry
        ? {
            proxy: {
              '/sites/': {
                target: siteUrl,
                changeOrigin: true,
              },
              '/canvas/api/': authProxyEntry(siteUrl),
              '/session/token': authProxyEntry(siteUrl),
            },
          }
        : {}),
    },
    optimizeDeps: {
      // Scan both sides of runtime-only imports before serving the preview.
      // Late discovery can otherwise split React across optimizer generations.
      // Keep the client root separate: a glob rooted above Vite's root can
      // omit its descendants. Published @wb modules also need explicit entries
      // because the scanner does not crawl TSX dependencies in node_modules.
      entries: [
        path.join(paths.clientRoot, '**/*.{html,ts,tsx}'),
        path.join(paths.workbenchSourceRoot, 'lib/**/*.ts'),
        // Discover optional dependencies from host imports, not a fixed list
        // that would require every project to install them.
        path.join(paths.componentDiscoveryRoot, '**/*.{js,jsx,ts,tsx,mjs}'),
        `!${path.join(paths.componentDiscoveryRoot, '**/*.{test,spec}.{js,jsx,ts,tsx,mjs}')}`,
        `!${path.join(paths.componentDiscoveryRoot, '**/__tests__/**')}`,
        `!${path.join(paths.clientRoot, '**/*.{test,spec}.{ts,tsx}')}`,
        `!${path.join(paths.workbenchSourceRoot, 'lib/**/*.{test,spec}.ts')}`,
      ].map(normalizePath),
      // Base UI imports these CommonJS shim subpaths from ESM files. Prebundle
      // them so Vite does not serve the raw shim files to the browser.
      include: [
        'use-sync-external-store/shim',
        'use-sync-external-store/shim/with-selector',
      ],
      exclude: ['next-image-standalone'],
    },
    plugins: [
      createWorkbenchPlugin(paths),
      react(),
      tailwindcss(),
      ...drupalCanvasCompat({
        hostRoot: paths.hostProjectRoot,
      }),
    ] as any,
    resolve: {
      dedupe: [
        'react',
        'react-dom',
        'react-dom/client',
        'react/jsx-runtime',
        'react/jsx-dev-runtime',
      ],
      alias: {
        ...(options.useWorkbenchSourceAlias
          ? {
              '@wb': paths.workbenchSourceRoot,
            }
          : {}),
        'react-dom/client': path.join(reactDomPackageRoot, 'client.js'),
        'react/jsx-runtime': path.join(reactPackageRoot, 'jsx-runtime.js'),
        'react/jsx-dev-runtime': path.join(
          reactPackageRoot,
          'jsx-dev-runtime.js',
        ),
        react: reactPackageRoot,
        'react-dom': reactDomPackageRoot,
      },
    },
  };
}
