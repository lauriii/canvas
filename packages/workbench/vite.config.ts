import { promises as fs } from 'node:fs';
import { createRequire } from 'node:module';
import path from 'path';
import { defineConfig } from 'vite';
import {
  drupalCanvasCompat,
  drupalCanvasCompatServer,
  ensureHardcodedHostGlobalCssExists,
  extractFirstExamplePropsFromComponentYaml,
  getWorkbenchHostGlobalCssVirtualUrl,
} from '@drupal-canvas/vite-compat';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';

import { discoverCodeComponents } from '../discovery/src/discover';
import { buildPreviewManifest } from './src/lib/preview-contract';

import type { Plugin } from 'vite';
import type { DiscoveryResult } from '../discovery/src/types';

const require = createRequire(import.meta.url);
const hostProjectRoot = process.cwd();
const runningInsideWorkbenchPackage =
  path.resolve(hostProjectRoot) === path.resolve(__dirname);
const workbenchNodeModulesPath = path.resolve(__dirname, 'node_modules');
const geistPackageRoot = path.dirname(
  require.resolve('@fontsource-variable/geist/package.json'),
);

function isComponentMetadataPath(filePath: string): boolean {
  const normalizedPath = filePath.replaceAll('\\', '/');
  return (
    /(^|\/)component\.yml$/.test(normalizedPath) ||
    /(^|\/)[^/]+\.component\.yml$/.test(normalizedPath)
  );
}

function isPreviewSourcePath(filePath: string): boolean {
  const normalizedPath = filePath.replaceAll('\\', '/');
  return /\.(js|jsx|ts|tsx|css)$/.test(normalizedPath);
}

function isTopLevelPageSpecPath(filePath: string): boolean {
  const normalizedPath = filePath.replaceAll('\\', '/');
  return /(^|\/)pages\/[^/]+\.json$/.test(normalizedPath);
}

