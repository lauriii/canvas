import { existsSync, promises as fs, statSync } from 'node:fs';
import path from 'node:path';
import * as yaml from 'js-yaml';
import svgr from 'vite-plugin-svgr';
import { resolveCanvasConfig } from '@drupal-canvas/discovery';
import drupalCanvas from '@drupal-canvas/vite-plugin';

import type { Plugin } from 'vite';

export interface CanvasViteCompatOptions {
  hostRoot: string;
  hostAliasBaseDir?: string;
}

const WORKBENCH_HOST_GLOBAL_CSS_VIRTUAL_URL =
  '/@id/virtual:canvas-host-global.css';

function asRecord(value: unknown): Record<string, unknown> | null {
  return typeof value === 'object' && value !== null
    ? (value as Record<string, unknown>)
    : null;
}

function normalizePath(value: string): string {
  return value.replaceAll('\\', '/');
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
  const unresolvedTarget = path.resolve(
    hostRoot,
    hostAliasBaseDir,
    sourceSuffix,
  );

  if (path.extname(unresolvedTarget)) {
    return unresolvedTarget;
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
      return candidate;
    }
  }

  return unresolvedTarget;
}

function isPathWithinRoot(filePath: string, rootPath: string): boolean {
  const relative = path.relative(rootPath, filePath);
  return (
    relative !== '' && !relative.startsWith('..') && !path.isAbsolute(relative)
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

export function drupalCanvasCompat(options: CanvasViteCompatOptions): Plugin[] {
  const canvasConfig = resolveCanvasConfig(options);
  const hostAliasPrefix = '@/';
  const hostAliasBaseDir =
    options.hostAliasBaseDir ?? canvasConfig.aliasBaseDir;
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
      return resolveHostAliasPath(options.hostRoot, hostAliasBaseDir, suffix);
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
      include: '**/*.svg',
    }) as unknown as Plugin,
  );

  return plugins;
}
