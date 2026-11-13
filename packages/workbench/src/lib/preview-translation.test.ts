import { afterEach, describe, expect, it, vi } from 'vitest';

import { fetchCsrfToken } from './preview-client';
import {
  aggregatePreviewTranslation,
  collectRenderedComponents,
  collectRequestComponents,
  createRegistryResolver,
  createTranslationLookup,
  getInitialLangcode,
  getPreviewLangcodeStorageKey,
  loadPreviewLanguages,
  readPreviewLangcode,
  sendTranslatedRenderRequest,
  storePreviewLangcode,
} from './preview-translation';

import type { Spec } from '@json-render/core';
import type {
  PreviewManifest,
  PreviewManifestComponent,
  PreviewRenderRequest,
} from './preview-contract';

const spec = (root: string, elements: Record<string, unknown>): Spec =>
  ({ root, elements }) as unknown as Spec;

const registrySources = ['card', 'badge', 'heading', 'Button', 'js.icon'].map(
  (name) => ({ name, jsEntryUrl: `/@fs/${name}.tsx` }),
);

describe('collectRenderedComponents', () => {
  it('collects the components that renderSpec() renders', () => {
    const resolve = createRegistryResolver(registrySources);
    expect(
      collectRenderedComponents(
        spec('canvas:component-tree', {
          'canvas:component-tree': {
            type: 'canvas:component-tree',
            children: ['card', 'unregistered', 'missing'],
          },
          card: {
            type: 'js.card',
            children: ['heading'],
            slots: { footer: ['button'] },
          },
          heading: { type: 'heading', children: ['card'] },
          button: { type: 'js.button' },
          // Renders nothing, nor its children.
          unregistered: { type: 'js.unknown', children: ['badge'] },
          badge: { type: 'badge' },
        }),
        resolve,
      ),
    ).toEqual(new Set(['card', 'heading', 'Button']));
  });

  it('renders only the children of the synthetic component tree', () => {
    expect(
      collectRenderedComponents(
        spec('canvas:component-tree', {
          'canvas:component-tree': {
            type: 'canvas:component-tree',
            children: ['card'],
            slots: { ignored: ['badge'] },
          },
          card: { type: 'card' },
          badge: { type: 'badge' },
        }),
        createRegistryResolver(registrySources),
      ),
    ).toEqual(new Set(['card']));
  });

  it('resolves the names the preview registry registers', () => {
    const resolve = createRegistryResolver(registrySources);
    expect(resolve('card')).toBe('card');
    expect(resolve('js.card')).toBe('card');
    expect(resolve('Button')).toBe('Button');
    expect(resolve('js.button')).toBe('Button');
    expect(resolve('js.icon')).toBe('js.icon');
    expect(resolve('icon')).toBeNull();
    // A later component replaces an earlier one with the same name.
    expect(
      createRegistryResolver([
        { name: 'Card', jsEntryUrl: '/@fs/a.tsx' },
        { name: 'js.card', jsEntryUrl: '/@fs/b.tsx' },
      ])('js.card'),
    ).toBe('js.card');
  });
});

describe('collectRequestComponents', () => {
  const page = spec('root', {
    root: { type: 'card', children: [] },
  });
  const template = (children: string[]) =>
    spec('root', {
      root: { type: 'heading', children },
      marker: { type: 'marker.page_content' },
    });

  it('includes the page where its template renders the page content', () => {
    expect(
      collectRequestComponents({
        renderId: 'page',
        renderType: 'page',
        spec: page,
        pageTemplate: { spec: template(['marker']) },
        registrySources,
        cssUrls: [],
      }),
    ).toEqual(new Set(['heading', 'card']));
    expect(
      collectRequestComponents({
        renderId: 'page',
        renderType: 'page',
        spec: page,
        pageTemplate: { spec: template([]) },
        registrySources,
        cssUrls: [],
      }),
    ).toEqual(new Set(['heading']));
  });

  it('renders no page in page template previews', () => {
    expect(
      collectRequestComponents({
        renderId: 'template',
        renderType: 'page-template',
        spec: template(['marker']),
        registrySources,
        cssUrls: [],
      }),
    ).toEqual(new Set(['heading']));
  });
});

