import { defineConfig } from 'vite';

import { createWorkbenchConfig } from './src/server/create-workbench-config';

export default defineConfig(
  createWorkbenchConfig({
    clientRootRelativePath: 'dist/client/src/client',
    useWorkbenchSourceAlias: true,
  }),
);
