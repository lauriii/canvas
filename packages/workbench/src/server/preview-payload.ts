import { promises as fs } from 'node:fs';
import { createRequire } from 'node:module';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { canvasTreeToSpec } from 'drupal-canvas/json-render-utils';
import { loadEnv, build as viteBuild } from 'vite';
import {
  buildBrandKitColorCss,
  discoverCanvasProject,
  readBrandKitColors,
  resolveCanvasConfig,
} from '@drupal-canvas/discovery';
import {
  createCanvasViteBuildConfig,
  extractComponentPreviewMetadataFromComponentYaml,
  resolvePageColorPropsForPreview,
  validateCanvasImportRoots,
} from '@drupal-canvas/vite-compat';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';

import { selectPageTemplate } from '../lib/page-template-selection';
import { parsePageTemplateSpec } from '../lib/page-template-spec';
import { isSupportedPreviewModulePath } from '../lib/preview-runtime';
import { toPreviewPageSpec } from '../lib/spec-discovery';

import type {
  DiscoveredComponent,
  DiscoveryResult,
  NormalizedBrandKitColor,
} from '@drupal-canvas/discovery';
import type { CanvasSiteData } from '@drupal-canvas/vite-plugin';
import type { Spec } from '@json-render/core';
import type { OutputAsset, OutputChunk, RollupOutput } from 'rollup';

export type PreviewMode = 'component' | 'page';

export interface PreviewIssue {
  code: string;
  message: string;
  path?: string;
}

export interface PreviewRequest {
  mode: PreviewMode;
  inputPath: string;
  projectRoot: string;
}

export interface PreviewTarget {
  id: string;
  name: string;
  projectRelativePath: string;
}

export interface PreviewPayload {
  ok: boolean;
  request: PreviewRequest;
  target: PreviewTarget | null;
  spec: Spec | null;
  css: string;
  iframeHtml: string | null;
  renderMode: 'interactive';
  warnings: PreviewIssue[];
  errors: PreviewIssue[];
}

export interface InteractiveBundleResult {
  js: string;
  css: string;
  /** The site data the Vite integration loaded during the bundle build. */
  siteData: CanvasSiteData | null;
}

export interface PreviewRuntimeSettings {
  baseUrl: string | null;
  jsonapiPrefix: string | null;
}

export function formatComponentPathConstraintMessage(options: {
  inputPath: string;
  componentDir: string;
}): string {
  return `No component found for path "${options.inputPath}". Workbench resolves --component-path only inside the configured componentDir ("${options.componentDir}"). Paths outside that directory are not supported, because preview discovery, mocks, and @/ module resolution are scoped to the configured roots. Move the component under "${options.componentDir}" or update canvas.config.json.`;
}

export function formatPagePathConstraintMessage(options: {
  inputPath: string;
  pagesDir: string;
  componentDir: string;
}): string {
  return `No page found for path "${options.inputPath}". Workbench resolves --page-path only inside the configured pagesDir ("${options.pagesDir}"). Page previews also use components discovered under componentDir ("${options.componentDir}"), so paths outside those roots are not supported. Move the page under "${options.pagesDir}" or update canvas.config.json.`;
}

interface BundleComponentSource {
  name: string;
  jsEntryPath: string;
}

interface PreparedComponentPreview {
  target: PreviewTarget;
  spec: Spec;
  bundleSources: BundleComponentSource[];
  cssEntryPaths: string[];
}

interface PreparedPagePreview {
  target: PreviewTarget;
  spec: Spec;
  pageTemplateSpec: Spec | null;
  bundleSources: BundleComponentSource[];
  cssEntryPaths: string[];
}

interface BuildPreviewPayloadDependencies {
  discover?: typeof discoverCanvasProject;
  resolveConfig?: typeof resolveCanvasConfig;
  extractMetadata?: (
    metadataPath: string,
    brandKitColors?: NormalizedBrandKitColor[],
  ) => Promise<{
    label: string | null;
    exampleProps: Record<string, unknown>;
    requiredPropNames: string[];
  }>;
  bundleInteractivePreview?: typeof bundleInteractivePreview;
}

interface BuildPreviewPayloadOptions {
  mode: PreviewMode;
  inputPath: string;
  projectRoot: string;
}

function normalizePreviewRuntimeSetting(value?: string): string | null {
  const trimmedValue = value?.trim();

  return trimmedValue ? trimmedValue : null;
}

export function resolvePreviewRuntimeSettings(
  projectRoot: string,
): PreviewRuntimeSettings {
  const env = loadEnv('', projectRoot, 'CANVAS_');

  return {
    baseUrl: normalizePreviewRuntimeSetting(env.CANVAS_SITE_URL),
    jsonapiPrefix: normalizePreviewRuntimeSetting(env.CANVAS_JSONAPI_PREFIX),
  };
}