describe('aggregatePreviewTranslation', () => {
  const component = (
    name: string,
    translation?: PreviewManifestComponent['translation'],
  ) => ({ name, translation }) as PreviewManifestComponent;
  const manifest = {
    components: [
      component('card', {
        usesTranslationHook: true,
        translatableStrings: [{ source: 'Card' }, { source: 'Shared' }],
      }),
      component('badge', {
        usesTranslationHook: true,
        translatableStrings: [{ source: 'Shared' }],
      }),
      component('plain', {
        usesTranslationHook: false,
        translatableStrings: [],
      }),
      component('broken', {
        usesTranslationHook: null,
        translatableStrings: null,
      }),
      component('withoutMetadata'),
    ],
  } as PreviewManifest;

  it('aggregates the rendered components only', () => {
    expect(aggregatePreviewTranslation(['card', 'badge'], manifest)).toEqual({
      usesTranslationHook: true,
      translatableStrings: [{ source: 'Card' }, { source: 'Shared' }],
      unknownComponents: [],
    });
    expect(aggregatePreviewTranslation(['plain'], manifest)).toEqual({
      usesTranslationHook: false,
      translatableStrings: [],
      unknownComponents: [],
    });
    expect(aggregatePreviewTranslation([], manifest)).toEqual({
      usesTranslationHook: false,
      translatableStrings: [],
      unknownComponents: [],
    });
  });

  it('is unknown, not false, when a component is unknown', () => {
    for (const unknown of ['broken', 'withoutMetadata', 'missing']) {
      expect(
        aggregatePreviewTranslation(['plain', unknown], manifest)
          .usesTranslationHook,
      ).toBeNull();
    }
    expect(
      aggregatePreviewTranslation(['broken', 'card'], manifest)
        .usesTranslationHook,
    ).toBe(true);
  });
});

describe('getInitialLangcode', () => {
  const languages = {
    defaultLangcode: 'en',
    languages: [
      { langcode: 'en', label: 'English', direction: 'ltr' as const },
      { langcode: 'fr', label: 'French', direction: 'ltr' as const },
    ],
  };

  it('prefers the negotiated language to the default one', () => {
    expect(getInitialLangcode(languages, 'fr')).toBe('fr');
    expect(getInitialLangcode(languages, 'de')).toBe('en');
    expect(getInitialLangcode(languages, undefined)).toBe('en');
  });

  it('prefers the language selected earlier while the site translates into it', () => {
    expect(getInitialLangcode(languages, 'en', 'fr')).toBe('fr');
    // A language that the site removed.
    expect(getInitialLangcode(languages, 'fr', 'de')).toBe('fr');
    expect(getInitialLangcode(languages, undefined, 'de')).toBe('en');
  });
});

class MemoryStorage implements Storage {
  private items = new Map<string, string>();
  get length() {
    return this.items.size;
  }
  clear() {
    this.items.clear();
  }
  getItem(key: string) {
    return this.items.get(key) ?? null;
  }
  key(index: number) {
    return [...this.items.keys()][index] ?? null;
  }
  removeItem(key: string) {
    this.items.delete(key);
  }
  setItem(key: string, value: string) {
    this.items.set(key, value);
  }
}

