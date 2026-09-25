import { defineConfig } from 'vitest/config';

// Start normally generates these modules with its Vite plugin. The JSON:API
// proxy PUT-rejection test supplies them in-memory without building or serving
// an app.
const entries: Record<string, string> = {
  '#tanstack-router-entry': 'export const getRouter = () => {};',
  '#tanstack-start-entry': 'export const startInstance = undefined;',
  '#tanstack-start-plugin-adapters': 'export const hasPluginAdapters = false;',
  '#tanstack-start-server-fn-resolver':
    'export const getServerFnById = () => {};',
  'tanstack-start-manifest:v':
    'export const tsrStartManifest = () => ({ routes: {} });',
};

export default defineConfig({
  plugins: [
    {
      name: 'start-route-test-entries',
      enforce: 'pre',
      resolveId(id) {
        if (id in entries) return id;
      },
      load(id) {
        return entries[id];
      },
    },
  ],
  test: {
    server: { deps: { inline: [/@tanstack\//] } },
  },
});
