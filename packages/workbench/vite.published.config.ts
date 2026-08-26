import { defineConfig } from 'vite';

import { createWorkbenchConfig } from './src/server/create-workbench-config';
import { trustSystemCertificates } from './src/server/system-ca';

trustSystemCertificates();

export default defineConfig(
  createWorkbenchConfig({
    clientRootRelativePath: 'dist/client/src/client',
    useWorkbenchSourceAlias: true,
  }),
);