describe('the language selected for a Drupal site', () => {
  const SITE = 'https://drupal.example/';

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('has one key per site, without credentials, query or fragment', () => {
    const key = getPreviewLangcodeStorageKey(SITE);
    expect(key).toBe(
      'canvas-workbench:preview-langcode:https://drupal.example',
    );
    for (const sameSite of [
      'https://DRUPAL.example',
      'https://drupal.example:443/',
      'https://editor:hunter2@drupal.example/?a=b#c',
    ]) {
      expect(getPreviewLangcodeStorageKey(sameSite)).toBe(key);
    }
    expect(
      getPreviewLangcodeStorageKey('https://editor:hunter2@drupal.example/'),
    ).not.toContain('hunter2');
    for (const otherSite of [
      'http://drupal.example',
      'https://drupal.example:8443',
      'https://drupal.example/fr',
      'https://other.example',
    ]) {
      expect(getPreviewLangcodeStorageKey(otherSite)).not.toBe(key);
    }
    expect(getPreviewLangcodeStorageKey('not a URL')).toBeNull();
  });

  it('is stored for each site separately', () => {
    const storage = new MemoryStorage();
    storePreviewLangcode(SITE, 'fr', storage);
    storePreviewLangcode('https://other.example', 'de', storage);

    expect(readPreviewLangcode('https://drupal.example', storage)).toBe('fr');
    expect(readPreviewLangcode('https://other.example/', storage)).toBe('de');
    expect(readPreviewLangcode('https://third.example', storage)).toBeNull();
  });

  it('lasts until the page reloads without storage', () => {
    const throwing = new MemoryStorage();
    throwing.getItem = () => {
      throw new DOMException('Denied', 'SecurityError');
    };
    throwing.setItem = () => {
      throw new DOMException('Full', 'QuotaExceededError');
    };
    for (const storage of [throwing, null]) {
      expect(() => storePreviewLangcode(SITE, 'fr', storage)).not.toThrow();
      expect(readPreviewLangcode(SITE, storage)).toBeNull();
    }

    // When the browser denies access to the storage, or on the server.
    vi.stubGlobal('window', {
      get sessionStorage(): Storage {
        throw new DOMException('Denied', 'SecurityError');
      },
    });
    expect(() => storePreviewLangcode(SITE, 'fr')).not.toThrow();
    expect(readPreviewLangcode(SITE)).toBeNull();
    vi.stubGlobal('window', undefined);
    expect(() => storePreviewLangcode(SITE, 'fr')).not.toThrow();
    expect(readPreviewLangcode(SITE)).toBeNull();
  });
});

describe('loadPreviewLanguages', () => {
  const SITE = 'https://drupal.example';
  const languages = {
    defaultLangcode: 'en',
    languages: [
      { langcode: 'en', label: 'English', direction: 'ltr' },
      { langcode: 'fr', label: 'French', direction: 'ltr' },
    ],
  };
  const fetchLanguages = (body: unknown) =>
    vi.fn(
      async () => new Response(JSON.stringify(body), { status: 200 }),
    ) as unknown as typeof fetch;

  it('restores the language selected before the page reloaded', async () => {
    const storage = new MemoryStorage();
    const load = () =>
      loadPreviewLanguages({
        siteUrl: SITE,
        negotiatedLangcode: 'en',
        isCurrent: () => true,
        fetchImpl: fetchLanguages(languages),
        storage,
      });

    expect(await load()).toEqual({ languages, langcode: 'en' });
    storePreviewLangcode(SITE, 'fr', storage);
    expect(await load()).toEqual({ languages, langcode: 'fr' });
  });

  it('falls back when the site no longer translates into the selected language', async () => {
    const storage = new MemoryStorage();
    storePreviewLangcode(SITE, 'fr', storage);
    const english = {
      defaultLangcode: 'en',
      languages: [languages.languages[0]],
    };

    expect(
      await loadPreviewLanguages({
        siteUrl: SITE,
        negotiatedLangcode: 'en',
        isCurrent: () => true,
        fetchImpl: fetchLanguages(english),
        storage,
      }),
    ).toEqual({ languages: english, langcode: 'en' });
  });

  it('ignores the languages of a site that was replaced meanwhile', async () => {
    const storage = new MemoryStorage();
    storePreviewLangcode(SITE, 'fr', storage);
    const getItem = vi.spyOn(storage, 'getItem');

    expect(
      await loadPreviewLanguages({
        siteUrl: SITE,
        negotiatedLangcode: 'en',
        isCurrent: () => false,
        fetchImpl: fetchLanguages(languages),
        storage,
      }),
    ).toBeNull();
    expect(getItem).not.toHaveBeenCalled();
    expect(readPreviewLangcode(SITE, storage)).toBe('fr');
  });
});

