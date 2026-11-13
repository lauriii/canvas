import { promises as fs } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { createGeneratedPreviewTranslationResolver } from './preview-interface-translation';
import { createRuntimeRegistryResolver } from './preview-payload';

import type { Spec } from '@json-render/core';

const HOOK = "import { useTranslation } from 'drupal-canvas/react';";

let root: string;
let componentSources: Array<{ name: string; jsEntryPath: string }>;

beforeEach(async () => {
  root = await fs.mkdtemp(path.join(os.tmpdir(), 'canvas-workbench-'));
  const write = async (name: string, source: string) => {
    const jsEntryPath = path.join(root, 'src/components', name, 'index.tsx');
    await fs.mkdir(path.dirname(jsEntryPath), { recursive: true });
    await fs.writeFile(jsEntryPath, source);
    return { name, jsEntryPath };
  };
  componentSources = [
    await write(
      'card',
      `${HOOK}\nexport default () => useTranslation().t('Card');`,
    ),
    await write('plain', 'export default () => null;'),
    await write('broken', 'export default (;'),
  ];
});

afterEach(async () => {
  await fs.rm(root, { recursive: true, force: true });
});

const spec = (type: string): Spec =>
  ({ root: 'root', elements: { root: { type } } }) as unknown as Spec;

function createDrupal(
  responses: Record<string, unknown>,
): ReturnType<typeof vi.fn> {
  return vi.fn(async (input: RequestInfo | URL) => {
    const body = responses[String(input)];
    return body === undefined
      ? new Response('', { status: 403 })
      : new Response(JSON.stringify(body), { status: 200 });
  });
}

const LANGUAGES = {
  defaultLangcode: 'en',
  languages: [
    { langcode: 'en', label: 'English', direction: 'ltr' },
    { langcode: 'fr', label: 'French', direction: 'ltr' },
  ],
};

const SITE_DATA_FR = { '/canvas/api/v0/site-data': { langcode: 'fr' } };

const input = (rendered: Spec, pageTemplateSpec: Spec | null = null) => ({
  projectRoot: root,
  registeredEntryPaths: componentSources.map(({ jsEntryPath }) => jsEntryPath),
  spec: rendered,
  pageTemplateSpec,
  componentSources,
  resolveType: createRuntimeRegistryResolver(componentSources),
});

