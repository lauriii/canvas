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
  regionsDir: 'regions',
  deprecatedComponentDir: 'components',
  globalCssPath: 'src/global.css',
  layoutPath: 'src/layout.jsx',
  sync: {
    pages: true,
    contentTemplates: true,
    regions: true,
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
    const parsed = JSON.parse(raw) as Partial<CanvasConfig>;
    return {
      aliasBaseDir: parsed.aliasBaseDir ?? DEFAULT_CANVAS_CONFIG.aliasBaseDir,
      outputDir: parsed.outputDir ?? DEFAULT_CANVAS_CONFIG.outputDir,
      componentDir: parsed.componentDir ?? DEFAULT_CANVAS_CONFIG.componentDir,
      pagesDir: parsed.pagesDir ?? DEFAULT_CANVAS_CONFIG.pagesDir,
      contentTemplatesDir:
        parsed.contentTemplatesDir ?? DEFAULT_CANVAS_CONFIG.contentTemplatesDir,
      regionsDir: parsed.regionsDir ?? DEFAULT_CANVAS_CONFIG.regionsDir,
      deprecatedComponentDir:
        parsed.componentDir ?? DEFAULT_CANVAS_CONFIG.deprecatedComponentDir,
      globalCssPath:
        parsed.globalCssPath ?? resolveDefaultGlobalCssPath(options),
      layoutPath: parsed.layoutPath ?? DEFAULT_CANVAS_CONFIG.layoutPath,
      sync: {
        pages: parsed.sync?.pages ?? DEFAULT_CANVAS_CONFIG.sync.pages,
        contentTemplates:
          parsed.sync?.contentTemplates ??
          DEFAULT_CANVAS_CONFIG.sync.contentTemplates,
        regions: parsed.sync?.regions ?? DEFAULT_CANVAS_CONFIG.sync.regions,
      },
    };
  } catch {
    return {
      ...DEFAULT_CANVAS_CONFIG,
      globalCssPath: resolveDefaultGlobalCssPath(options),
    };
  }
}