/**
 * The Workbench context helpers, inlined into the generated entry: the entry
 * is bundled inside the host project, where this package's modules are not
 * resolvable. Mirrors `createWorkbenchContext()` and
 * `createWorkbenchJsonApiConfig()` in ../lib/workbench-context.ts.
 */
const WORKBENCH_CONTEXT_RUNTIME_SOURCE = [
  'const WORKBENCH_EMPTY_THEME_ASSETS = { logo: { url: "" }, favicon: { url: "", mimeType: "" } };',
  'function createWorkbenchContext(siteData, fallbackBaseUrl) {',
  '  return {',
  '    page: { pageTitle: "", breadcrumbs: [], mainEntity: null },',
  '    site: {',
  '      branding: (siteData && siteData.branding) || { homeUrl: "", siteName: "", siteSlogan: "" },',
  '      baseUrl: (siteData && siteData.baseUrl) || fallbackBaseUrl,',
  '      themeAssets: (siteData && siteData.themeAssets) || WORKBENCH_EMPTY_THEME_ASSETS,',
  '    },',
  '  };',
  '}',
  'function createWorkbenchJsonApiConfig(siteData, fallbackBaseUrl) {',
  '  if (siteData && siteData.jsonapiSettings === null) { return null; }',
  '  const apiPrefix = siteData && siteData.jsonapiSettings && siteData.jsonapiSettings.apiPrefix;',
  '  return {',
  '    baseUrl: (siteData && siteData.baseUrl) || fallbackBaseUrl,',
  '    ...(apiPrefix ? { apiPrefix } : {}),',
  '    resourceVersion: null,',
  '    preview: false,',
  '  };',
  '}',
].join('\n');

export function buildPreviewRuntimeEntrySource(options: {
  spec: Spec;
  pageTemplateSpec?: Spec | null;
  componentSources: BundleComponentSource[];
  cssEntryPaths: string[];
  /** The static settings; the site data module supplies the rest. */
  runtimeSettings?: PreviewRuntimeSettings;
}): string {
  const runtimeSettings = options.runtimeSettings ?? {
    baseUrl: null,
    jsonapiPrefix: null,
  };
  const componentImports = options.componentSources
    .map(
      (source, index) =>
        `import Component${index} from ${JSON.stringify(toImportPath(source.jsEntryPath))};`,
    )
    .join('\n');
  const cssImports = options.cssEntryPaths
    .map((cssPath) => `import ${JSON.stringify(toImportPath(cssPath))};`)
    .join('\n');
  const registryEntryByName = new Map<string, string>();
  options.componentSources.forEach((source, index) => {
    const componentReference = `typeof Component${index} === 'function' ? Component${index} : () => null`;

    toRuntimeRegistryNames(source.name).forEach((name) => {
      if (!registryEntryByName.has(name)) {
        registryEntryByName.set(name, componentReference);
      }
    });
  });
  const registryEntries = [...registryEntryByName.entries()]
    .map(([name, componentReference]) => {
      return `${JSON.stringify(name)}: ${componentReference}`;
    })
    .join(',\n');

  return [
    "import React from 'react';",
    "import { createRoot } from 'react-dom/client';",
    "import { createJsonApiClient } from 'drupal-canvas'; import { CanvasContextProvider, JsonApiClientProvider } from 'drupal-canvas/react';",
    "import { renderSpec } from 'drupal-canvas/json-render-utils';",
    "import canvasSiteData from 'virtual:drupal-canvas/site-data';",
    componentImports,
    cssImports,
    "if (typeof globalThis === 'object') {",
    '  globalThis.React = React;',
    '}',
    "if (typeof window === 'object') {",
    '  window.drupalSettings = window.drupalSettings ?? {};',
    '  window.drupalSettings.canvasData = window.drupalSettings.canvasData ?? {};',
    '  window.drupalSettings.canvasData.v0 = window.drupalSettings.canvasData.v0 ?? {};',
    '  if (typeof window.drupalSettings.canvasData.v0.baseUrl !== "string" || window.drupalSettings.canvasData.v0.baseUrl.length === 0) {',
    '    window.drupalSettings.canvasData.v0.baseUrl = window.location.origin;',
    '  }',
    '}',
    `const spec = ${JSON.stringify(options.spec)};`,
    `const pageTemplateSpec = ${JSON.stringify(options.pageTemplateSpec ?? null)};`,
    `const registry = {${registryEntries}};`,
    "const container = document.getElementById('root');",
    "if (!container) { throw new Error('Missing #root element in preview document.'); }",
    'const pageContent = renderSpec(spec, registry);',
    'if (pageTemplateSpec) { registry["marker.page_content"] = () => pageContent; }',
    'const renderedNode = pageTemplateSpec ? renderSpec(pageTemplateSpec, registry) : pageContent;',
    // The same providers the interactive preview mounts: Workbench page
    // defaults and the site data loaded once by the Vite integration,
    // resolved with the static settings and the preview origin exactly as
    // the bootstrap script resolves the legacy settings — from the same
    // inputs, not from the legacy settings themselves.
    // @see ../lib/workbench-context.ts
    // @see resolvePreviewSiteData()
    WORKBENCH_CONTEXT_RUNTIME_SOURCE,
    WORKBENCH_SITE_DATA_RUNTIME_SOURCE,
    `const canvasStaticSettings = ${JSON.stringify(runtimeSettings)};`,
    'const canvasResolvedSiteData = resolvePreviewSiteData(canvasStaticSettings, canvasSiteData);',
    'const canvasContext = createWorkbenchContext(canvasResolvedSiteData, canvasPreviewOrigin);',
    'const canvasJsonApiConfig = createWorkbenchJsonApiConfig(canvasResolvedSiteData, canvasPreviewOrigin);',
    'const withClient = canvasJsonApiConfig ? React.createElement(JsonApiClientProvider, { client: createJsonApiClient(canvasJsonApiConfig) }, renderedNode) : renderedNode;',
    'createRoot(container).render(React.createElement(CanvasContextProvider, { context: canvasContext }, withClient));',
  ].join('\n');
}

