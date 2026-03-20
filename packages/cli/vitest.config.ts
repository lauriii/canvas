import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

function resolveWorkspaceSource(relativePath: string): string {
  return fileURLToPath(new URL(relativePath, import.meta.url));
}

export default defineConfig({
  resolve: {
    alias: {
      '@drupal-canvas/discovery': resolveWorkspaceSource(
        '../discovery/src/index.ts',
      ),
      '@drupal-canvas/vite-compat': resolveWorkspaceSource(
        '../vite-compat/src/index.ts',
      ),
      '@drupal-canvas/vite-plugin': resolveWorkspaceSource(
        '../vite-plugin/src/index.ts',
      ),
    },
  },
  test: {
    environment: 'node',
    globals: true,
    setupFiles: ['./vitest.setup.ts'],
    mockReset: true,
    restoreMocks: true,
  },
});
