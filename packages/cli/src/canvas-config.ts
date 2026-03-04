import fs from 'fs';
import path from 'path';
import * as p from '@clack/prompts';

export interface CanvasConfig {
  aliasBaseDir: string;
  outputDir: string;
  componentDir: string;
  deprecatedComponentDir: string;
}

const DEFAULT_CANVAS_CONFIG: CanvasConfig = {
  aliasBaseDir: 'src',
  outputDir: 'dist',
  componentDir: process.cwd(),
  deprecatedComponentDir: './components',
};

/**
 * Loads canvas.config.json from the current working directory.
 * Falls back to defaults if the file is absent or unparsable.
 */
export function loadCanvasConfig(): CanvasConfig {
  const configPath = path.resolve(process.cwd(), 'canvas.config.json');
  if (!fs.existsSync(configPath)) {
    return { ...DEFAULT_CANVAS_CONFIG };
  }

  try {
    const raw = fs.readFileSync(configPath, 'utf-8');
    const parsed = JSON.parse(raw) as Partial<CanvasConfig>;
    return {
      aliasBaseDir: parsed.aliasBaseDir ?? DEFAULT_CANVAS_CONFIG.aliasBaseDir,
      outputDir: parsed.outputDir ?? DEFAULT_CANVAS_CONFIG.outputDir,
      componentDir: parsed.componentDir ?? DEFAULT_CANVAS_CONFIG.componentDir,
      deprecatedComponentDir:
        parsed.componentDir ?? DEFAULT_CANVAS_CONFIG.deprecatedComponentDir,
    };
  } catch {
    p.log.warn(
      'canvas.config.json could not be parsed. Using default configuration.',
    );
    return { ...DEFAULT_CANVAS_CONFIG };
  }
}