function normalizePath(value: string): string {
  return value.replaceAll('\\', '/');
}

function toRuntimeRegistryNames(componentName: string): string[] {
  const trimmedName = componentName.trim();
  if (trimmedName.length === 0) {
    return [];
  }

  const names = new Set<string>([trimmedName]);
  const canonicalName = trimmedName.startsWith('js.')
    ? trimmedName.slice(3)
    : trimmedName;

  if (canonicalName.length > 0) {
    names.add(canonicalName);
    names.add(canonicalName.toLowerCase());
    names.add(`js.${canonicalName}`);
    names.add(`js.${canonicalName.toLowerCase()}`);
  }

  return [...names];
}

function toProjectRelativePath(
  projectRoot: string,
  absolutePath: string,
): string {
  return normalizePath(path.relative(projectRoot, absolutePath));
}

function toIssue(
  code: string,
  message: string,
  issuePath?: string,
): PreviewIssue {
  return {
    code,
    message,
    ...(issuePath ? { path: issuePath } : {}),
  };
}

function toPayload(
  request: PreviewRequest,
  options: {
    ok: boolean;
    target?: PreviewTarget | null;
    spec?: Spec | null;
    css?: string;
    iframeHtml?: string | null;
    renderMode?: 'interactive';
    warnings?: PreviewIssue[];
    errors?: PreviewIssue[];
  },
): PreviewPayload {
  return {
    ok: options.ok,
    request,
    target: options.target ?? null,
    spec: options.spec ?? null,
    css: options.css ?? '',
    iframeHtml: options.iframeHtml ?? null,
    renderMode: options.renderMode ?? 'interactive',
    warnings: options.warnings ?? [],
    errors: options.errors ?? [],
  };
}

/**
 * The one site-data snapshot both preview consumers resolve from: the site
 * data the Vite integration discovered wins, the static `CANVAS_SITE_URL` /
 * `CANVAS_JSONAPI_PREFIX` settings fill what discovery did not provide, and
 * the preview origin is the last resort (applied in the browser). The legacy
 * `drupalSettings` bootstrap resolves it on the server and the generated
 * entry resolves it in the browser from the same inputs (the inlined static
 * settings and the site data module), so `getSiteData()` and
 * `useSiteContext()` never disagree about the backend without either reading
 * the other's output.
 *
 * Mirrored by `WORKBENCH_SITE_DATA_RUNTIME_SOURCE` below; keep them in sync.
 */
export function resolvePreviewSiteData(
  runtimeSettings: PreviewRuntimeSettings,
  siteData: CanvasSiteData | null = null,
): CanvasSiteData | null {
  const baseUrl = siteData?.baseUrl || runtimeSettings.baseUrl || undefined;
  const jsonapiSettings =
    siteData?.jsonapiSettings === null
      ? null
      : siteData?.jsonapiSettings?.apiPrefix || runtimeSettings.jsonapiPrefix
        ? {
            ...siteData?.jsonapiSettings,
            apiPrefix:
              siteData?.jsonapiSettings?.apiPrefix ||
              (runtimeSettings.jsonapiPrefix as string),
          }
        : undefined;
  if (!siteData && !baseUrl && jsonapiSettings === undefined) {
    return null;
  }
  return {
    ...siteData,
    ...(baseUrl && { baseUrl }),
    ...(jsonapiSettings !== undefined && { jsonapiSettings }),
  };
}

/**
 * The browser-side mirror of `resolvePreviewSiteData()` and of the bootstrap
 * script's origin fallback, inlined into the generated entry.
 */
