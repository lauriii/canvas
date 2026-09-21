import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { beforeAll, describe, expect, it } from 'vitest';

// client/draft-session.tsx and canvas-component-tree.tsx both carry a
// 'use client' directive that must survive bundling: without it at the very
// top of the built module, React Server Component bundlers treat the module
// as server code and every consumer build breaks.
const packageRoot = path.resolve(fileURLToPath(import.meta.url), '../..');

beforeAll(() => {
  execFileSync('npx', ['tsdown'], {
    cwd: packageRoot,
    env: { ...process.env, NODE_ENV: 'production' },
  });
}, 30_000);

describe('built output', () => {
  it.each([['client/draft-session.js'], ['canvas-component-tree.js']])(
    'keeps the "use client" directive at the top of dist/%s',
    (relativePath) => {
      const built = readFileSync(
        path.join(packageRoot, 'dist', relativePath),
        'utf-8',
      );

      expect(built).toMatch(/^(['"])use client\1;/);
    },
  );
});
