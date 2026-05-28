import { promises as fs } from 'node:fs';
import path from 'node:path';
import {
  discoverCanvasProject,
  loadComponentsMetadata,
} from '@drupal-canvas/discovery';
import {
  ensureHostGlobalCssExists,
  extractComponentPreviewMetadataFromComponentYaml,
  getWorkbenchHostGlobalCssVirtualUrl,
} from '@drupal-canvas/vite-compat';

import { isTopLevelContentTemplateSpecPath } from '../lib/content-template-spec-path';
import {
  isTopLevelPageSpecPath,
  isTopLevelRegionSpecPath,
} from '../lib/page-spec-path';
import { buildPreviewManifest } from '../lib/preview-contract';
import { parseRegionSpec } from '../lib/region-spec';
import {
  toDiscoveredContentTemplateName,
  toDiscoveredPageName,
  toPreviewContentTemplateSpec,
  toPreviewManifestComponentMocks,
  toPreviewPageSpec,
} from '../lib/spec-discovery';

import type { IncomingMessage, ServerResponse } from 'node:http';
import type { DiscoveryResult } from '@drupal-canvas/discovery';
import type { Plugin } from 'vite';
import type { EnrichedDiscoveredPage } from '../lib/discovery-client';
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

interface PageFileMetadata {
  title: string | null;
  path: string | null;
}

async function loadPageFileMetadata(
  filePath: string,
): Promise<PageFileMetadata> {
  let fileContent: string;
  try {
    fileContent = await fs.readFile(filePath, 'utf-8');
  } catch {
    return { title: null, path: null };
  }

  let parsedJson: unknown;
  try {
    parsedJson = JSON.parse(fileContent);
  } catch {
    return { title: null, path: null };
  }

  const title = toDiscoveredPageName(
    parsedJson,
    filePath,
    path.basename(filePath, '.json'),
  );

  const obj = parsedJson as Record<string, unknown>;
  let pagePath = typeof obj.path === 'string' && obj.path ? obj.path : null;
  if (pagePath && !pagePath.startsWith('/')) {
    pagePath = `/${pagePath}`;
  }

  return { title, path: pagePath };
}

async function enrichDiscoveredPages(discoveryResult: DiscoveryResult) {
  const pages: EnrichedDiscoveredPage[] = await Promise.all(
    discoveryResult.pages.map(async (page) => {
      const metadata = await loadPageFileMetadata(page.path);
      return {
        ...page,
        name: metadata.title ?? page.name,
        pagePath: metadata.path,
      };
    }),
  );

  return {
    ...discoveryResult,
    pages,
  };
}

async function loadContentTemplateName(
  templatePath: string,
): Promise<string | null> {
  let fileContent: string;
  try {
    fileContent = await fs.readFile(templatePath, 'utf-8');
  } catch {
    return null;
  }

  let parsedJson: unknown;
  try {
    parsedJson = JSON.parse(fileContent);
  } catch {
    return null;
  }

  return toDiscoveredContentTemplateName(
    parsedJson,
    templatePath,
    path.basename(templatePath, '.json'),
  );
}

async function enrichDiscoveredContentTemplates<
  T extends { contentTemplates: DiscoveryResult['contentTemplates'] },