const WORKBENCH_SITE_DATA_RUNTIME_SOURCE = [
  'function resolvePreviewSiteData(runtimeSettings, siteData) {',
  '  const baseUrl = (siteData && siteData.baseUrl) || runtimeSettings.baseUrl || undefined;',
  '  const discoveredPrefix = siteData && siteData.jsonapiSettings && siteData.jsonapiSettings.apiPrefix;',
  '  const jsonapiSettings = siteData && siteData.jsonapiSettings === null',
  '    ? null',
  '    : (discoveredPrefix || runtimeSettings.jsonapiPrefix)',
  '      ? { ...(siteData && siteData.jsonapiSettings), apiPrefix: discoveredPrefix || runtimeSettings.jsonapiPrefix }',
  '      : undefined;',
  '  if (!siteData && !baseUrl && jsonapiSettings === undefined) { return null; }',
  '  return { ...siteData, ...(baseUrl ? { baseUrl } : {}), ...(jsonapiSettings !== undefined ? { jsonapiSettings } : {}) };',
  '}',
  "const canvasPreviewOrigin = ['http:', 'https:'].includes(window.location.protocol) ? window.location.origin : 'http://localhost';",
].join('\n');

function buildPreviewBootstrapScript(
  runtimeSettings: PreviewRuntimeSettings,
  siteData: CanvasSiteData | null = null,
): string {
  const resolved = resolvePreviewSiteData(runtimeSettings, siteData);
  const bootstrapStatements = [
    // Generated previews are a Workbench environment for the legacy runtime
    // APIs; declare it before the runtime script evaluates component modules.
    // @see packages/drupal-canvas/src/runtime.ts
    'window.__drupalCanvasRuntime = { environment: "workbench" };',
    'window.drupalSettings = window.drupalSettings ?? {};',
    'window.drupalSettings.canvasData = window.drupalSettings.canvasData ?? {};',
    'window.drupalSettings.canvasData.v0 = window.drupalSettings.canvasData.v0 ?? {};',
    resolved?.baseUrl
      ? `const canvasPreviewBaseUrl = ${JSON.stringify(resolved.baseUrl)};`
      : "const canvasPreviewBaseUrl = ['http:', 'https:'].includes(window.location.protocol) ? window.location.origin : 'http://localhost';",
    'if (typeof window.drupalSettings.canvasData.v0.baseUrl !== "string" || window.drupalSettings.canvasData.v0.baseUrl.length === 0) {',
    '  window.drupalSettings.canvasData.v0.baseUrl = canvasPreviewBaseUrl;',
    '}',
  ];

  if (resolved?.jsonapiSettings === null) {
    // The site reports JSON:API as not installed; legacy clients must see
    // that too.
    bootstrapStatements.push(
      'if (window.drupalSettings.canvasData.v0.jsonapiSettings === undefined) { window.drupalSettings.canvasData.v0.jsonapiSettings = null; }',
    );
  } else if (resolved?.jsonapiSettings?.apiPrefix) {
    bootstrapStatements.push(
      'window.drupalSettings.canvasData.v0.jsonapiSettings = window.drupalSettings.canvasData.v0.jsonapiSettings ?? {};',
      'if (typeof window.drupalSettings.canvasData.v0.jsonapiSettings.apiPrefix !== "string" || window.drupalSettings.canvasData.v0.jsonapiSettings.apiPrefix.length === 0) {',
      `  window.drupalSettings.canvasData.v0.jsonapiSettings.apiPrefix = ${JSON.stringify(resolved.jsonapiSettings.apiPrefix)};`,
      '}',
    );
  }

  // The remaining site-level data for legacy consumers (`getSiteData()`),
  // filled in without overriding what is already set.
  if (resolved) {
    const siteLevelData = Object.fromEntries(
      Object.entries(resolved).filter(
        ([key]) => key !== 'baseUrl' && key !== 'jsonapiSettings',
      ),
    );
    if (Object.keys(siteLevelData).length > 0) {
      bootstrapStatements.push(
        `for (const [key, value] of Object.entries(${JSON.stringify(siteLevelData)})) {`,
        '  if (window.drupalSettings.canvasData.v0[key] === undefined) { window.drupalSettings.canvasData.v0[key] = value; }',
        '}',
      );
    }
  }

  return bootstrapStatements.join('').replaceAll('</script>', '<\\/script>');
}

/**
 * Prepends the brand kit color custom property block from the project's
 * canvas.brand-kit.json to bundled preview CSS, matching the dev server's
 * ordering (brand kit colors before the host global CSS).
 */
export function withBrandKitColorCss(projectRoot: string, css: string): string {
  const brandKitCss = buildBrandKitColorCss(readBrandKitColors(projectRoot));
  return brandKitCss ? `${brandKitCss}\n\n${css}` : css;
}

