import path from 'node:path';
import {
  drupalCanvasCompat,
  drupalCanvasCompatServer,
} from '@drupal-canvas/vite-compat';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';

import { createWorkbenchPlugin } from './create-workbench-plugin';
import { resolveWorkbenchPaths } from './paths';

import type { UserConfig } from 'vite';

export interface CreateWorkbenchConfigOptions {
  clientRootRelativePath?: string;
  useWorkbenchSourceAlias?: boolean;
}

export function createWorkbenchConfig(
  options: CreateWorkbenchConfigOptions,
): UserConfig {
  const paths = resolveWorkbenchPaths({
    moduleUrl: import.meta.url,
    clientRootRelativePath: options.clientRootRelativePath,
  });

  return {
    root: paths.clientRoot,
    server: {
      ...drupalCanvasCompatServer({
        hostRoot: paths.hostProjectRoot,
      }),
      fs: {
        allow: paths.allowedFsRoots,
      },
    },
    optimizeDeps: {
      exclude: ['next-image-standalone'],
    },
    plugins: [
      react(),
      tailwindcss(),
      ...drupalCanvasCompat({
        hostRoot: paths.hostProjectRoot,
      }),
      createWorkbenchPlugin(paths),
    ] as any,
    resolve: {
      dedupe: [
        'react',
        'react-dom',
        'react/jsx-runtime',
        'react/jsx-dev-runtime',
      ],
      alias: {
        ...(options.useWorkbenchSourceAlias
          ? {
              '@wb': paths.workbenchSourceRoot,
            }
          : {}),
        react: path.join(paths.workbenchNodeModulesPath, 'react'),
        'react-dom': path.join(paths.workbenchNodeModulesPath, 'react-dom'),
        'react/jsx-runtime': path.join(
          paths.workbenchNodeModulesPath,
          'react/jsx-runtime.js',
        ),
        'react/jsx-dev-runtime': path.join(
          paths.workbenchNodeModulesPath,
          'react/jsx-dev-runtime.js',
        ),
      },
    },
  };
}
