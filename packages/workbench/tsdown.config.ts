import { readdir, rm } from 'node:fs/promises';
import path from 'node:path';
import { defineConfig } from 'tsdown';

async function removeCopiedTestFiles(directoryPath: string): Promise<void> {
  const entries = await readdir(directoryPath, { withFileTypes: true });

  await Promise.all(
    entries.map(async (entry) => {
      const entryPath = path.join(directoryPath, entry.name);

      if (entry.isDirectory()) {
        await removeCopiedTestFiles(entryPath);
        return;
      }

      if (/\.(test|spec)\.[cm]?[jt]sx?$/.test(entry.name)) {
        await rm(entryPath);
      }
    }),
  );
}

export default defineConfig({
  clean: ['dist'],
  copy: [
    { from: 'public/**/*', to: 'dist/client', flatten: false },
    { from: 'src/client/**/*', to: 'dist/client/src', flatten: false },
    { from: 'src/lib/**/*', to: 'dist/client/src', flatten: false },
  ],
  deps: {
    // Bundle these workspace packages into the published config so consumers do
    // not need the Canvas monorepo source layout at runtime.
    alwaysBundle: ['@drupal-canvas/discovery', '@drupal-canvas/vite-compat'],
    // These are pulled in transitively when the published Vite config bundles
    // the discovery and Vite compatibility helpers used by the Workbench server.
    onlyBundle: ['glob', 'ignore', 'js-yaml'],
    neverBundle: [
      'vite',
      '@vitejs/plugin-react',
      '@tailwindcss/vite',
      'vite-plugin-svgr',
      'react',
      'react-dom',
      'react/jsx-runtime',
      'react/jsx-dev-runtime',
    ],
  },
  dts: false,
  entry: ['vite.published.config.ts', 'src/server/preview-build.ts'],
  format: ['es'],
  hooks: {
    async 'build:done'(ctx) {
      await removeCopiedTestFiles(
        path.join(ctx.options.outDir, '../client/src'),
      );
    },
  },
  outDir: 'dist/server',
  platform: 'node',
});
