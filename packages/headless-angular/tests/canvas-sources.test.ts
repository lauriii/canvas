import { mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import { generateCanvasSources } from '../tools/build.mjs';

let projectRoot: string;
beforeEach(async () => {
  projectRoot = await mkdtemp(path.join(tmpdir(), 'canvas-angular-sources-'));
});
afterEach(async () => {
  await rm(projectRoot, { recursive: true, force: true });
});

const stylesheetPath = () =>
  path.join(projectRoot, 'src/canvas-brand-kit.generated.css');

describe('generateCanvasSources', () => {
  it('generates the brand kit stylesheet with fonts relative to src/', async () => {
    await writeFile(
      path.join(projectRoot, 'canvas.brand-kit.json'),
      JSON.stringify({
        fonts: { families: [{ name: 'Brand', src: 'fonts/brand.woff2' }] },
        colors: { 'brand-red': '#cc0000' },
      }),
    );

    await generateCanvasSources(projectRoot);

    const css = await readFile(stylesheetPath(), 'utf8');
    expect(css).toContain("src: url('../fonts/brand.woff2') format('woff2');");
    expect(css).toContain('--brand-red: #cc0000;');
  });

  it('writes an empty stylesheet without a brand kit file', async () => {
    await generateCanvasSources(projectRoot);

    expect(await readFile(stylesheetPath(), 'utf8')).toBe('');
  });
});
