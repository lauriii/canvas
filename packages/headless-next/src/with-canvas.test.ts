import { mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { BRAND_KIT_CSS_MODULE_ID, withCanvas } from './with-canvas';

import type { NextConfig } from 'next';

let projectRoot: string;
beforeEach(async () => {
  projectRoot = await mkdtemp(path.join(tmpdir(), 'canvas-with-canvas-'));
  vi.stubEnv('CANVAS_COMPONENT_MANIFEST_JSON', '{}');
});
afterEach(async () => {
  vi.unstubAllEnvs();
  await rm(projectRoot, { recursive: true, force: true });
});

const resolve = (config: NextConfig = {}) =>
  withCanvas(config, { projectRoot })('phase-production-build', {
    defaultConfig: {},
  });

describe('request-time CSP migration', () => {
  it('does not emit a restrictive static policy alongside the middleware', async () => {
    const config = await resolve();
    expect(await config.headers!()).toEqual([]);
  });

  it('rejects application CSP case-insensitively with migration instructions', async () => {
    const config = await resolve({
      headers: async () => [
        {
          source: '/restricted/:path*',
          headers: [
            { key: 'Content-Security-Policy', value: "frame-ancestors 'none'" },
          ],
        },
      ],
    });
    await expect(config.headers!()).rejects.toThrow(
      'Set the complete policy on the response passed to applyCanvasHeaders()',
    );
  });

  it('preserves non-CSP and report-only header rules', async () => {
    const rules = [
      {
        source: '/:path*',
        headers: [
          { key: 'X-App-Header', value: 'app' },
          {
            key: 'Content-Security-Policy-Report-Only',
            value: "default-src 'self'",
          },
        ],
      },
    ];
    const config = await resolve({ headers: async () => rules });
    expect(await config.headers!()).toEqual(rules);
  });
});

describe('brand kit stylesheet', () => {
  it('generates .canvas/brand-kit.css and aliases it for Turbopack and webpack', async () => {
    await writeFile(
      path.join(projectRoot, 'canvas.brand-kit.json'),
      JSON.stringify({
        fonts: { families: [{ name: 'Brand', src: 'fonts/brand.woff2' }] },
      }),
    );
    const config = await resolve();
    const stylesheetPath = path.join(projectRoot, '.canvas/brand-kit.css');

    expect(await readFile(stylesheetPath, 'utf8')).toContain(
      "src: url('../fonts/brand.woff2') format('woff2');",
    );
    expect(config.turbopack?.resolveAlias?.[BRAND_KIT_CSS_MODULE_ID]).toBe(
      './.canvas/brand-kit.css',
    );
    const webpackConfig = config.webpack!({}, {} as never) as {
      resolve: { alias: Record<string, string> };
    };
    expect(webpackConfig.resolve.alias[BRAND_KIT_CSS_MODULE_ID]).toBe(
      stylesheetPath,
    );
  });
});
