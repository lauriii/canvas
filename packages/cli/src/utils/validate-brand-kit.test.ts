import fs from 'fs/promises';
import os from 'os';
import path from 'path';
import Ajv from 'ajv/dist/2020.js';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import brandKitSchema from '../../../workbench/src/lib/schemas/brand-kit.schema.json';
import { planColorPull } from '../lib/colors/color-pull';
import { validateBrandKit } from './validate-brand-kit';

import type { BrandKitColorEntry } from '../types/Component';

let tmpDir: string;

beforeEach(async () => {
  tmpDir = await fs.mkdtemp(path.join(os.tmpdir(), 'validate-brand-kit-'));
});

afterEach(async () => {
  await fs.rm(tmpDir, { recursive: true, force: true });
});

async function writeBrandKit(content: unknown): Promise<void> {
  await fs.writeFile(
    path.join(tmpDir, 'canvas.brand-kit.json'),
    typeof content === 'string' ? content : JSON.stringify(content, null, 2),
    'utf-8',
  );
}

describe('validateBrandKit', () => {
  it('returns no results when the file does not exist', async () => {
    expect((await validateBrandKit(tmpDir)).results).toEqual([]);
  });

  it('accepts a valid file in every supported form', async () => {
    await fs.mkdir(path.join(tmpDir, 'fonts'), { recursive: true });
    await fs.writeFile(
      path.join(tmpDir, 'fonts/inter.woff2'),
      Buffer.from([0x00]),
    );
    await writeBrandKit({
      $schema: 'https://example.com/brand-kit.schema.json',
      fonts: {
        defaults: { weights: ['400'], styles: ['normal'] },
        families: [
          { name: 'Inter', src: 'fonts/inter.woff2', weights: ['400'] },
          { name: 'Lora', provider: 'google', subsets: ['latin'] },
        ],
      },
      colors: {
        'brand-red': '#cc0000',
        '--prefixed': 'rgb(20, 24, 31)',
        overlay: 'hsla(220, 60%, 50%, 0.5)',
        exact: { colorSpace: 'srgb', components: [0.1, 0.2, 0.3] },
        accent: { value: '#00cc00', name: 'Accent green' },
      },
    });

    const { results } = await validateBrandKit(tmpDir);
    expect(results).toEqual([
      { itemName: 'canvas.brand-kit.json', success: true, details: undefined },
    ]);
  });

  it('reports invalid JSON as a single failure', async () => {
    await writeBrandKit('{ "colors": {');
    const { results } = await validateBrandKit(tmpDir);
    expect(results[0].success).toBe(false);
    expect(results[0].details?.[0].content).toContain('Invalid JSON');
  });

  it('reports schema violations such as a mistyped wrapper property', async () => {
    await writeBrandKit({
      colors: { accent: { value: '#00cc00', label: 'not a wrapper field' } },
    });
    const { results } = await validateBrandKit(tmpDir);
    expect(results[0].success).toBe(false);
    const contents = (results[0].details ?? []).map((d) => d.content);
    expect(contents.join('\n')).toContain('label');
  });

  it('reports semantic color errors a schema cannot express', async () => {
    await writeBrandKit({
      colors: { 'brand-red': '#cc0000', '--brand-red': '#0000cc' },
    });
    const { results } = await validateBrandKit(tmpDir);
    expect(results[0].success).toBe(false);
    const colorErrors = (results[0].details ?? [])
      .filter((d) => d.heading === 'colors')
      .map((d) => d.content);
    expect(colorErrors.join('\n')).toContain('duplicate of "brand-red"');
  });

  it('reports a missing local font file', async () => {
    await writeBrandKit({
      fonts: { families: [{ name: 'Inter', src: 'fonts/missing.woff2' }] },
    });
    const { results } = await validateBrandKit(tmpDir);
    expect(results[0].success).toBe(false);
    const fontErrors = (results[0].details ?? [])
      .filter((d) => d.heading === 'fonts')
      .map((d) => d.content);
    expect(fontErrors.join('\n')).toContain('file not found');
  });

  it('reports malformed color values through both schema and semantics', async () => {
    await writeBrandKit({ colors: { bad: '#cc00' } });
    const { results } = await validateBrandKit(tmpDir);
    expect(results[0].success).toBe(false);
  });

  it('reports out-of-range token components through both schema and semantics', async () => {
    await writeBrandKit({
      colors: { bad: { colorSpace: 'srgb', components: [1.5, 0, 0] } },
    });
    const { results } = await validateBrandKit(tmpDir);
    expect(results[0].success).toBe(false);
    const contents = (results[0].details ?? [])
      .map((d) => d.content)
      .join('\n');
    expect(contents).toContain('between 0 and 1');
  });
});

describe('brand kit schema and pull output stay in sync', () => {
  function remoteColor(
    overrides: Partial<BrandKitColorEntry>,
  ): BrandKitColorEntry {
    return {
      id: 'uuid',
      name: 'Brand Red',
      cssVariable: '--brand-red',
      value: {
        colorSpace: 'srgb',
        components: [204 / 255, 0, 0],
        alpha: null,
        hex: '#cc0000',
      },
      displayFormat: null,
      weight: 0,
      ...overrides,
    };
  }

  it('everything a pull writes validates against the schema', () => {
    const plan = planColorPull(
      [
        remoteColor({}),
        remoteColor({
          cssVariable: '--labeled',
          name: 'A UI Label',
          weight: 1,
        }),
        remoteColor({
          cssVariable: '--ink',
          name: 'Ink',
          displayFormat: 'rgb',
          weight: 2,
          value: {
            colorSpace: 'srgb',
            components: [20 / 255, 24 / 255, 31 / 255],
            alpha: null,
            hex: '#14181f',
          },
        }),
        remoteColor({
          cssVariable: '--overlay',
          name: 'Overlay',
          weight: 3,
          value: {
            colorSpace: 'hsl',
            components: [220, 60, 50],
            alpha: 0.5,
            hex: null,
          },
        }),
        remoteColor({
          cssVariable: '--odd',
          name: 'Odd',
          weight: 4,
          value: {
            colorSpace: 'srgb',
            components: [0.33, 0.66, 0.99],
            alpha: null,
            hex: '#54a8fc',
          },
        }),
        remoteColor({
          cssVariable: '--mismatch',
          name: 'Mismatch',
          displayFormat: 'hsl',
          weight: 5,
        }),
      ],
      undefined,
    );

    const ajv = new Ajv({ allowUnionTypes: true });
    const validate = ajv.compile(brandKitSchema);
    const file = {
      $schema: 'https://example.com/brand-kit.schema.json',
      colors: plan.colors,
    };
    expect({ valid: validate(file), errors: validate.errors }).toEqual({
      valid: true,
      errors: null,
    });
  });
});
