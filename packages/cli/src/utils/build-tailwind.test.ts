import { promises as fs } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { compileCss } from 'tailwindcss-in-browser';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { transformCss } from '../lib/transform-css';
import { buildTailwindCss } from './build-tailwind';

vi.mock('tailwindcss-in-browser', () => ({
  compileCss: vi.fn(async () => 'compiled css'),
  extractClassNameCandidates: vi.fn(() => []),
}));

vi.mock('../lib/transform-css', () => ({
  transformCss: vi.fn(async () => 'transformed css'),
}));

describe('buildTailwindCss', () => {
  let temporaryDirectory: string;

  beforeEach(async () => {
    temporaryDirectory = await fs.mkdtemp(
      path.join(os.tmpdir(), 'canvas-build-tailwind-'),
    );
  });

  afterEach(async () => {
    vi.clearAllMocks();
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
});
