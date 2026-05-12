import {
  createCanvasViteBuildConfig as createSharedCanvasViteBuildConfig,
  DRUPAL_CANVAS_EXTERNALS,
} from '@drupal-canvas/vite-compat';

export { DRUPAL_CANVAS_EXTERNALS };

export interface CanvasViteBuildConfigOptions {
  scanRoot: string;
  aliasBaseDir: string;
}

export function createCanvasViteBuildConfig(
  options: CanvasViteBuildConfigOptions,
): ReturnType<typeof createSharedCanvasViteBuildConfig> {
  return createSharedCanvasViteBuildConfig({
    hostRoot: process.cwd(),
    hostAliasBaseDir: options.aliasBaseDir,
  });
}