describe('createTranslationLookup', () => {
  const strings = [{ source: 'Card' }];
  const translated = () =>
    new Response(JSON.stringify({ strings: { '': { Card: 'Carte' } } }), {
      status: 200,
    });

  it('sends the CSRF token of the browser session with its cookies', async () => {
    const fetchImpl = vi.fn(
      async (_input: RequestInfo | URL, _init?: RequestInit) => translated(),
    );
    const getCsrfToken = vi.fn(async () => 'token');
    const lookup = createTranslationLookup(fetchImpl as typeof fetch, {
      getCsrfToken,
    });

    expect(await lookup('fr', strings, 1)).toEqual({
      translations: { strings: { '': { Card: 'Carte' } } },
    });
    const init = fetchImpl.mock.lastCall?.[1];
    expect(init?.credentials).toBe('include');
    expect(init?.headers).toMatchObject({ 'X-CSRF-Token': 'token' });

    // Neither cached lookups nor lookups without strings get a token.
    await lookup('fr', strings, 1);
    await lookup('fr', [], 1);
    expect(getCsrfToken).toHaveBeenCalledOnce();
    expect(fetchImpl).toHaveBeenCalledOnce();
  });

  it('reports why a lookup without a token fails', async () => {
    const fetchImpl = vi.fn(
      async (_input: RequestInfo | URL, _init?: RequestInit) =>
        new Response('', { status: 403 }),
    );
    const lookup = createTranslationLookup(fetchImpl as typeof fetch, {
      getCsrfToken: async () => null,
    });

    expect(await lookup('fr', strings, 1)).toEqual({
      error:
        "Failed to fetch the translations into 'fr' from Drupal: 403 (check the credentials of Workbench).",
    });
    expect(fetchImpl.mock.lastCall?.[1]?.headers).not.toHaveProperty(
      'X-CSRF-Token',
    );
  });

  it('gets the token from the session, as Workbench does', async () => {
    const fetchMock = vi.fn(
      async (input: RequestInfo | URL, _init?: RequestInit) =>
        String(input) === '/session/token'
          ? new Response('session-token', { status: 200 })
          : translated(),
    );
    vi.stubGlobal('fetch', fetchMock);
    try {
      const lookup = createTranslationLookup(fetch, {
        getCsrfToken: () => fetchCsrfToken(),
      });
      await lookup('fr', strings, 1);
    } finally {
      vi.unstubAllGlobals();
    }

    expect(
      fetchMock.mock.calls.map(([input, init]) => [
        String(input),
        init?.credentials,
        (init?.headers as Record<string, string> | undefined)?.['X-CSRF-Token'],
      ]),
    ).toEqual([
      ['/session/token', 'include', undefined],
      ['/canvas/api/v0/interface-translation/fr', 'include', 'session-token'],
    ]);
  });

  it('looks up the translations once per language, strings and revision', async () => {
    const fetchImpl = vi.fn(
      async () =>
        new Response(JSON.stringify({ strings: { '': { Card: 'Carte' } } }), {
          status: 200,
        }),
    );
    const lookup = createTranslationLookup(fetchImpl as typeof fetch);
    const strings = [{ source: 'Card' }];

    expect(await lookup('fr', strings, 1)).toEqual({
      translations: { strings: { '': { Card: 'Carte' } } },
    });
    await lookup('fr', strings, 1);
    expect(fetchImpl).toHaveBeenCalledTimes(1);
    expect(fetchImpl).toHaveBeenCalledWith(
      '/canvas/api/v0/interface-translation/fr',
      expect.objectContaining({
        method: 'POST',
        body: JSON.stringify({ strings }),
      }),
    );
    await lookup('fr', strings, 2);
    await lookup('de', strings, 2);
    expect(fetchImpl).toHaveBeenCalledTimes(3);
    // Without strings, nothing to look up.
    expect(await lookup('fr', [], 2)).toEqual({
      translations: { strings: {} },
    });
    expect(fetchImpl).toHaveBeenCalledTimes(3);
  });

  it('looks up failed translations again', async () => {
    const fetchImpl = vi
      .fn()
      .mockResolvedValueOnce(new Response('', { status: 403 }))
      .mockResolvedValueOnce(
        new Response(JSON.stringify({ strings: {} }), { status: 200 }),
      );
    const lookup = createTranslationLookup(fetchImpl as typeof fetch);

    expect(await lookup('fr', [{ source: 'Card' }], 1)).toEqual({
      error:
        "Failed to fetch the translations into 'fr' from Drupal: 403 (check the credentials of Workbench).",
    });
    expect(await lookup('fr', [{ source: 'Card' }], 1)).toEqual({
      translations: { strings: {} },
    });
  });
});

