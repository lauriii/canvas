import { existsSync, promises as fs, realpathSync, statSync } from 'node:fs';
import path from 'node:path';
import * as yaml from 'js-yaml';
import { build as viteBuild } from 'vite';
import svgr from 'vite-plugin-svgr';
import {
  ASSET_EXTENSIONS,
  FONT_EXTENSIONS,
  resolveCanvasConfig,
} from '@drupal-canvas/discovery';
import drupalCanvas from '@drupal-canvas/vite-plugin';

import type { Manifest, Plugin, UserConfig } from 'vite';

// The following packages are bundled by Drupal Canvas and are provided by
// default in its import map. Build targets should not bundle them.
export const DRUPAL_CANVAS_EXTERNALS: string[] = [
  'preact',
  'preact/hooks',
  'react/jsx-runtime',
  'react',
  'react-dom',
  'react-dom/client',
  'clsx',
  'class-variance-authority',
  'tailwind-merge',
  'drupal-jsonapi-params',
  'swr',
  '@tailwindcss/typography',
  'drupal-canvas',
  'next-image-standalone',
  '@drupal-api-client/json-api-client',
  '@/lib/FormattedText',
  '@/lib/utils',
  '@/lib/jsonapi-utils',
  '@/lib/drupal-utils',
];

export interface CanvasViteCompatOptions {
  hostRoot: string;
  hostAliasBaseDir?: string;
}

export interface CanvasImportRootValidationOptions {
  hostRoot: string;
  aliasBaseDir: string;
  componentDir: string;
}

const WORKBENCH_HOST_GLOBAL_CSS_VIRTUAL_URL =
  '/@id/virtual:canvas-host-global.css';
const hostAliasPrefix = '@/';
const componentAliasPrefix = 'components/';

function asRecord(value: unknown): Record<string, unknown> | null {
  return typeof value === 'object' && value !== null
    ? (value as Record<string, unknown>)
    : null;
}

function normalizePath(value: string): string {
  return value.replaceAll('\\', '/');
}

function stripQueryAndHash(value: string): string {
  return value.split('?')[0].split('#')[0];
}