export function buildIframeHtml(
  js: string,
  css: string,
  runtimeSettings: PreviewRuntimeSettings = {
    baseUrl: null,
    jsonapiPrefix: null,
  },
  siteData: CanvasSiteData | null = null,
): string {
  const escapedScript = js.replaceAll('</script>', '<\\/script>');
  const escapedStyle = css.replaceAll('</style>', '<\\/style>');
  const bootstrapScript = buildPreviewBootstrapScript(
    runtimeSettings,
    siteData,
  );

  return [
    '<!doctype html>',
    '<html lang="en">',
    '<head>',
    '<meta charset="utf-8"/>',
    '<meta name="viewport" content="width=device-width, initial-scale=1"/>',
    '<style data-canvas-preview-css>',
    escapedStyle,
    '</style>',
    '</head>',
    '<body>',
    '<div id="root"></div>',
    '<script type="text/javascript" data-canvas-preview-bootstrap>',
    bootstrapScript,
    '</script>',
    '<script type="text/javascript" data-canvas-preview-runtime>',
    escapedScript,
    '</script>',
    '</body>',
    '</html>',
  ].join('');
}

interface CanvasSiteDataPlugin {
  name: string;
  api?: { getCanvasSiteData(): CanvasSiteData };
}

function collectOutputs(result: RollupOutput | RollupOutput[]): {
  js: string;
  css: string;
} {
  const outputs = Array.isArray(result) ? result : [result];
  const chunks: OutputChunk[] = [];
  const assets: OutputAsset[] = [];

  outputs.forEach((output) => {
    output.output.forEach((entry) => {
      if (entry.type === 'chunk') {
        chunks.push(entry);
        return;
      }

      assets.push(entry);
    });
  });

  const entryChunk =
    chunks.find((chunk) => chunk.isEntry) ??
    chunks.find((chunk) => chunk.fileName.endsWith('.js'));
  if (!entryChunk) {
    throw new Error('Interactive bundle did not produce a JavaScript entry.');
  }

  const cssAssets = assets.filter((asset) => asset.fileName.endsWith('.css'));
  const css = cssAssets
    .map((asset) => {
      if (typeof asset.source === 'string') {
        return asset.source;
      }

      return Buffer.from(asset.source).toString('utf-8');
    })
    .join('\n');

  return {
    js: entryChunk.code,
    css,
  };
}

function toImportPath(filePath: string): string {
  return normalizePath(filePath);
}

function resolveSpecifierFromCurrentModule(specifier: string): string {
  const require = createRequire(import.meta.url);

  try {
    return require.resolve(specifier);
  } catch {
    const resolver = (
      import.meta as ImportMeta & { resolve?: (specifier: string) => string }
    ).resolve;
    if (typeof resolver === 'function') {
      const resolved = resolver(specifier);
      if (resolved.startsWith('file://')) {
        return fileURLToPath(resolved);
      }

      return resolved;
    }
  }

  throw new Error(`Failed to resolve module specifier: ${specifier}`);
}

export async function bundleInteractivePreview(options: {
  projectRoot: string;
  aliasBaseDir: string;
  spec: Spec;
  pageTemplateSpec?: Spec | null;
  componentSources: BundleComponentSource[];
  cssEntryPaths: string[];
  runtimeSettings?: PreviewRuntimeSettings;
}): Promise<InteractiveBundleResult> {
  const require = createRequire(import.meta.url);
  const reactPackageRoot = path.dirname(require.resolve('react/package.json'));
  const reactDomPackageRoot = path.dirname(
    require.resolve('react-dom/package.json'),
  );
  const drupalCanvasEntryPath =
    resolveSpecifierFromCurrentModule('drupal-canvas');
  const drupalCanvasReactPath = resolveSpecifierFromCurrentModule(
    'drupal-canvas/react',
  );
  const drupalCanvasJsonRenderUtilsPath = resolveSpecifierFromCurrentModule(
    'drupal-canvas/json-render-utils',
  );

  const temporaryDirectory = await fs.mkdtemp(
    path.join(os.tmpdir(), 'canvas-workbench-preview-'),
  );
  const entryPath = path.join(temporaryDirectory, 'entry.tsx');
  const entrySource = buildPreviewRuntimeEntrySource({
    spec: options.spec,
    pageTemplateSpec: options.pageTemplateSpec,
    componentSources: options.componentSources,
    cssEntryPaths: options.cssEntryPaths,
    runtimeSettings: options.runtimeSettings,
  });
  const canvasViteConfig = createCanvasViteBuildConfig({
    hostRoot: options.projectRoot,
    hostAliasBaseDir: options.aliasBaseDir,
  });

  try {
    await fs.writeFile(entryPath, entrySource, 'utf-8');

    const buildResult = await viteBuild({
      configFile: false,
      root: options.projectRoot,
      esbuild: canvasViteConfig.esbuild,
      logLevel: 'silent',
      define: {
        'process.env.NODE_ENV': JSON.stringify('production'),
        'process.env': JSON.stringify({ NODE_ENV: 'production' }),
        process: JSON.stringify({ env: { NODE_ENV: 'production' } }),
      },
      plugins: [
        react(),
        tailwindcss(),
        ...(canvasViteConfig.plugins ?? []),
      ] as any,
      resolve: {
        dedupe: [
          'react',
          'react-dom',
          'react-dom/client',
          'react/jsx-runtime',
          'react/jsx-dev-runtime',
          'drupal-canvas',
        ],
        alias: [
          {
            find: 'react-dom/client',
            replacement: path.join(reactDomPackageRoot, 'client.js'),
          },
          {
            find: 'react/jsx-runtime',
            replacement: path.join(reactPackageRoot, 'jsx-runtime.js'),
          },
          {
            find: 'react/jsx-dev-runtime',
            replacement: path.join(reactPackageRoot, 'jsx-dev-runtime.js'),
          },
          {
            find: 'react',
            replacement: reactPackageRoot,
          },
          {
            find: 'react-dom',
            replacement: reactDomPackageRoot,
          },
          {
            find: /^drupal-canvas$/,
            replacement: drupalCanvasEntryPath,
          },
          {
            find: /^drupal-canvas\/react$/,
            replacement: drupalCanvasReactPath,
          },
          {
            find: 'drupal-canvas/json-render-utils',
            replacement: drupalCanvasJsonRenderUtilsPath,
          },
        ],
      },
      build: {
        write: false,
        emptyOutDir: false,
        cssCodeSplit: false,
        assetsInlineLimit: Number.MAX_SAFE_INTEGER,
        minify: true,
        lib: {
          entry: entryPath,
          formats: ['iife'],
          name: 'CanvasWorkbenchPreviewRuntime',
          fileName: () => 'preview-runtime.js',
          cssFileName: 'preview-runtime.css',
        },
        rollupOptions: {
          output: {
            inlineDynamicImports: true,
            entryFileNames: 'preview-runtime.js',
            chunkFileNames: 'preview-runtime.js',
            assetFileNames: 'preview-[name][extname]',
          },
        },
      },
    });

    const outputs = collectOutputs(
      buildResult as RollupOutput | RollupOutput[],
    );
    // The Vite integration loaded the site data once during the build.
    const canvasPlugin = (canvasViteConfig.plugins ?? [])
      .flat()
      .find(
        (plugin): plugin is CanvasSiteDataPlugin =>
          typeof plugin === 'object' &&
          plugin !== null &&
          'name' in plugin &&
          plugin.name === 'drupal-canvas',
      );
    return {
      ...outputs,
      siteData: canvasPlugin?.api?.getCanvasSiteData() ?? null,
    };
  } finally {
    await fs.rm(temporaryDirectory, { recursive: true, force: true });
  }
}

