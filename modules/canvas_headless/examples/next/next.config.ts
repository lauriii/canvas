import path from 'node:path';
import { withCanvas } from '@drupal-canvas/headless-next/config';

import type { NextConfig } from 'next';

const nextConfig: NextConfig = {
  turbopack: {
    // The SDK packages arrive as file: links into the Canvas repository,
    // and multiple lockfiles sit above this app — name the workspace root
    // explicitly instead of letting Next infer it. Anchored on cwd (the
    // app directory for every next command): __dirname is unusable here,
    // as Next compiles this config to a temp location before running it.
    root: path.join(process.cwd(), '../../../..'),
  },
};

// withCanvas() adds the Canvas headless integration: the build-time
// component manifest, transpilation of the raw-TypeScript SDK packages, and
// the session-aware CSP frame-ancestors header.
export default withCanvas(nextConfig);
