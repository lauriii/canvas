import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import type { CanvasConfig } from './types';

export function resolveCanvasConfig(options: {
  hostRoot: string;
}): CanvasConfig {
  const DEFAULT_CANVAS_CONFIG: CanvasConfig = {
    aliasBaseDir: 'src',
    outputDir: 'dist',
    componentDir: 'src/components',
    pagesDir: './pages',
    contentTemplatesDir: './content-templates',
    regionsDir: './regions',
    deprecatedComponentDir: './components',
    globalCssPath: './src/components/global.css',
    layoutPath: './src/layout.jsx',
  };

  const configPath = resolve(options.hostRoot, 'canvas.config.json');
  if (!existsSync(configPath)) {
    return { ...DEFAULT_CANVAS_CONFIG };
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
        parsed.globalCssPath ?? DEFAULT_CANVAS_CONFIG.globalCssPath,
      layoutPath: parsed.layoutPath ?? DEFAULT_CANVAS_CONFIG.layoutPath,
    };
  } catch {
    return { ...DEFAULT_CANVAS_CONFIG };
  }
}
