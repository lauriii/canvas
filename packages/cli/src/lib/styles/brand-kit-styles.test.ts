import fs from 'fs/promises';
import os from 'os';
import path from 'path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import {
  collectBrandKitStyleErrors,
  writeBrandKitStylesConfig,
} from './brand-kit-styles';

describe('brand kit style options', () => {
  let dir: string;
  beforeEach(async () => {
    dir = await fs.mkdtemp(path.join(os.tmpdir(), 'brand-kit-styles-'));
  });
  afterEach(async () => {
    await fs.rm(dir, { recursive: true, force: true });
  });

  it('rejects fallbacks to options the site does not offer', () => {
    expect(
      collectBrandKitStyleErrors({
        palette: {
          options: ['base'],
          fallbacks: { muted: 'base', ink: 'brand' },
        },
      }),
    ).toEqual([
      'palette: the fallback for "ink" is "brand", which is not in options. Fall back to an option this site offers.',
    ]);
  });

  it('writes styles beside other keys, and removes them when unset', async () => {
    const file = path.join(dir, 'canvas.brand-kit.json');
    await fs.writeFile(file, JSON.stringify({ colors: {} }));
    expect(
      await writeBrandKitStylesConfig(dir, {
        corners: { options: ['square'] },
      }),
    ).toBe(true);
    expect(JSON.parse(await fs.readFile(file, 'utf-8'))).toEqual({
      colors: {},
      styles: { corners: { options: ['square'] } },
    });
    expect(
      await writeBrandKitStylesConfig(dir, {
        corners: { options: ['square'] },
      }),
    ).toBe(false);
    expect(await writeBrandKitStylesConfig(dir, undefined)).toBe(true);
    expect(JSON.parse(await fs.readFile(file, 'utf-8'))).toEqual({
      colors: {},
    });
  });

  it('does not create a file for a site without style options', async () => {
    expect(await writeBrandKitStylesConfig(dir, undefined)).toBe(false);
    await expect(
      fs.access(path.join(dir, 'canvas.brand-kit.json')),
    ).rejects.toThrow();
  });
});
