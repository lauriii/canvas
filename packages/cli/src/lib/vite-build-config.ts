import { drupalCanvasCompat } from '@drupal-canvas/vite-compat';

import type { UserConfig } from 'vite';

export interface CanvasViteBuildConfigOptions {
  scanRoot: string;
  aliasBaseDir: string;
}

export function createCanvasViteBuildConfig(
  options: CanvasViteBuildConfigOptions,
): UserConfig {
  return {
    // Use project root (cwd) so Vite can resolve all paths correctly
    root: process.cwd(),
    plugins: [
      ...drupalCanvasCompat({
        // hostRoot is project root, aliasBaseDir is relative to it
        hostRoot: process.cwd(),
        hostAliasBaseDir: options.aliasBaseDir,
      }),
    ],
  };
}