>(discoveryResult: T): Promise<T> {
  const contentTemplates = await Promise.all(
    discoveryResult.contentTemplates.map(async (template) => ({
      ...template,
      name: (await loadContentTemplateName(template.path)) ?? template.name,
    })),
  );

  return {
    ...discoveryResult,
    contentTemplates,
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

async function loadPreviewContentTemplateSpec(
  discoveryResult: DiscoveryResult,
  slug: string,
): Promise<{
  spec: unknown | null;
  metadata: {
    label: string;
    entityTypeId: string;
    bundle: string;
    viewMode: string;
  } | null;
  status: number;
  error: string | null;
}> {
  const template = discoveryResult.contentTemplates.find(
    (candidate) => candidate.slug === slug,
  );
  if (!template) {
    return {
      spec: null,
      metadata: null,
      status: 404,
      error: `No content template found for slug "${slug}".`,
    };
  }

  let fileContent: string;
  try {
    fileContent = await fs.readFile(template.path, 'utf-8');
  } catch {
    return {
      spec: null,
      metadata: null,
      status: 404,
      error: `Failed to read content template file: ${template.path}`,
    };
  }

  let parsedJson: unknown;
  try {
    parsedJson = JSON.parse(fileContent);
  } catch {
    return {
      spec: null,
      metadata: null,
      status: 400,
      error: `Failed to parse content template JSON file: ${template.path}`,
    };
  }

  const parsedTemplate = toPreviewContentTemplateSpec(parsedJson, {
    sourcePath: template.path,
    componentNames: discoveryResult.components.map(
      (component) => component.name,
    ),
  });
  if (!parsedTemplate.spec || !parsedTemplate.metadata) {
    return {
      spec: null,
      metadata: null,
      status: 400,
      error:
        parsedTemplate.issues[0]?.message ??
        `Content template spec is invalid: ${template.path}`,
    };
  }

  return {
    spec: parsedTemplate.spec,
    metadata: parsedTemplate.metadata,
    status: 200,
    error: null,
  };
}

async function loadPreviewRegionSpec(
  discoveryResult: DiscoveryResult,
  id: string,
): Promise<{
  spec: unknown | null;
  status: number;
  error: string | null;
  enabled: boolean;
}> {
  const region = discoveryResult.regions.find(
    (candidate) => candidate.region === id,
  );
  if (!region) {
    return {
      spec: null,
      status: 404,
      error: `No global region found for id "${id}".`,
      enabled: true,
    };
  }

  let fileContent: string;
  try {
    fileContent = await fs.readFile(region.path, 'utf-8');
  } catch {
    return {
      spec: null,
      status: 404,
      error: `Failed to read region file: ${region.path}`,
      enabled: true,
    };
  }

  let parsedJson: unknown;
  try {
    parsedJson = JSON.parse(fileContent);
  } catch {
    return {
      spec: null,
      status: 400,
      error: `Failed to parse region JSON file: ${region.path}`,
      enabled: true,
    };
  }

  const parsedRegion = parseRegionSpec(parsedJson, region.path, {
    componentNames: discoveryResult.components.map((c) => c.name),
  });
  if (!parsedRegion.region) {
    return {
      spec: null,
      status: 400,
      error:
        parsedRegion.issues[0]?.message ??
        `Region spec is invalid: ${region.path}`,
      enabled: true,
    };
  }

  return {
    spec: parsedRegion.region.spec,
    status: 200,
    error: null,
    enabled: parsedRegion.region.status,
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
        contentTemplatesRoot: paths.contentTemplatesDiscoveryRoot,
        regionsRoot: paths.regionsDiscoveryRoot,
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

          const withEnrichedPages = await enrichDiscoveredPages(cachedResult!);
          const responseResult =
            await enrichDiscoveredContentTemplates(withEnrichedPages);

          let layoutAvailable = false;
          try {
            await fs.access(paths.layoutPath);
            layoutAvailable = true;
          } catch {
            layoutAvailable = false;
          }

          res.statusCode = 200;
          res.setHeader('Content-Type', 'application/json');
          res.end(
            JSON.stringify({
              ...responseResult,
              layoutPath: layoutAvailable ? paths.layoutPath : null,
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

      server.middlewares.use('/__canvas/workbench-config', (_req, res) => {
        res.statusCode = 200;
        res.setHeader('Content-Type', 'application/json');
        res.end(
          JSON.stringify({
            siteUrl: paths.siteUrl,
          }),
        );
      });

      server.middlewares.use('/__canvas/components-metadata', (_req, res) => {
        void (async () => {
          await refresh();
          const metadata = await loadComponentsMetadata(cachedResult!);
          const shapes: Record<
            string,
            { propKeys: string[]; slotKeys: string[] }
          > = {};
          for (const m of metadata) {
            const serverComponentId = `js.${m.machineName}`;
            shapes[serverComponentId] = {
              propKeys: Object.keys(m.props.properties ?? {}).sort(),
              slotKeys: Object.keys(m.slots ?? {}).sort(),
            };
          }
          res.statusCode = 200;
          res.setHeader('Content-Type', 'application/json');
          res.end(JSON.stringify(shapes));
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
        '/__canvas/region-preview-spec',
        (req, res, next) => {
          if (req.method !== 'GET') {
            next();
            return;
          }

          void (async () => {
            await refresh();

            const requestUrl = new URL(req.url ?? '', 'http://localhost');
            const id = requestUrl.searchParams.get('id');
            if (!id) {
              res.statusCode = 400;
              res.setHeader('Content-Type', 'application/json');
              res.end(
                JSON.stringify({ error: 'Missing required id parameter.' }),
              );
              return;
            }

            const result = await loadPreviewRegionSpec(cachedResult!, id);
            res.statusCode = result.status;
            res.setHeader('Content-Type', 'application/json');
            res.end(
              JSON.stringify(
                result.error
                  ? { error: result.error }
                  : { spec: result.spec, status: result.enabled },
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

      server.middlewares.use(
        '/__canvas/content-template-preview-spec',
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

            const templateResult = await loadPreviewContentTemplateSpec(
              cachedResult!,
              slug,
            );
            res.statusCode = templateResult.status;
            res.setHeader('Content-Type', 'application/json');
            res.end(
              JSON.stringify(
                templateResult.error
                  ? { error: templateResult.error }
                  : {
                      spec: templateResult.spec,
                      metadata: templateResult.metadata,
                    },
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
        const contentTemplateChanged =
          isTopLevelContentTemplateSpecPath(filePath);
        const regionChanged = isTopLevelRegionSpecPath(filePath);
        const layoutChanged =
          path.resolve(filePath) === path.resolve(paths.layoutPath);
        const mockChanged = isMockSpecPath(filePath);
        if (
          !metadataChanged &&
          !sourceChanged &&
          !pageChanged &&
          !contentTemplateChanged &&
          !regionChanged &&
          !layoutChanged &&
          !mockChanged
        ) {
          return;
        }

        const requiresManifestRefresh =
          metadataChanged ||
          mockChanged ||
          pageChanged ||
          contentTemplateChanged ||
          regionChanged ||
          layoutChanged ||
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
