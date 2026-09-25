import { createRequire } from 'node:module';
import path from 'node:path';
import { defineConfig } from 'vitest/config';

// The package's own `react` copy is a peer-installed React 19 required by the
// json-render fork, while the workspace's `react-dom` is React 18. Tests that
// render must use one matched pair, so both resolve to the workspace root copy.
const require = createRequire(path.resolve(__dirname, '../../package.json'));
const reactRoot = path.dirname(require.resolve('react/package.json'));
const reactDomRoot = path.dirname(require.resolve('react-dom/package.json'));

export default defineConfig({
  resolve: {
    alias: [
      { find: /^react$/, replacement: reactRoot },
      { find: /^react\/(.*)$/, replacement: `${reactRoot}/$1` },
      { find: /^react-dom$/, replacement: reactDomRoot },
      { find: /^react-dom\/(.*)$/, replacement: `${reactDomRoot}/$1` },
    ],
  },
});