describe('createGeneratedPreviewTranslationResolver', () => {
  it('looks up the strings of the rendered components in the negotiated language', async () => {
    const fetchImpl = createDrupal({
      '/canvas/api/v0/interface-translation': LANGUAGES,
      '/canvas/api/v0/interface-translation/fr': {
        strings: { '': { Card: 'Carte' } },
      },
    });
    // The site data is public, and Drupal refuses OAuth credentials for it.
    const fetchPublic = createDrupal(SITE_DATA_FR);
    const resolve = createGeneratedPreviewTranslationResolver({
      langcode: null,
      fetchImpl: fetchImpl as typeof fetch,
      fetchPublic: fetchPublic as typeof fetch,
    });

    expect(await resolve(input(spec('js.card')))).toEqual({
      interfaceTranslation: {
        langcode: 'fr',
        translations: { strings: { '': { Card: 'Carte' } } },
      },
      warnings: [],
    });
    expect(fetchImpl).toHaveBeenCalledWith(
      '/canvas/api/v0/interface-translation/fr',
      expect.objectContaining({
        body: JSON.stringify({ strings: [{ source: 'Card' }] }),
      }),
    );
    expect(fetchImpl).not.toHaveBeenCalledWith(
      '/canvas/api/v0/site-data',
      expect.anything(),
    );
    expect(fetchPublic).toHaveBeenCalledWith(
      '/canvas/api/v0/site-data',
      expect.anything(),
    );
    // Without a browser session, generated previews need no CSRF token.
    expect(
      [...fetchImpl.mock.calls, ...fetchPublic.mock.calls].map(([input]) =>
        String(input),
      ),
    ).not.toContain('/session/token');
    expect(fetchImpl.mock.lastCall?.[1]?.headers).not.toHaveProperty(
      'X-CSRF-Token',
    );

    // Without useTranslation(), nothing to look up.
    fetchImpl.mockClear();
    expect(await resolve(input(spec('plain')))).toEqual({ warnings: [] });
    expect(fetchImpl).not.toHaveBeenCalled();
  });

  it('warns about the components whose strings are unknown', async () => {
    const fetchImpl = createDrupal({
      '/canvas/api/v0/interface-translation': LANGUAGES,
      '/canvas/api/v0/interface-translation/fr': {
        strings: { '': { Card: 'Carte' } },
      },
    });
    const page = {
      root: 'root',
      elements: {
        root: { type: 'card', children: ['broken'] },
        broken: { type: 'broken' },
      },
    } as unknown as Spec;

    expect(
      await createGeneratedPreviewTranslationResolver({
        langcode: null,
        fetchImpl: fetchImpl as typeof fetch,
        fetchPublic: createDrupal(SITE_DATA_FR) as typeof fetch,
      })(input(page)),
    ).toEqual({
      // The known strings are translated all the same.
      interfaceTranslation: {
        langcode: 'fr',
        translations: { strings: { '': { Card: 'Carte' } } },
      },
      warnings: [
        {
          code: 'interface_translation_unknown_strings',
          message: expect.stringMatching(
            /^The strings of the component 'broken' are unknown, so they are not translated: src\/components\/broken\/index\.tsx:1:\d+: /,
          ),
        },
      ],
    });
  });

  it('follows the page only where its template renders the page content', async () => {
    const fetchImpl = createDrupal({
      '/canvas/api/v0/interface-translation': LANGUAGES,
      '/canvas/api/v0/interface-translation/en': { strings: {} },
    });
    const resolve = createGeneratedPreviewTranslationResolver({
      langcode: null,
      fetchImpl: fetchImpl as typeof fetch,
      fetchPublic: createDrupal({
        '/canvas/api/v0/site-data': { langcode: 'en' },
      }) as typeof fetch,
    });
    const template = (children: string[]) =>
      ({
        root: 'root',
        elements: {
          root: { type: 'plain', children },
          marker: { type: 'marker.page_content' },
        },
      }) as unknown as Spec;

    expect(
      (await resolve(input(spec('card'), template(['marker']))))
        .interfaceTranslation,
    ).toEqual({ langcode: 'en', translations: { strings: {} } });
    expect(await resolve(input(spec('card'), template([])))).toEqual({
      warnings: [],
    });
  });

  it('shows source strings when the language or the translations are not available', async () => {
    const languages = createDrupal({
      '/canvas/api/v0/interface-translation': LANGUAGES,
    });
    expect(
      await createGeneratedPreviewTranslationResolver({
        langcode: 'de',
        fetchImpl: languages as typeof fetch,
        fetchPublic: createDrupal({}) as typeof fetch,
      })(input(spec('card'))),
    ).toEqual({
      warnings: [
        {
          code: 'interface_translation_unavailable',
          message:
            "The language 'de' is not an interface language of the Drupal site. The preview shows source strings.",
        },
      ],
    });
    expect(
      await createGeneratedPreviewTranslationResolver({
        langcode: 'fr',
        fetchImpl: languages as typeof fetch,
        fetchPublic: createDrupal({}) as typeof fetch,
      })(input(spec('card'))),
    ).toEqual({
      interfaceTranslation: { langcode: 'fr', translations: null },
      warnings: [
        expect.objectContaining({ code: 'interface_translation_unavailable' }),
      ],
    });
    expect(
      await createGeneratedPreviewTranslationResolver({
        langcode: null,
        fetchImpl: createDrupal({}) as typeof fetch,
        fetchPublic: createDrupal({}) as typeof fetch,
      })(input(spec('card'))),
    ).toEqual({
      warnings: [
        expect.objectContaining({ code: 'interface_translation_unavailable' }),
      ],
    });
  });
});
