import { promises as fs } from 'node:fs';
import path from 'node:path';
import { discoverCanvasProject } from '@drupal-canvas/discovery';
import {
  ensureHostGlobalCssExists,
  extractComponentPreviewMetadataFromComponentYaml,
  getWorkbenchHostGlobalCssVirtualUrl,
} from '@drupal-canvas/vite-compat';

import { isTopLevelPageSpecPath } from '../lib/page-spec-path';
import { buildPreviewManifest } from '../lib/preview-contract';
import {
  toDiscoveredPageName,
  toPreviewManifestComponentMocks,
  toPreviewPageSpec,
} from '../lib/spec-discovery';

import type { IncomingMessage, ServerResponse } from 'node:http';
import type { DiscoveryResult } from '@drupal-canvas/discovery';
import type { Plugin } from 'vite';
import type {
  PreviewManifestComponent,
  PreviewManifestComponentMock,
  PreviewWarning,
} from '../lib/preview-contract';
import type { WorkbenchPaths } from './paths';

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

function isMockSpecPath(filePath: string): boolean {
  const normalizedPath = filePath.replaceAll('\\', '/');
  return (
    /(^|\/)mocks\.json$/.test(normalizedPath) ||
    /(^|\/)[^/]+\.mocks\.json$/.test(normalizedPath)
  );
}

/**
 * Whether to serve the Workbench shell index.html for a dev-server request.
 * Mirrors SPA fallback: any extensionless path except APIs, Vite internals, and
 * non-HTML entries under /canvas/ (so /canvas/workbench-preview.html still falls
 * through to the static file handler).
 */
function shouldServeWorkbenchIndexHtml(requestUrl: string): boolean {
  const pathname = new URL(requestUrl, 'http://localhost').pathname;

  if (pathname.startsWith('/__canvas/')) {
    return false;
  }

  if (pathname.startsWith('/@') || pathname.startsWith('/node_modules/')) {
    return false;
  }

  if (pathname.startsWith('/__')) {
    return false;
  }

  if (pathname.startsWith('/canvas/') && !pathname.endsWith('.html')) {
    return false;
  }

  if (path.extname(pathname) !== '') {
    return false;
  }

  return true;
}

function toExpectedMockPaths(component: PreviewManifestComponent): string[] {
  const componentDirectory = path.dirname(component.metadataPath);
  const metadataFilename = path.basename(component.metadataPath);

  if (metadataFilename === 'component.yml') {
    return [path.join(componentDirectory, 'mocks.json')];
  }

  const namedComponentBase = metadataFilename.replace(/\.component\.yml$/, '');
  return [path.join(componentDirectory, `${namedComponentBase}.mocks.json`)];
}

async function loadComponentMocks(
  component: PreviewManifestComponent,
  componentRoot: string,
  allComponentNames: string[],
  componentExampleProps: Record<string, unknown>,
  componentRequiredPropNames: string[],
): Promise<{
  mocks: PreviewManifestComponentMock[];
  warnings: PreviewWarning[];
}> {
  const expectedMockPaths = toExpectedMockPaths(component);
  const mocks: PreviewManifestComponentMock[] = [];
  const warnings: PreviewWarning[] = [];

  for (const mockPath of expectedMockPaths) {
    let fileContent: string;
    try {
      fileContent = await fs.readFile(mockPath, 'utf-8');
    } catch {
      continue;
    }

    let parsedJson: unknown;
    try {
      parsedJson = JSON.parse(fileContent);
    } catch {
      warnings.push({
        code: 'invalid_mock_json',
        message: `Failed to parse mock JSON file: ${mockPath}`,
        path: mockPath,
      });
      continue;
    }

    const parsed = toPreviewManifestComponentMocks(parsedJson, {
      sourcePath: mockPath,
      componentRoot,
      componentName: component.name,
      componentNames: allComponentNames,
      componentExampleProps,
      componentRequiredPropNames,
    });
    warnings.push(...parsed.warnings);
    mocks.push(...parsed.mocks);
  }

  return { mocks, warnings };
}

async function loadPageTitle(pagePath: string): Promise<string | null> {
  let fileContent: string;
  try {
    fileContent = await fs.readFile(pagePath, 'utf-8');
  } catch {
    return null;
  }

  let parsedJson: unknown;
  try {
    parsedJson = JSON.parse(fileContent);
  } catch {
    return null;
  }

  return toDiscoveredPageName(
    parsedJson,
    pagePath,
    path.basename(pagePath, '.json'),
  );
}

async function enrichDiscoveredPages(discoveryResult: DiscoveryResult) {
  const pages = await Promise.all(
    discoveryResult.pages.map(async (page) => ({
      ...page,
      name: (await loadPageTitle(page.path)) ?? page.name,
    })),
  );

  return {
    ...discoveryResult,
    pages,
  };
}

async function loadPreviewPageSpec(
  discoveryResult: DiscoveryResult,
  slug: string,
): Promise<{
  spec: unknown | null;
  status: number;
  error: string | null;
}> {
  const page = discoveryResult.pages.find(
    (candidate) => candidate.slug === slug,
  );
  if (!page) {
    return {
      spec: null,
      status: 404,
      error: `No page found for slug "${slug}".`,
    };
  }

  let fileContent: string;
  try {
    fileContent = await fs.readFile(page.path, 'utf-8');
  } catch {
    return {
      spec: null,
      status: 404,
      error: `Failed to read page file: ${page.path}`,
    };
  }

  let parsedJson: unknown;
  try {
    parsedJson = JSON.parse(fileContent);
  } catch {
    return {
      spec: null,
      status: 400,
      error: `Failed to parse page JSON file: ${page.path}`,
    };
  }

  const parsedPage = toPreviewPageSpec(parsedJson, {
    sourcePath: page.path,
    componentNames: discoveryResult.components.map(
      (component) => component.name,
    ),
  });
  if (!parsedPage.spec) {
    return {
      spec: null,
      status: 400,
      error:
        parsedPage.issues[0]?.message ?? `Page spec is invalid: ${page.path}`,
    };
  }

  return {
    spec: parsedPage.spec,
    status: 200,
    error: null,
  };
}