function parseJsonFile(filePath: string): Promise<unknown> {
  return fs.readFile(filePath, 'utf-8').then((content) => JSON.parse(content));
}

function isSamePath(left: string, right: string): boolean {
  return path.resolve(left) === path.resolve(right);
}

async function maybeResolveGlobalCssPath(
  projectRoot: string,
  globalCssPath: string,
): Promise<{ cssPath: string | null; warning: PreviewIssue | null }> {
  const absoluteGlobalCssPath = path.resolve(projectRoot, globalCssPath);

  try {
    await fs.access(absoluteGlobalCssPath);
    return {
      cssPath: absoluteGlobalCssPath,
      warning: null,
    };
  } catch {
    return {
      cssPath: null,
      warning: toIssue(
        'missing_global_css',
        `Configured global CSS file was not found: ${globalCssPath}`,
        absoluteGlobalCssPath,
      ),
    };
  }
}

async function prepareComponentPreview(options: {
  projectRoot: string;
  inputPath: string;
  componentDir: string;
  discoveryResult: DiscoveryResult;
  extractMetadata: typeof extractComponentPreviewMetadataFromComponentYaml;
}): Promise<
  | { prepared: PreparedComponentPreview; warnings: PreviewIssue[] }
  | { errors: PreviewIssue[]; warnings: PreviewIssue[] }
> {
  const requestedPath = path.resolve(options.projectRoot, options.inputPath);
  const component = options.discoveryResult.components.find((candidate) =>
    isSamePath(candidate.metadataPath, requestedPath),
  );

  if (!component) {
    return {
      warnings: [],
      errors: [
        toIssue(
          'component_not_found',
          formatComponentPathConstraintMessage({
            inputPath: options.inputPath,
            componentDir: options.componentDir,
          }),
          requestedPath,
        ),
      ],
    };
  }

  if (!component.jsEntryPath) {
    return {
      warnings: [],
      errors: [
        toIssue(
          'missing_js_entry',
          `Component at "${options.inputPath}" is missing a JavaScript entry file.`,
          component.metadataPath,
        ),
      ],
    };
  }

  if (!isSupportedPreviewModulePath(component.jsEntryPath)) {
    return {
      warnings: [],
      errors: [
        toIssue(
          'unsupported_js_extension',
          `Component JavaScript entry is not previewable: ${component.jsEntryPath}`,
          component.jsEntryPath,
        ),
      ],
    };
  }

  const brandKitColors = readBrandKitColors(options.projectRoot);
  const metadata = await options.extractMetadata(
    component.metadataPath,
    brandKitColors,
  );
  const spec = canvasTreeToSpec([
    {
      uuid: 'canvas-workbench-preview-root',
      parent_uuid: null,
      slot: null,
      component_id: component.name,
      component_version: null,
      inputs: metadata.exampleProps,
      label: null,
    },
  ]);

  return {
    warnings: [],
    prepared: {
      target: {
        id: component.id,
        name: metadata.label ?? component.name,
        projectRelativePath: toProjectRelativePath(
          options.projectRoot,
          component.metadataPath,
        ),
      },
      spec,
      bundleSources: [
        {
          name: component.name,
          jsEntryPath: component.jsEntryPath,
        },
      ],
      cssEntryPaths: component.cssEntryPath ? [component.cssEntryPath] : [],
    },
  };
}