function canvasWorkbenchDiscovery(): Plugin {
  let cachedResult: DiscoveryResult | null = null;
  let refreshTask: Promise<void> | null = null;
  let hostGlobalCssPath: string | null = null;
  const virtualHostGlobalCssId = 'virtual:canvas-host-global.css';
  const resolvedVirtualHostGlobalCssId = '\0virtual:canvas-host-global.css';

  const refresh = async () => {
    if (refreshTask) {
      await refreshTask;
      return;
    }

    refreshTask = (async () => {
      cachedResult = await discoverCodeComponents({ scanRoot: process.cwd() });
    })();

    try {
      await refreshTask;
    } finally {
      refreshTask = null;
    }
  };

  return {
    name: 'canvas-workbench-discovery',
    resolveId(source) {
      if (source === virtualHostGlobalCssId) {
        return resolvedVirtualHostGlobalCssId;
      }

      return null;
    },
    load(id) {
      if (id !== resolvedVirtualHostGlobalCssId || !hostGlobalCssPath) {
        return null;
      }

      const normalizedHostRoot = hostProjectRoot.replaceAll('\\', '/');
      const normalizedGlobalCssPath = hostGlobalCssPath.replaceAll('\\', '/');

      return [
        `@import "${normalizedGlobalCssPath}";`,
        `@source "${normalizedHostRoot}/src/**/*.{js,jsx,ts,tsx,html}";`,
      ].join('\n');
    },
    async configureServer(server) {
      if (!runningInsideWorkbenchPackage) {
        hostGlobalCssPath =
          await ensureHardcodedHostGlobalCssExists(hostProjectRoot);
      }
      await refresh();

      server.watcher.add(hostProjectRoot);

      server.middlewares.use('/__canvas/discovery', (_req, res) => {
        void (async () => {
          await refresh();

          res.statusCode = 200;
          res.setHeader('Content-Type', 'application/json');
          res.end(JSON.stringify(cachedResult));
        })().catch((error) => {
          res.statusCode = 500;
          res.setHeader('Content-Type', 'application/json');
          res.end(
            JSON.stringify({
              error: error instanceof Error ? error.message : String(error),
            }),
          );
        });
      });

      server.middlewares.use('/__canvas/preview-manifest', (_req, res) => {
        void (async () => {
          await refresh();

          const manifest = buildPreviewManifest(cachedResult!);
          manifest.components = await Promise.all(
            manifest.components.map(async (component) => ({
              ...component,
              exampleProps: await extractFirstExamplePropsFromComponentYaml(
                component.metadataPath,
              ),
            })),
          );

          res.statusCode = 200;
          res.setHeader('Content-Type', 'application/json');
          res.end(
            JSON.stringify({
              ...manifest,
              globalCssUrl: hostGlobalCssPath
                ? getWorkbenchHostGlobalCssVirtualUrl()
                : null,
            }),
          );
        })().catch((error) => {
          res.statusCode = 500;
          res.setHeader('Content-Type', 'application/json');
          res.end(
            JSON.stringify({
              error: error instanceof Error ? error.message : String(error),
            }),
          );
        });
      });

      server.middlewares.use('/__canvas/preview-frame', (req, res, next) => {
        if (req.method !== 'GET') {
          next();
          return;
        }

        void (async () => {
          const indexPath = path.resolve(__dirname, 'index.html');
          const html = await fs.readFile(indexPath, 'utf-8');
          const transformed = await server.transformIndexHtml(req.url!, html);
          res.statusCode = 200;
          res.setHeader('Content-Type', 'text/html');
          res.end(transformed);
        })().catch((error) => {
          server.config.logger.error(
            `Failed to serve preview frame HTML: ${String(error)}`,
          );
          next(error);
        });
      });

      server.watcher.on('all', (event, filePath) => {
        if (!['add', 'change', 'unlink'].includes(event)) {
          return;
        }

        const metadataChanged = isComponentMetadataPath(filePath);
        const sourceChanged = isPreviewSourcePath(filePath);
        const pageChanged = isTopLevelPageSpecPath(filePath);
        if (!metadataChanged && !sourceChanged && !pageChanged) {
          return;
        }

        const requiresManifestRefresh =
          metadataChanged ||
          pageChanged ||
          (sourceChanged && event !== 'change');
        if (!requiresManifestRefresh) {
          server.ws.send({
            type: 'custom',
            event: 'canvas:workbench:update',
            data: {
              reloadFrameOnly: true,
              filePath,
              event,
            },
          });
          return;
        }

        void refresh()
          .then(() => {
            server.ws.send({
              type: 'custom',
              event: 'canvas:workbench:update',
              data: {
                reloadFrameOnly: false,
                filePath,
                event,
              },
            });
          })
          .catch((error) => {
            server.config.logger.error(
              `Failed to refresh discovery: ${String(error)}`,
            );
          });
      });
    },
  };
}

// https://vite.dev/config/
export default defineConfig({
  root: __dirname,
  server: {
    ...drupalCanvasCompatServer({
      hostRoot: hostProjectRoot,
    }),
    fs: {
      allow: [hostProjectRoot, __dirname, geistPackageRoot],
    },
  },
  optimizeDeps: {
    // Keep next-image-standalone out of prebundling to avoid embedding a
    // separate React runtime that can break hooks in iframe previews.
    // Keep drupal-canvas optimized so CJS interop (jsona) remains stable.
    exclude: ['next-image-standalone'],
  },
  plugins: [
    react(),
    tailwindcss(),
    ...drupalCanvasCompat({
      hostRoot: hostProjectRoot,
    }),
    canvasWorkbenchDiscovery(),
  ] as any,
  resolve: {
    dedupe: [
      'react',
      'react-dom',
      'react/jsx-runtime',
      'react/jsx-dev-runtime',
    ],
    alias: {
      '@wb': path.resolve(__dirname, './src'),
      react: path.join(workbenchNodeModulesPath, 'react'),
      'react-dom': path.join(workbenchNodeModulesPath, 'react-dom'),
      'react/jsx-runtime': path.join(
        workbenchNodeModulesPath,
        'react/jsx-runtime.js',
      ),
      'react/jsx-dev-runtime': path.join(
        workbenchNodeModulesPath,
        'react/jsx-dev-runtime.js',
      ),
    },
  },
});
