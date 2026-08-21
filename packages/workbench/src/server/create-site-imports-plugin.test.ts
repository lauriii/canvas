import { afterEach, describe, expect, it, vi } from 'vitest';

import {
  createSiteImportsPlugin,
  getSiteImportProxyPrefixes,
} from './create-site-imports-plugin';

import type { ImportMap } from '@drupal-canvas/vite-compat';
import type { Plugin } from 'vite';

const IMPORT_MAP: ImportMap = {
  imports: {
    react: '/modules/contrib/canvas/packages/astro-hydration/dist/compat.js',
    'canvas_forms/useCanvasForm': '/modules/custom/canvas_forms/js/form.js',
  },
  scopes: {},
};

type ResolveId = (
  source: string,
  importer?: string,
) => string | null | undefined;
type Load = (id: string) => Promise<string | null>;

function hooks(plugin: Plugin): { resolveId: ResolveId; load: Load } {
  return {
    resolveId: plugin.resolveId as unknown as ResolveId,
    load: plugin.load as unknown as Load,
  };
}

afterEach(() => {
  vi.unstubAllGlobals();
});

describe('site imports plugin', () => {
  it('runs last, so specifiers with a local copy never reach it', () => {
    // React is in the import map, but Workbench resolves it from its own
    // install tree. A `post` plugin only sees what nothing else resolved,
    // which is what keeps one React runtime in the preview.
    expect(
      createSiteImportsPlugin(IMPORT_MAP, 'https://site.test').enforce,
    ).toBe('post');
  });

  it('pulls a site-provided module into the build rather than linking to it', async () => {
    const fetchMock = vi.fn().mockResolvedValue({
      ok: true,
      text: async () => "import { useState } from 'react';\n",
    });
    vi.stubGlobal('fetch', fetchMock);
    const { resolveId, load } = hooks(
      createSiteImportsPlugin(IMPORT_MAP, 'https://site.test'),
    );

    const id = resolveId('canvas_forms/useCanvasForm');
    expect(id).toBeTruthy();

    // The module's own imports have to go through Vite too: a hook that calls
    // useState imports React, and serving the file to the browser directly
    // would leave that bare specifier unresolvable.
    await expect(load(id as string)).resolves.toContain("from 'react'");
    expect(fetchMock).toHaveBeenCalledWith(
      'https://site.test/modules/custom/canvas_forms/js/form.js',
    );
  });

  it("resolves a site module's relative imports against its own URL", () => {
    const { resolveId } = hooks(
      createSiteImportsPlugin(IMPORT_MAP, 'https://site.test'),
    );
    const importer = resolveId('canvas_forms/useCanvasForm') as string;

    expect(resolveId('./helper.js', importer)).toContain(
      'https://site.test/modules/custom/canvas_forms/js/helper.js',
    );
  });

  it('ignores specifiers the site does not provide', () => {
    const { resolveId } = hooks(
      createSiteImportsPlugin(IMPORT_MAP, 'https://site.test'),
    );

    expect(resolveId('lodash')).toBeNull();
    expect(resolveId('./local')).toBeNull();
  });

  it('says what to do when there is no site to fetch from', () => {
    const { resolveId } = hooks(createSiteImportsPlugin(IMPORT_MAP, undefined));

    expect(() => resolveId('canvas_forms/useCanvasForm')).toThrow(
      /CANVAS_SITE_URL/,
    );
  });

  it('derives proxy prefixes from the mapped URLs', () => {
    expect(
      getSiteImportProxyPrefixes({
        imports: {
          a: '/modules/custom/a/a.js',
          b: '/themes/custom/b/b.js',
          // Somebody else's host: nothing to proxy.
          c: 'https://cdn.example.com/c.js',
        },
        scopes: { '/modules/': { d: '/libraries/d/d.js' } },
      }),
    ).toEqual(['/libraries/', '/modules/', '/themes/']);
  });
});