function toPreviewablePageRegistrySources(
  components: DiscoveredComponent[],
): BundleComponentSource[] {
  return components
    .filter(
      (component): component is DiscoveredComponent & { jsEntryPath: string } =>
        component.jsEntryPath !== null,
    )
    .filter((component) => isSupportedPreviewModulePath(component.jsEntryPath))
    .map((component) => ({
      name: component.name,
      jsEntryPath: component.jsEntryPath,
    }));
}

async function preparePagePreview(options: {
  projectRoot: string;
  inputPath: string;
  componentDir: string;
  pagesDir: string;
  discoveryResult: DiscoveryResult;
}): Promise<
  | { prepared: PreparedPagePreview; warnings: PreviewIssue[] }
  | { errors: PreviewIssue[]; warnings: PreviewIssue[] }
> {
  const requestedPath = path.resolve(options.projectRoot, options.inputPath);
  const page = options.discoveryResult.pages.find((candidate) =>
    isSamePath(candidate.path, requestedPath),
  );

  if (!page) {
    return {
      warnings: [],
      errors: [
        toIssue(
          'page_not_found',
          formatPagePathConstraintMessage({
            inputPath: options.inputPath,
            pagesDir: options.pagesDir,
            componentDir: options.componentDir,
          }),
          requestedPath,
        ),
      ],
    };
  }

  let parsedJson: unknown;
  try {
    parsedJson = await parseJsonFile(page.path);
  } catch {
    return {
      warnings: [],
      errors: [
        toIssue(
          'invalid_page_json',
          `Failed to parse page JSON file: ${page.path}`,
          page.path,
        ),
      ],
    };
  }

  const parsedPage = toPreviewPageSpec(parsedJson, {
    sourcePath: page.path,
    componentNames: options.discoveryResult.components.map(
      (component) => component.name,
    ),
  });

  if (!parsedPage.spec) {
    return {
      warnings: [],
      errors: parsedPage.issues.map((issue) =>
        toIssue(issue.code, issue.message, issue.path),
      ),
    };
  }

  const cssEntryPaths = options.discoveryResult.components
    .filter(
      (
        component,
      ): component is DiscoveredComponent & { cssEntryPath: string } =>
        component.cssEntryPath !== null,
    )
    .map((component) => component.cssEntryPath);

  const brandKitColors = readBrandKitColors(options.projectRoot);

  const pageTemplate = selectPageTemplate(
    options.discoveryResult.pageTemplates,
    parsedPage.pageVariant,
  );
  let pageTemplateSpec: Spec | null = null;
  if (pageTemplate) {
    let authoredPageTemplate: unknown;
    try {
      authoredPageTemplate = await parseJsonFile(pageTemplate.path);
    } catch {
      return {
        warnings: [],
        errors: [
          toIssue(
            'invalid_page_template_json',
            `Failed to parse page template JSON file: ${pageTemplate.path}`,
            pageTemplate.path,
          ),
        ],
      };
    }
    const parsedPageTemplate = parsePageTemplateSpec(
      authoredPageTemplate,
      pageTemplate.path,
      {
        componentNames: options.discoveryResult.components.map(
          (component) => component.name,
        ),
      },
    );
    if (!parsedPageTemplate.pageTemplate) {
      return {
        warnings: [],
        errors: parsedPageTemplate.issues.map((issue) =>
          toIssue(issue.code, issue.message, issue.path),
        ),
      };
    }
    pageTemplateSpec = resolvePageColorPropsForPreview(
      parsedPageTemplate.pageTemplate.spec,
      brandKitColors,
      options.discoveryResult.componentSchemas,
    );
  }

  return {
    warnings: [],
    prepared: {
      target: {
        id: page.slug,
        name: page.name,
        projectRelativePath: page.relativePath,
      },
      spec: resolvePageColorPropsForPreview(
        parsedPage.spec,
        brandKitColors,
        options.discoveryResult.componentSchemas,
      ),
      pageTemplateSpec,
      bundleSources: toPreviewablePageRegistrySources(
        options.discoveryResult.components,
      ),
      cssEntryPaths,
    },
  };
}