function getQueryAndHash(value: string): string {
  const queryIndex = value.search(/[?#]/);
  return queryIndex === -1 ? '' : value.slice(queryIndex);
}

function normalizeViteImporterPath(importerId: string): string {
  const withoutQuery = importerId.split('?')[0].split('#')[0];
  const normalized = normalizePath(withoutQuery);
  if (normalized.startsWith('/@fs/')) {
    return normalized.slice('/@fs'.length);
  }
  return normalized;
}

function resolveHostAliasPath(
  hostRoot: string,
  hostAliasBaseDir: string,
  sourceSuffix: string,
): string {
  const cleanSourceSuffix = stripQueryAndHash(sourceSuffix);
  const suffixQuery = getQueryAndHash(sourceSuffix);
  const unresolvedTarget = path.resolve(
    hostRoot,
    hostAliasBaseDir,
    cleanSourceSuffix,
  );

  if (path.extname(unresolvedTarget)) {
    return `${unresolvedTarget}${suffixQuery}`;
  }

  const isDirectory = (() => {
    try {
      return (
        existsSync(unresolvedTarget) && statSync(unresolvedTarget).isDirectory()
      );
    } catch {
      return false;
    }
  })();

  const extensionCandidates = [
    '.ts',
    '.tsx',
    '.js',
    '.jsx',
    '.mjs',
    '.cjs',
  ] as const;
  const candidates = isDirectory
    ? extensionCandidates.map((extension) =>
        path.join(unresolvedTarget, `index${extension}`),
      )
    : extensionCandidates.map((extension) => `${unresolvedTarget}${extension}`);

  for (const candidate of candidates) {
    if (existsSync(candidate)) {
      return `${candidate}${suffixQuery}`;
    }
  }

  return `${unresolvedTarget}${suffixQuery}`;
}

function hasHostCanvasConfig(hostRoot: string): boolean {
  return existsSync(path.resolve(hostRoot, 'canvas.config.json'));
}

export function validateCanvasImportRoots(
  options: CanvasImportRootValidationOptions,
): void {
  const aliasRoot = path.resolve(options.hostRoot, options.aliasBaseDir);
  const componentRoot = path.resolve(options.hostRoot, options.componentDir);
  const relativeComponentRoot = path.relative(aliasRoot, componentRoot);

  if (
    relativeComponentRoot === '' ||
    relativeComponentRoot.startsWith('..') ||
    path.isAbsolute(relativeComponentRoot)
  ) {
    throw new Error(
      `Invalid Canvas config: componentDir "${options.componentDir}" must be inside aliasBaseDir "${options.aliasBaseDir}".`,
    );
  }
}

function resolveHostImportPath(
  hostRoot: string,
  hostAliasBaseDir: string,
  sourceSuffix: string,
): string {
  const cleanSourceSuffix = stripQueryAndHash(sourceSuffix);
  const isComponentImport =
    cleanSourceSuffix === 'components' ||
    cleanSourceSuffix.startsWith(componentAliasPrefix);

  if (!hasHostCanvasConfig(hostRoot) || !isComponentImport) {
    return resolveHostAliasPath(hostRoot, hostAliasBaseDir, sourceSuffix);
  }

  const canvasConfig = resolveCanvasConfig({ hostRoot });
  validateCanvasImportRoots({
    hostRoot,
    aliasBaseDir: hostAliasBaseDir,
    componentDir: canvasConfig.componentDir,
  });
  const componentSuffix =
    cleanSourceSuffix === 'components'
      ? ''
      : sourceSuffix.slice(componentAliasPrefix.length);

  return resolveHostAliasPath(
    hostRoot,
    canvasConfig.componentDir,
    componentSuffix,
  );
}

function isPathWithinRoot(filePath: string, rootPath: string): boolean {
  const relative = path.relative(rootPath, filePath);
  if (
    relative !== '' &&
    !relative.startsWith('..') &&
    !path.isAbsolute(relative)
  ) {
    return true;
  }

  let realFilePath: string;
  let realRootPath: string;
  try {
    realFilePath = realpathSync(filePath);
    realRootPath = realpathSync(rootPath);
  } catch {
    return false;
  }

  const realRelative = path.relative(realRootPath, realFilePath);
  return (
    realRelative !== '' &&
    !realRelative.startsWith('..') &&
    !path.isAbsolute(realRelative)
  );
}

// @todo Implement automatic discovery of the Tailwind CSS entrypoint in @drupal-canvas/discovery.
// Idea: Search for the following strings in files:
// - '@import "tailwindcss"' — note that this is optional in the in-browser code editor
// - '@theme
// - Identify more patterns that indicate a Tailwind CSS entrypoint.
// - Also a file named `global.css` is a good indicator.
export function resolveHostGlobalCssPath(hostRoot: string): string {
  const canvasConfig = resolveCanvasConfig({ hostRoot });
  return path.resolve(hostRoot, canvasConfig.globalCssPath);
}

export async function ensureHostGlobalCssExists(
  hostRoot: string,
): Promise<string> {
  const resolvedPath = resolveHostGlobalCssPath(hostRoot);
  const canvasConfig = resolveCanvasConfig({ hostRoot });
  const relativePath = canvasConfig.globalCssPath;
  try {
    await fs.access(resolvedPath);
  } catch {
    throw new Error(
      `Missing required host Tailwind entrypoint at ${relativePath}. Expected file: ${resolvedPath}`,
    );
  }

  return resolvedPath;
}

export function getWorkbenchHostGlobalCssVirtualUrl(): string {
  return WORKBENCH_HOST_GLOBAL_CSS_VIRTUAL_URL;
}

export function drupalCanvasCompatServer(
  options: Pick<CanvasViteCompatOptions, 'hostRoot'>,
): { fs: { allow: string[] } } {
  return {
    fs: {
      allow: [options.hostRoot],
    },
  };
}

export interface ComponentPreviewMetadata {
  label: string | null;
  exampleProps: Record<string, unknown>;
  requiredPropNames: string[];
}

export async function extractComponentPreviewMetadataFromComponentYaml(
  metadataPath: string,
): Promise<ComponentPreviewMetadata> {
  try {
    const content = await fs.readFile(metadataPath, 'utf-8');
    const parsed = yaml.load(content);
    const root = asRecord(parsed);
    const props = asRecord(root?.props);
    const properties = asRecord(props?.properties);
    const requiredPropNames = Array.isArray(root?.required)
      ? root.required.filter(
          (value): value is string => typeof value === 'string',
        )
      : [];

    const exampleProps: Record<string, unknown> = {};
    if (properties) {
      for (const [propName, rawPropDefinition] of Object.entries(properties)) {
        const propDefinition = asRecord(rawPropDefinition);
        if (!propDefinition) {
          continue;
        }

        const examples = propDefinition.examples;
        if (Array.isArray(examples) && examples.length > 0) {
          exampleProps[propName] = examples[0];
        }
      }
    }

    return {
      label: typeof root?.name === 'string' ? root.name : null,
      exampleProps,
      requiredPropNames,
    };
  } catch {
    return {
      label: null,
      exampleProps: {},
      requiredPropNames: [],
    };
  }
}

export async function extractFirstExamplePropsFromComponentYaml(
  metadataPath: string,
): Promise<Record<string, unknown>> {
  const previewMetadata =
    await extractComponentPreviewMetadataFromComponentYaml(metadataPath);
  return previewMetadata.exampleProps;
}

export interface CanvasViteBuildConfigOptions {
  hostRoot: string;
  hostAliasBaseDir?: string;
}

export function createCanvasViteBuildConfig(
  options: CanvasViteBuildConfigOptions,
): UserConfig {
  return {
    root: options.hostRoot,
    esbuild: { jsx: 'automatic' },
    plugins: [
      ...drupalCanvasCompat({
        hostRoot: options.hostRoot,
        hostAliasBaseDir: options.hostAliasBaseDir,
      }),
    ],
  };
}

export interface CanvasDependencyMetadata {
  thirdPartyPackages: Set<string>;
  localAliasImports: Map<string, string>;
  assetImports: Map<string, string>;
  unresolvedAliasImports: Set<string>;
}

export interface CanvasAssetImport {
  specifier: string;
  filePath: string;
}

export function createCanvasDependencyMetadata(): CanvasDependencyMetadata {
  return {
    thirdPartyPackages: new Set(),
    localAliasImports: new Map(),
    assetImports: new Map(),
    unresolvedAliasImports: new Set(),
  };
}

function isCanvasAssetSpecifier(value: string): boolean {
  if (value.includes('?react')) {
    return false;
  }
  const ext = path.extname(stripQueryAndHash(value)).toLowerCase();
  return (ASSET_EXTENSIONS as readonly string[]).includes(ext);
}

function isBareImport(source: string): boolean {
  return (
    !source.startsWith('.') &&
    !source.startsWith('/') &&
    !source.startsWith('\0') &&
    !source.includes('\0')
  );
}

function isCanvasProvidedExternal(source: string): boolean {
  return DRUPAL_CANVAS_EXTERNALS.some(
    (external) => source === external || source.startsWith(`${external}/`),
  );
}

function toCanvasAssetSpecifier(options: {
  source: string;
  importerPath: string;
  hostRoot: string;
  hostAliasBaseDir: string;
}): CanvasAssetImport | null {
  if (!isCanvasAssetSpecifier(options.source)) {
    return null;
  }

  const aliasRoot = path.resolve(options.hostRoot, options.hostAliasBaseDir);
  const resolvedPath = options.source.startsWith(hostAliasPrefix)
    ? resolveHostImportPath(
        options.hostRoot,
        options.hostAliasBaseDir,
        options.source.slice(hostAliasPrefix.length),
      )
    : options.source.startsWith('.')
      ? path.resolve(path.dirname(options.importerPath), options.source)
      : null;

  if (!resolvedPath) {
    return null;
  }

  const cleanResolvedPath = stripQueryAndHash(resolvedPath);
  if (hasHostCanvasConfig(options.hostRoot)) {
    const canvasConfig = resolveCanvasConfig({ hostRoot: options.hostRoot });
    validateCanvasImportRoots({
      hostRoot: options.hostRoot,
      aliasBaseDir: options.hostAliasBaseDir,
      componentDir: canvasConfig.componentDir,
    });
    const componentRoot = path.resolve(
      options.hostRoot,
      canvasConfig.componentDir,
    );
    const componentRelativePath = path.relative(
      componentRoot,
      cleanResolvedPath,
    );
    if (
      !componentRelativePath.startsWith('..') &&
      !path.isAbsolute(componentRelativePath)
    ) {
      return {
        specifier: `${hostAliasPrefix}components/${normalizePath(componentRelativePath)}`,
        filePath: cleanResolvedPath,
      };
    }
  }

  const relativePath = path.relative(aliasRoot, cleanResolvedPath);
  if (!relativePath.startsWith('..') && !path.isAbsolute(relativePath)) {
    return {
      specifier: `${hostAliasPrefix}${normalizePath(relativePath)}`,
      filePath: cleanResolvedPath,
    };
  }

  return null;
}

export function rewriteCanvasAssetImports(
  sourceCode: string,
  options: {
    filePath: string;
    hostRoot: string;
    hostAliasBaseDir: string;
  },
): { code: string; assets: CanvasAssetImport[] } {
  const assets: CanvasAssetImport[] = [];
  const importPattern =
    /^(\s*)import\s+([A-Za-z_$][\w$]*)\s+from\s+(['"])([^'"]+)\3\s*;?\s*$/gm;

  const code = sourceCode.replace(
    importPattern,
    (statement, indentation, identifier, _quote, specifier) => {
      const asset = toCanvasAssetSpecifier({
        source: specifier,
        importerPath: options.filePath,
        hostRoot: options.hostRoot,
        hostAliasBaseDir: options.hostAliasBaseDir,
      });
      if (!asset) {
        return statement;
      }

      assets.push(asset);
      return `${indentation}const ${identifier} = import.meta.resolve(${JSON.stringify(asset.specifier)});`;
    },
  );

  return { code, assets };
}

export function createCanvasAssetImportTransformPlugin(options: {
  hostRoot: string;
  hostAliasBaseDir: string;
  metadata?: CanvasDependencyMetadata;
}): Plugin {
  return {
    name: 'canvas-asset-import-transform',
    enforce: 'pre',
    transform(code, id) {
      const filePath = stripQueryAndHash(normalizeViteImporterPath(id));
      if (!isPathWithinRoot(filePath, options.hostRoot)) {
        return null;
      }

      const result = rewriteCanvasAssetImports(code, {
        filePath,
        hostRoot: options.hostRoot,
        hostAliasBaseDir: options.hostAliasBaseDir,
      });
      for (const asset of result.assets) {
        if (existsSync(asset.filePath)) {
          options.metadata?.assetImports.set(asset.specifier, asset.filePath);
        }
      }

      return result.assets.length > 0 ? { code: result.code, map: null } : null;
    },
  };
}

export function createCanvasDependencyMetadataPlugin(options: {
  hostRoot: string;
  hostAliasBaseDir: string;
  metadata: CanvasDependencyMetadata;
  externalizeBareImports?: boolean;
  externalizeAliasImports?: boolean;
}): Plugin {
  return {
    name: 'canvas-dependency-metadata',
    enforce: 'pre',
    resolveId(source, importer) {
      if (!importer || source.startsWith('\0')) {
        return null;
      }

      if (source.startsWith(hostAliasPrefix)) {
        if (isCanvasProvidedExternal(source)) {
          return options.externalizeAliasImports
            ? { id: source, external: true }
            : null;
        }

        const cleanSource = stripQueryAndHash(source);
        const resolvedPath = resolveHostImportPath(
          options.hostRoot,
          options.hostAliasBaseDir,
          source.slice(hostAliasPrefix.length),
        );
        const cleanResolvedPath = stripQueryAndHash(resolvedPath);
        const exists = existsSync(cleanResolvedPath);

        if (!exists) {
          options.metadata.unresolvedAliasImports.add(cleanSource);
          return options.externalizeAliasImports
            ? { id: cleanSource, external: true }
            : null;
        }

        if (isCanvasAssetSpecifier(source)) {
          options.metadata.assetImports.set(cleanSource, cleanResolvedPath);
        } else if (!source.startsWith(`${hostAliasPrefix}components/`)) {
          options.metadata.localAliasImports.set(source, cleanResolvedPath);
        }

        return options.externalizeAliasImports
          ? { id: source, external: true }
          : null;
      }

      if (isBareImport(source)) {
        if (!isCanvasProvidedExternal(source)) {
          options.metadata.thirdPartyPackages.add(source);
        }

        return options.externalizeBareImports
          ? { id: source, external: true }
          : null;
      }

      return null;
    },
  };
}

export interface CanvasComponentBuildEntry {
  entryPath: string;
  outputDir: string;
  outputBaseName: string;
}

export async function buildCanvasComponentEntry(options: {
  projectRoot: string;
  aliasBaseDir: string;
  entry: CanvasComponentBuildEntry;
}): Promise<CanvasDependencyMetadata> {
  const metadata = createCanvasDependencyMetadata();
  const baseConfig = createCanvasViteBuildConfig({
    hostRoot: options.projectRoot,
    hostAliasBaseDir: options.aliasBaseDir,
  });
  const externalizeComponentImport = (source: string): boolean => {
    if (isCanvasProvidedExternal(source)) {
      return true;
    }

    if (source.startsWith(hostAliasPrefix)) {
      const cleanSource = stripQueryAndHash(source);
      const resolvedPath = resolveHostImportPath(
        options.projectRoot,
        options.aliasBaseDir,
        source.slice(hostAliasPrefix.length),
      );
      const cleanResolvedPath = stripQueryAndHash(resolvedPath);

      if (!existsSync(cleanResolvedPath)) {
        metadata.unresolvedAliasImports.add(cleanSource);
        return true;
      }

      if (isCanvasAssetSpecifier(source)) {
        metadata.assetImports.set(cleanSource, cleanResolvedPath);
      } else if (!source.startsWith(`${hostAliasPrefix}components/`)) {
        metadata.localAliasImports.set(source, cleanResolvedPath);
      }
      return true;
    }

    if (isBareImport(source)) {
      metadata.thirdPartyPackages.add(source);
      return true;
    }

    return false;
  };

  await viteBuild({
    ...baseConfig,
    configFile: false,
    logLevel: 'silent',
    plugins: [
      createCanvasAssetImportTransformPlugin({
        hostRoot: options.projectRoot,
        hostAliasBaseDir: options.aliasBaseDir,
        metadata,
      }),
      createCanvasDependencyMetadataPlugin({
        hostRoot: options.projectRoot,
        hostAliasBaseDir: options.aliasBaseDir,
        metadata,
        externalizeAliasImports: true,
        externalizeBareImports: true,
      }),
      ...(baseConfig.plugins ?? []),
    ],
    build: {
      outDir: options.entry.outputDir,
      emptyOutDir: false,
      target: 'es2015',
      minify: false,
      sourcemap: false,
      copyPublicDir: false,
      lib: {
        entry: options.entry.entryPath,
        formats: ['es'],
        fileName: () => `${options.entry.outputBaseName}.js`,
      },
      rollupOptions: {
        external: externalizeComponentImport,
        output: {
          inlineDynamicImports: true,
        },
      },
    },
  });

  return metadata;
}

export interface CanvasLocalArtifactBuildResult {
  localImportMap: Record<string, string>;
  sharedChunks: string[];
  thirdPartyPackages: Set<string>;
}

function createLocalAssetEmitterPlugin(options: {
  assetImports: Map<string, string>;
  localImportMap: Record<string, string>;
}): Plugin {
  const referenceIds = new Map<string, string>();

  return {
    name: 'canvas-local-asset-emitter',
    async buildStart() {
      for (const [specifier, filePath] of options.assetImports) {
        const source = await fs.readFile(filePath);
        const referenceId = this.emitFile({
          type: 'asset',
          name: path.basename(filePath),
          source,
        });
        referenceIds.set(specifier, referenceId);
      }
    },
    generateBundle() {
      for (const [specifier, referenceId] of referenceIds) {
        options.localImportMap[specifier] =
          `./local/${this.getFileName(referenceId)}`.replace(/\\/g, '/');
      }
    },
  };
}

function createVirtualAssetEntryPlugin(): Plugin {
  const virtualId = '\0canvas-asset-entry';
  return {
    name: 'canvas-virtual-asset-entry',
    resolveId(source) {
      return source === virtualId ? virtualId : null;
    },
    load(id) {
      return id === virtualId ? 'export default {};' : null;
    },
  };
}

export async function buildCanvasLocalArtifacts(options: {
  projectRoot: string;
  aliasBaseDir: string;
  outputDir: string;
  localImports: Map<string, string>;
  assetImports: Map<string, string>;
}): Promise<CanvasLocalArtifactBuildResult> {
  const localImportMap: Record<string, string> = {};
  const sharedChunks: string[] = [];
  const metadata = createCanvasDependencyMetadata();
  const outputDirForLocalImports = path.join(
    path.resolve(options.outputDir),
    'local',
  );
  const codeEntries: Record<string, string> = {};
  const sourceByResolvedPath = new Map<string, string>();
  const entryNameCounts = new Map<string, number>();

  for (const [source, resolvedPath] of options.localImports) {
    if (isCanvasAssetSpecifier(resolvedPath)) {
      continue;
    }

    const entryBaseName = path.parse(resolvedPath).name;
    const previousCount = entryNameCounts.get(entryBaseName) ?? 0;
    const entryName =
      previousCount === 0
        ? entryBaseName
        : `${entryBaseName}--${previousCount}`;
    entryNameCounts.set(entryBaseName, previousCount + 1);
    codeEntries[entryName] = resolvedPath;
    sourceByResolvedPath.set(path.resolve(resolvedPath), source);
  }

  if (
    Object.keys(codeEntries).length === 0 &&
    options.assetImports.size === 0
  ) {
    return { localImportMap, sharedChunks, thirdPartyPackages: new Set() };
  }

  const baseConfig = createCanvasViteBuildConfig({
    hostRoot: options.projectRoot,
    hostAliasBaseDir: options.aliasBaseDir,
  });
  const hasCodeEntries = Object.keys(codeEntries).length > 0;
  const virtualAssetEntry = '\0canvas-asset-entry';

  await viteBuild({
    ...baseConfig,
    configFile: false,
    logLevel: 'silent',
    plugins: [
      createLocalAssetEmitterPlugin({
        assetImports: options.assetImports,
        localImportMap,
      }),
      ...(hasCodeEntries ? [] : [createVirtualAssetEntryPlugin()]),
      createCanvasDependencyMetadataPlugin({
        hostRoot: options.projectRoot,
        hostAliasBaseDir: options.aliasBaseDir,
        metadata,
        externalizeBareImports: true,
        externalizeAliasImports: false,
      }),
      ...(baseConfig.plugins ?? []),
    ],
    build: {
      outDir: outputDirForLocalImports,
      emptyOutDir: false,
      manifest: true,
      cssCodeSplit: true,
      minify: true,
      sourcemap: false,
      copyPublicDir: false,
      rollupOptions: {
        input: hasCodeEntries
          ? codeEntries
          : { __canvas_assets: virtualAssetEntry },
        external: (source) => isCanvasProvidedExternal(source),
        preserveEntrySignatures: 'exports-only',
        output: {
          entryFileNames: '[name]-[hash].js',
          chunkFileNames: '[name]-[hash].js',
          assetFileNames: '[name]-[hash][extname]',
        },
      },
    },
  });

  const viteManifestPath = path.join(
    outputDirForLocalImports,
    '.vite',
    'manifest.json',
  );
  const viteManifestContent = await fs.readFile(viteManifestPath, 'utf-8');
  const viteManifest: Manifest = JSON.parse(viteManifestContent);
  const directlyMappedAssetFiles = new Set(Object.values(localImportMap));

  for (const [entryPath, entry] of Object.entries(viteManifest)) {
    if (!entry.isEntry) {
      const sharedChunkPath = `./local/${entry.file}`;
      if (!directlyMappedAssetFiles.has(sharedChunkPath)) {
        sharedChunks.push(sharedChunkPath);
      }
      continue;
    }

    if (entry.name === '__canvas_assets') {
      await fs.rm(path.join(outputDirForLocalImports, entry.file), {
        force: true,
      });
      continue;
    }

    const sourcePath = entry.src ?? entryPath;
    const source = sourceByResolvedPath.get(path.resolve(sourcePath));
    if (source) {
      localImportMap[source] = `./local/${entry.file}`.replace(/\\/g, '/');
    }
  }

  return {
    localImportMap,
    sharedChunks,
    thirdPartyPackages: metadata.thirdPartyPackages,
  };
}

export interface CanvasVendorArtifactBuildResult {
  importMap: { imports: Record<string, string> };
  bundledPackages: string[];
  sharedChunks: string[];
}

function packageNameToFileName(packageName: string): string {
  return packageName.replace(/\//g, '--');
}

export async function buildCanvasVendorArtifacts(options: {
  projectRoot: string;
  aliasBaseDir: string;
  outputDir: string;
  packages: Set<string>;
}): Promise<CanvasVendorArtifactBuildResult> {
  const importMap = { imports: {} as Record<string, string> };
  const bundledPackages: string[] = [];
  const packagesToBundle = [...options.packages].filter(
    (packageName) => !isCanvasProvidedExternal(packageName),
  );

  if (packagesToBundle.length === 0) {
    return { importMap, bundledPackages, sharedChunks: [] };
  }

  const vendorDir = path.join(path.resolve(options.outputDir), 'vendor');
  await fs.mkdir(vendorDir, { recursive: true });

  const entries: Record<string, string> = {};
  for (const packageName of packagesToBundle) {
    entries[packageNameToFileName(packageName)] = packageName;
  }

  const baseConfig = createCanvasViteBuildConfig({
    hostRoot: options.projectRoot,
    hostAliasBaseDir: options.aliasBaseDir,
  });

  await viteBuild({
    ...baseConfig,
    configFile: false,
    logLevel: 'silent',
    build: {
      outDir: vendorDir,
      emptyOutDir: true,
      manifest: true,
      cssCodeSplit: true,
      minify: true,
      sourcemap: false,
      copyPublicDir: false,
      rollupOptions: {
        input: entries,
        external: (source) => isCanvasProvidedExternal(source),
        treeshake: false,
        preserveEntrySignatures: 'exports-only',
        output: {
          format: 'esm',
          entryFileNames: '[name]-[hash].js',
          chunkFileNames: '[name]-[hash].js',
        },
      },
    },
  });

  const viteManifestPath = path.join(vendorDir, '.vite', 'manifest.json');
  const viteManifestContent = await fs.readFile(viteManifestPath, 'utf-8');
  const viteManifest: Manifest = JSON.parse(viteManifestContent);
  const sharedChunks: string[] = [];

  for (const info of Object.values(viteManifest)) {
    if (!info.isEntry) {
      const ext = path.extname(info.file).toLowerCase();
      if (!(FONT_EXTENSIONS as readonly string[]).includes(ext)) {
        sharedChunks.push(`./vendor/${info.file}`);
      }
      continue;
    }

    const entryName = info.name;
    const packageName = entryName ? entries[entryName] : undefined;
    if (!packageName) {
      continue;
    }

    importMap.imports[packageName] = `./vendor/${info.file}`;
    bundledPackages.push(packageName);
  }

  return { importMap, bundledPackages, sharedChunks };
}

export function drupalCanvasCompat(options: CanvasViteCompatOptions): Plugin[] {
  const canvasConfig = resolveCanvasConfig(options);
  const hostAliasBaseDir =
    options.hostAliasBaseDir ?? canvasConfig.aliasBaseDir;
  if (hasHostCanvasConfig(options.hostRoot)) {
    validateCanvasImportRoots({
      hostRoot: options.hostRoot,
      aliasBaseDir: hostAliasBaseDir,
      componentDir: canvasConfig.componentDir,
    });
  }
  const hostComponentDir = path.resolve(
    options.hostRoot,
    canvasConfig.componentDir,
  );

  const aliasPlugin: Plugin = {
    name: 'canvas-vite-compat-host-alias',
    enforce: 'pre',
    resolveId(source, importer) {
      if (!source.startsWith(hostAliasPrefix)) {
        return null;
      }

      if (!importer) {
        return null;
      }

      const normalizedImporter = normalizeViteImporterPath(importer);
      const normalizedHostRoot = normalizePath(options.hostRoot);
      if (!isPathWithinRoot(normalizedImporter, normalizedHostRoot)) {
        return null;
      }

      const suffix = source.slice(hostAliasPrefix.length);
      return resolveHostImportPath(options.hostRoot, hostAliasBaseDir, suffix);
    },
  };

  const plugins: Plugin[] = [
    aliasPlugin,
    ...(drupalCanvas({
      componentDir: hostComponentDir,
    }) as Plugin[]),
  ];

  plugins.push(
    svgr({
      include: '**/*.svg?react',
    }) as unknown as Plugin,
  );

  return plugins;
}
