import { mkdir, mkdtemp, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { withCanvas } from './with-canvas';

const PHASE_PRODUCTION_BUILD = 'phase-production-build';
const MOUNT = `export { canvasMiddleware as proxy } from '@drupal-canvas/headless-next/middleware';`;

const context = {
  defaultConfig: { pageExtensions: ['tsx', 'ts', 'jsx', 'js'] },
};

const cspRule = {
  async headers() {
    return [
      {
        source: '/:path*',
        headers: [
          { key: 'Content-Security-Policy', value: "default-src 'self'" },
        ],
      },
    ];
  },
};

async function project(files: Record<string, string> = {}): Promise<string> {
  const root = await mkdtemp(path.join(tmpdir(), 'canvas-with-canvas-'));
  for (const [name, contents] of Object.entries(files)) {
    await mkdir(path.dirname(path.join(root, name)), { recursive: true });
    await writeFile(path.join(root, name), contents);
  }
  return root;
}

function build(projectRoot: string, config = {}) {
  return withCanvas(config, { projectRoot })(PHASE_PRODUCTION_BUILD, context);
}

afterEach(() => {
  vi.restoreAllMocks();
  delete process.env.CANVAS_MIDDLEWARE_WARNING_SHOWN;
});

describe('a Content-Security-Policy configured through headers()', () => {
  it('fails the build', async () => {
    const root = await project({ 'proxy.ts': MOUNT });
    await expect(build(root, cspRule)).rejects.toThrow(
      /sets a Content-Security-Policy/,
    );
  });

  it('is allowed through when it is some other header', async () => {
    const other = {
      async headers() {
        return [
          {
            source: '/:path*',
            headers: [{ key: 'X-Frame-Options', value: 'DENY' }],
          },
        ];
      },
    };
    const root = await project({ 'proxy.ts': MOUNT });
    // Reaching the manifest write means the CSP check passed; the temporary
    // project has no components to describe, so it fails later or not at all.
    await expect(build(root, other)).resolves.toBeDefined();
  });
});

describe('the missing-middleware warning', () => {
  it('warns when nothing mentions the package', async () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    await build(await project());
    expect(warn).toHaveBeenCalledWith(
      expect.stringContaining('No middleware mounting'),
    );
  });

  it.each(['proxy.ts', 'middleware.ts', 'src/proxy.ts', 'src/middleware.js'])(
    'stays quiet when %s mounts the handler',
    async (file) => {
      const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
      await build(await project({ [file]: MOUNT }));
      expect(warn).not.toHaveBeenCalledWith(
        expect.stringContaining('No middleware mounting'),
      );
    },
  );

  it('respects a narrowed pageExtensions', async () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    // Next.js would not load proxy.ts in a project that excludes `ts`.
    await withCanvas(
      { pageExtensions: ['js'] },
      { projectRoot: await project({ 'proxy.ts': MOUNT }) },
    )(PHASE_PRODUCTION_BUILD, context);
    expect(warn).toHaveBeenCalledWith(
      expect.stringContaining('No middleware mounting'),
    );
  });
});
