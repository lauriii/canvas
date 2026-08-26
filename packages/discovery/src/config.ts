import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import type { CanvasConfig } from './types';

const LEGACY_GLOBAL_CSS_PATH = './src/components/global.css';

export const DEFAULT_CANVAS_CONFIG: CanvasConfig = {
  aliasBaseDir: 'src',
  outputDir: 'dist',
  componentDir: 'src/components',
  pagesDir: 'pages',
  contentTemplatesDir: 'content-templates',
  pageTemplatesDir: 'page-templates',
  globalCssPath: 'src/global.css',
  sync: {
    pages: true,
    contentTemplates: true,
    pageTemplates: true,
  },
};

export interface CanvasConfigWarning {
  code: 'legacy_default_global_css_path';
  message: string;
  path: string;
}

interface ResolveCanvasConfigOptions {
  hostRoot: string;
  onWarning?: (warning: CanvasConfigWarning) => void;
}

function resolveDefaultGlobalCssPath(
  options: ResolveCanvasConfigOptions,
): string {
  const defaultGlobalCssPath = resolve(
    options.hostRoot,
    DEFAULT_CANVAS_CONFIG.globalCssPath,
  );
  const legacyGlobalCssPath = resolve(options.hostRoot, LEGACY_GLOBAL_CSS_PATH);

  if (!existsSync(defaultGlobalCssPath) && existsSync(legacyGlobalCssPath)) {
    options.onWarning?.({
      code: 'legacy_default_global_css_path',
      path: LEGACY_GLOBAL_CSS_PATH,
      message:
        `Canvas is using the legacy default global CSS path ${LEGACY_GLOBAL_CSS_PATH} because ` +
        `globalCssPath is not set. Move the file to ${DEFAULT_CANVAS_CONFIG.globalCssPath}, or add ` +
        `"globalCssPath": "${LEGACY_GLOBAL_CSS_PATH}" to canvas.config.json to keep this location. ` +
        'The implicit fallback will be removed in a future release.',
    });
    return LEGACY_GLOBAL_CSS_PATH;
  }

  return DEFAULT_CANVAS_CONFIG.globalCssPath;
}

export function resolveCanvasConfig(
  options: ResolveCanvasConfigOptions,
): CanvasConfig {
  const configPath = resolve(options.hostRoot, 'canvas.config.json');
  if (!existsSync(configPath)) {
    return {
      ...DEFAULT_CANVAS_CONFIG,
      globalCssPath: resolveDefaultGlobalCssPath(options),
    };
  }

  try {
    const raw = readFileSync(configPath, 'utf-8');
    const parsed = JSON.parse(raw) as Partial<CanvasConfig> & {
      // Region-era keys, parsed only so consumers can warn about the
      // regions-to-page-variants upgrade path.
      regionsDir?: unknown;
      layoutPath?: unknown;
      sync?: Partial<CanvasConfig['sync']> & { regions?: unknown };
    };
    const legacy: CanvasConfig['legacy'] = {};
    if (typeof parsed.regionsDir === 'string') {
      legacy.regionsDir = parsed.regionsDir;
    }
    if (typeof parsed.sync?.regions === 'boolean') {
      legacy.syncRegions = parsed.sync.regions;
    }
    if (typeof parsed.layoutPath === 'string') {
      legacy.layoutPath = parsed.layoutPath;
    }
    return {
      aliasBaseDir: parsed.aliasBaseDir ?? DEFAULT_CANVAS_CONFIG.aliasBaseDir,
      outputDir: parsed.outputDir ?? DEFAULT_CANVAS_CONFIG.outputDir,
      componentDir: parsed.componentDir ?? DEFAULT_CANVAS_CONFIG.componentDir,
      pagesDir: parsed.pagesDir ?? DEFAULT_CANVAS_CONFIG.pagesDir,
      contentTemplatesDir:
        parsed.contentTemplatesDir ?? DEFAULT_CANVAS_CONFIG.contentTemplatesDir,
      pageTemplatesDir:
        parsed.pageTemplatesDir ?? DEFAULT_CANVAS_CONFIG.pageTemplatesDir,
      globalCssPath:
        parsed.globalCssPath ?? resolveDefaultGlobalCssPath(options),
      sync: {
        pages: parsed.sync?.pages ?? DEFAULT_CANVAS_CONFIG.sync.pages,
        contentTemplates:
          parsed.sync?.contentTemplates ??
          DEFAULT_CANVAS_CONFIG.sync.contentTemplates,
        pageTemplates:
          parsed.sync?.pageTemplates ??
          DEFAULT_CANVAS_CONFIG.sync.pageTemplates,
      },
      ...(Object.keys(legacy).length > 0 ? { legacy } : {}),
    };
  } catch {
    return {
      ...DEFAULT_CANVAS_CONFIG,
      globalCssPath: resolveDefaultGlobalCssPath(options),
    };
  }
}