async function loadWorkbenchHtmlTemplate(
  appHtmlPath: string,
  url: string,
  transformIndexHtml: (url: string, html: string) => Promise<string>,
): Promise<string> {
  const html = await fs.readFile(appHtmlPath, 'utf-8');
  return transformIndexHtml(url, html);
}

export function createWorkbenchPlugin(paths: WorkbenchPaths): Plugin {
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
      cachedResult = await discoverCanvasProject({
        componentRoot: paths.componentDiscoveryRoot,
        pagesRoot: paths.pagesDiscoveryRoot,
        projectRoot: paths.hostProjectRoot,
      });
    })();

    try {
      await refreshTask;
    } finally {
      refreshTask = null;
    }
  };

  return {
    name: 'canvas-workbench-discovery',
    enforce: 'pre',
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

      const normalizedHostRoot = paths.hostProjectRoot.replaceAll('\\', '/');
      const normalizedGlobalCssPath = hostGlobalCssPath.replaceAll('\\', '/');

      return [
        `@import "${normalizedGlobalCssPath}";`,
        `@source "${normalizedHostRoot}/src/**/*.{js,jsx,ts,tsx,html}";`,
      ].join('\n');
    },
    async configureServer(server) {
      if (!paths.runningInsideWorkbenchPackage) {
        hostGlobalCssPath = await ensureHostGlobalCssExists(
          paths.hostProjectRoot,
        );
      }
      await refresh();

      server.watcher.add(paths.watchRoots);

      const workbenchIndexHtmlMiddleware = (
        req: IncomingMessage,
        res: ServerResponse,
        next: (err?: unknown) => void,
      ) => {
        if (
          req.method !== 'GET' ||
          !req.url ||
          !shouldServeWorkbenchIndexHtml(req.url)
        ) {
          next();
          return;
        }

        void (async () => {
          const transformed = await loadWorkbenchHtmlTemplate(
            paths.appHtmlPath,
            req.url!,
            (url, html) => server.transformIndexHtml(url, html),
          );
          res.statusCode = 200;
          res.setHeader('Content-Type', 'text/html');
          res.end(transformed);
        })().catch((error) => {
          server.config.logger.error(
            `Failed to serve Workbench app HTML: ${String(error)}`,
          );
          next(error);
        });
      };

      // Register first so this plugin runs before other plugins' configureServer
      // middleware; avoids mutating connect's stack with unshift (fragile in some
      // Vite versions).
      server.middlewares.use(workbenchIndexHtmlMiddleware);

      server.middlewares.use('/__canvas/discovery', (_req, res) => {
        void (async () => {
          await refresh();

          const responseResult = await enrichDiscoveredPages(cachedResult!);

          res.statusCode = 200;
          res.setHeader('Content-Type', 'application/json');
          res.end(JSON.stringify(responseResult));
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
          const discoveredComponentNames = manifest.components.map(
            (component) => component.name,
          );
          const componentMocksAndExamples = await Promise.all(
            manifest.components.map(async (component) => {
              const componentPreviewMetadata =
                await extractComponentPreviewMetadataFromComponentYaml(
                  component.metadataPath,
                );
              const exampleProps = componentPreviewMetadata.exampleProps;
              const { mocks, warnings } = await loadComponentMocks(
                component,
                manifest.componentRoot,
                discoveredComponentNames,
                exampleProps,
                componentPreviewMetadata.requiredPropNames,
              );
              return {
                component: {
                  ...component,
                  label: componentPreviewMetadata.label ?? component.label,
                  exampleProps,
                  mocks,
                },
                warnings,
              };
            }),
          );

          manifest.components = componentMocksAndExamples.map(
            ({ component }) => component,
          );
          manifest.warnings = [
            ...manifest.warnings,
            ...componentMocksAndExamples.flatMap(({ warnings }) => warnings),
          ];

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

      server.middlewares.use(
        '/__canvas/page-preview-spec',
        (req, res, next) => {
          if (req.method !== 'GET') {
            next();
            return;
          }

          void (async () => {
            await refresh();

            const requestUrl = new URL(req.url ?? '', 'http://localhost');
            const slug = requestUrl.searchParams.get('slug');
            if (!slug) {
              res.statusCode = 400;
              res.setHeader('Content-Type', 'application/json');
              res.end(
                JSON.stringify({ error: 'Missing required slug parameter.' }),
              );
              return;
            }

            const pageResult = await loadPreviewPageSpec(cachedResult!, slug);
            res.statusCode = pageResult.status;
            res.setHeader('Content-Type', 'application/json');
            res.end(
              JSON.stringify(
                pageResult.error
                  ? { error: pageResult.error }
                  : pageResult.spec,
              ),
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
        },
      );

      server.watcher.on('all', (event, filePath) => {
        if (!['add', 'change', 'unlink'].includes(event)) {
          return;
        }

        const metadataChanged = isComponentMetadataPath(filePath);
        const sourceChanged = isPreviewSourcePath(filePath);
        const pageChanged = isTopLevelPageSpecPath(filePath);
        const mockChanged = isMockSpecPath(filePath);
        if (
          !metadataChanged &&
          !sourceChanged &&
          !pageChanged &&
          !mockChanged
        ) {
          return;
        }

        const requiresManifestRefresh =
          metadataChanged ||
          mockChanged ||
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
