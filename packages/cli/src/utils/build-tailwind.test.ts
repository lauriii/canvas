import { promises as fs } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { compileCss } from 'tailwindcss-in-browser';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { transformCss } from '../lib/transform-css';
import { buildTailwindCss, getGlobalCss } from './build-tailwind';

vi.mock('tailwindcss-in-browser', () => ({
  compileCss: vi.fn(async () => 'compiled css'),
  extractClassNameCandidates: vi.fn(() => []),
}));

vi.mock('../lib/transform-css', () => ({
  transformCss: vi.fn(async () => 'transformed css'),
}));

describe('buildTailwindCss', () => {
  let temporaryDirectory: string;
  let originalCwd: string;

  beforeEach(async () => {
    vi.clearAllMocks();
    vi.mocked(compileCss).mockResolvedValue('compiled css');
    vi.mocked(transformCss).mockResolvedValue('transformed css');
    originalCwd = process.cwd();
    temporaryDirectory = await fs.mkdtemp(
      path.join(os.tmpdir(), 'canvas-build-tailwind-'),
    );
  });

  afterEach(async () => {
    process.chdir(originalCwd);
    await fs.rm(temporaryDirectory, { recursive: true, force: true });
  });

  it('passes display utilities that must remain unlayered to compileCss', async () => {
    await buildTailwindCss(
      ['hidden', 'md:block'],
      '@theme {}',
      temporaryDirectory,
    );

    expect(compileCss).toHaveBeenCalledWith(
      ['hidden', 'md:block'],
      '@theme {}',
      {
        unlayeredUtilities: expect.arrayContaining([
          'hidden',
          'block',
          'flex',
          'grid',
          'table',
        ]),
      },
    );
    expect(transformCss).toHaveBeenCalledWith('compiled css');
    await expect(
      fs.readFile(path.join(temporaryDirectory, 'index.css'), 'utf-8'),
    ).resolves.toBe('transformed css');
  });

  it('fails clearly when the local global CSS file is missing', async () => {
    process.chdir(temporaryDirectory);
    const globalCssPath = path.join(process.cwd(), 'src/global.css');

    await expect(getGlobalCss()).rejects.toEqual(
      new Error(
        `Missing local global CSS file at ${globalCssPath}. Create this file, or set "globalCssPath" in canvas.config.json to an existing CSS file.`,
      ),
    );
  });
});