describe('sendTranslatedRenderRequest', () => {
  const manifest = {
    components: [
      {
        name: 'card',
        translation: {
          usesTranslationHook: true,
          translatableStrings: [{ source: 'Card' }],
        },
      },
      {
        name: 'plain',
        translation: { usesTranslationHook: false, translatableStrings: [] },
      },
    ],
    translationRevision: 1,
  } as PreviewManifest;
  const message = (renderId: string, type = 'card'): PreviewRenderRequest => ({
    source: 'canvas-workbench-parent',
    type: 'preview:render',
    payload: {
      renderId,
      renderType: 'component',
      spec: spec('root', { root: { type } }),
      registrySources,
      cssUrls: [],
    },
  });

  it('sends only the latest render after a slower lookup', async () => {
    let releaseFrench = () => {};
    const lookup = vi.fn((langcode: string) =>
      langcode === 'fr'
        ? new Promise<{ translations: { strings: Record<string, never> } }>(
            (resolve) => {
              releaseFrench = () => resolve({ translations: { strings: {} } });
            },
          )
        : Promise.resolve({ translations: { strings: {} } }),
    );
    let current = 0;
    const posted: PreviewRenderRequest[] = [];
    const send = (renderId: string, langcode: string, type?: string) => {
      const generation = ++current;
      return sendTranslatedRenderRequest({
        message: message(renderId, type),
        manifest,
        langcode,
        lookup,
        isCurrent: () => generation === current,
        post: (sent) => posted.push(sent),
        onTranslation: () => {},
      });
    };

    // French is selected, then English before the French lookup returns.
    const french = send('card', 'fr');
    await send('card', 'en');
    releaseFrench();
    await french;
    expect(
      posted.map(({ payload }) => payload.interfaceTranslation?.langcode),
    ).toEqual(['en']);

    // Another mock or target replaces a pending one.
    posted.length = 0;
    const pending = send('card:mock', 'fr');
    await send('plain', 'fr', 'plain');
    releaseFrench();
    await pending;
    expect(posted.map(({ payload }) => payload.renderId)).toEqual(['plain']);
    // Without useTranslation(), no lookup and no translation.
    expect(posted[0]?.payload.interfaceTranslation).toBeUndefined();
  });

  it('sends nothing for a replaced render, whether or not it looks up translations', async () => {
    const posted: PreviewRenderRequest[] = [];
    const reported: unknown[] = [];
    const lookup = vi.fn();
    for (const [type, langcode] of [
      // No language to translate into.
      ['card', null],
      // Nothing to translate.
      ['plain', 'fr'],
      ['card', 'fr'],
    ] as const) {
      await sendTranslatedRenderRequest({
        message: message('stale', type),
        manifest,
        langcode,
        lookup,
        isCurrent: () => false,
        post: (sent) => posted.push(sent),
        onTranslation: (translation) => reported.push(translation),
      });
    }
    expect(posted).toEqual([]);
    expect(reported).toEqual([]);
    expect(lookup).not.toHaveBeenCalled();
  });

  it('reports why the translations are not available', async () => {
    const reported: Array<string | null> = [];
    const posted: PreviewRenderRequest[] = [];
    await sendTranslatedRenderRequest({
      message: message('card'),
      manifest,
      langcode: 'fr',
      lookup: async () => ({ error: 'Failed.' }),
      isCurrent: () => true,
      post: (sent) => posted.push(sent),
      onTranslation: (_translation, error) => reported.push(error),
    });
    expect(reported).toEqual(['Failed.']);
    expect(posted[0]?.payload.interfaceTranslation).toEqual({
      langcode: 'fr',
      translations: null,
    });
  });
});
