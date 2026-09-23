import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { withCanvas } from './with-canvas';

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
