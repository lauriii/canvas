import { drupalCanvasCompat } from '@drupal-canvas/vite-compat';

import type { UserConfig } from 'vite';

// The following packages are bundled by Drupal Canvas, and are provided by
// default in its import map. We don't need to bundle them.
export const DRUPAL_CANVAS_EXTERNALS = [
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
