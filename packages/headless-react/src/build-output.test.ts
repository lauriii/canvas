import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';

// draft-session.tsx carries a 'use client' directive that must survive
// bundling: without it at the very top of its built module, React Server
// Component bundlers treat the module as server code and every consumer
// build breaks.
const packageRoot = path.resolve(fileURLToPath(import.meta.url), '../..');
// Not dist/: other packages' tests resolve this package from it, in parallel.
const outDir = mkdtempSync(path.join(tmpdir(), 'headless-react-build-'));

beforeAll(() => {
  execFileSync('npx', ['tsdown', '--out-dir', outDir, '--no-clean'], {
    cwd: packageRoot,
    env: { ...process.env, NODE_ENV: 'production' },
  });
}, 30_000);

afterAll(() => {
  rmSync(outDir, { recursive: true, force: true });
});

describe('built output', () => {
  it('keeps the "use client" directive at the top of dist/draft-session.js', () => {
    const built = readFileSync(path.join(outDir, 'draft-session.js'), 'utf-8');

    expect(built).toMatch(/^(['"])use client\1;/);
  });

  it('keeps the "use client" directive at the top of dist/translations.js', () => {
    const built = readFileSync(path.join(outDir, 'translations.js'), 'utf-8');

    expect(built).toMatch(/^(['"])use client\1;/);
  });
});