export async function buildPreviewPayload(
  options: BuildPreviewPayloadOptions,
  dependencies: BuildPreviewPayloadDependencies = {},
): Promise<PreviewPayload> {
  const request: PreviewRequest = {
    mode: options.mode,
    inputPath: options.inputPath,
    projectRoot: options.projectRoot,
  };

  const discover = dependencies.discover ?? discoverCanvasProject;
  const resolveConfig = dependencies.resolveConfig ?? resolveCanvasConfig;
  const extractMetadata =
    dependencies.extractMetadata ??
    extractComponentPreviewMetadataFromComponentYaml;
  const bundle =
    dependencies.bundleInteractivePreview ?? bundleInteractivePreview;

  const configWarnings: PreviewIssue[] = [];
  const config = resolveConfig({
    hostRoot: options.projectRoot,
    onWarning: (warning) =>
      configWarnings.push(toIssue(warning.code, warning.message, warning.path)),
  });
  try {
    validateCanvasImportRoots({
      hostRoot: options.projectRoot,
      aliasBaseDir: config.aliasBaseDir,
      componentDir: config.componentDir,
    });
  } catch (error) {
    return toPayload(request, {
      ok: false,
      errors: [
        toIssue(
          'invalid_canvas_config',
          error instanceof Error ? error.message : String(error),
        ),
      ],
    });
  }

  const componentRoot = path.resolve(options.projectRoot, config.componentDir);
  const pagesRoot = path.resolve(options.projectRoot, config.pagesDir);
  const pageTemplatesRoot = path.resolve(
    options.projectRoot,
    config.pageTemplatesDir,
  );

  let discoveryResult: DiscoveryResult;
  try {
    discoveryResult = await discover({
      componentRoot,
      pagesRoot,
      pageTemplatesRoot,
      projectRoot: options.projectRoot,
    });
  } catch (error) {
    return toPayload(request, {
      ok: false,
      errors: [
        toIssue(
          'discovery_failed',
          error instanceof Error ? error.message : String(error),
        ),
      ],
    });
  }

  // Preview build returns config warnings in the JSON payload.
  // This gives command output the same deprecation notices that live Workbench logs during startup.
  const warnings: PreviewIssue[] = [
    ...configWarnings,
    ...discoveryResult.warnings.map((warning) =>
      toIssue(warning.code, warning.message, warning.path),
    ),
  ];

  const globalCssResult = await maybeResolveGlobalCssPath(
    options.projectRoot,
    config.globalCssPath,
  );
  if (globalCssResult.warning) {
    warnings.push(globalCssResult.warning);
  }

  const preparedResult =
    options.mode === 'component'
      ? await prepareComponentPreview({
          projectRoot: options.projectRoot,
          inputPath: options.inputPath,
          componentDir: config.componentDir,
          discoveryResult,
          extractMetadata,
        })
      : await preparePagePreview({
          projectRoot: options.projectRoot,
          inputPath: options.inputPath,
          componentDir: config.componentDir,
          pagesDir: config.pagesDir,
          discoveryResult,
        });

  if ('errors' in preparedResult) {
    return toPayload(request, {
      ok: false,
      warnings: [...warnings, ...preparedResult.warnings],
      errors: preparedResult.errors,
    });
  }

  const allCssEntryPaths = [
    ...(globalCssResult.cssPath ? [globalCssResult.cssPath] : []),
    ...preparedResult.prepared.cssEntryPaths,
  ];
  const uniqueCssPaths = [
    ...new Set(allCssEntryPaths.map((entry) => path.resolve(entry))),
  ];
  const runtimeSettings = resolvePreviewRuntimeSettings(options.projectRoot);

  try {
    const bundleResult = await bundle({
      projectRoot: options.projectRoot,
      aliasBaseDir: config.aliasBaseDir,
      spec: preparedResult.prepared.spec,
      pageTemplateSpec:
        options.mode === 'page'
          ? (preparedResult.prepared as PreparedPagePreview).pageTemplateSpec
          : null,
      componentSources: preparedResult.prepared.bundleSources,
      cssEntryPaths: uniqueCssPaths,
      runtimeSettings,
    });

    const iframeHtml = buildIframeHtml(
      bundleResult.js,
      withBrandKitColorCss(options.projectRoot, bundleResult.css),
      runtimeSettings,
      bundleResult.siteData,
    );

    return toPayload(request, {
      ok: true,
      target: preparedResult.prepared.target,
      spec: preparedResult.prepared.spec,
      css: bundleResult.css,
      iframeHtml,
      warnings: [...warnings, ...preparedResult.warnings],
      errors: [],
    });
  } catch (error) {
    return toPayload(request, {
      ok: false,
      target: preparedResult.prepared.target,
      spec: preparedResult.prepared.spec,
      css: '',
      iframeHtml: null,
      warnings: [...warnings, ...preparedResult.warnings],
      errors: [
        toIssue(
          'interactive_bundle_failed',
          error instanceof Error ? error.message : String(error),
        ),
      ],
    });
  }
}
