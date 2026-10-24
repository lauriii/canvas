import { describe, expect, it } from 'vitest';

import {
  addEditorLanguageToUrl,
  extractEntityParams,
  getEditorLanguage,
  getRequestTranslation,
  replaceEntityParamsInUrl,
} from '@/services/baseQuery';

describe('extractEntityParams', () => {
  it('removes query parameters and hash fragments', () => {
    const url = '/canvas/editor/node/1234/?foo=bar#section';
    const result = extractEntityParams(url);
    expect(result).toEqual({ entityType: 'node', entityId: '1234' });
  });

  it('removes hash fragments', () => {
    const url = '/canvas/editor/node/1234#section';
    const result = extractEntityParams(url);
    expect(result).toEqual({ entityType: 'node', entityId: '1234' });
  });

  it('works with template editor URLs', () => {
    const url = '/canvas/template/article/bundle/view/5678?x=1#y';
    const result = extractEntityParams(url);
    expect(result).toEqual({
      entityType: 'article',
      templateBundle: 'bundle',
      templateViewMode: 'view',
      entityId: '5678',
    });
  });

  it('returns undefined for non-matching URLs', () => {
    const url = '/not/a/matching/url?foo=bar#baz';
    const result = extractEntityParams(url);
    expect(result).toEqual({ entityType: undefined, entityId: undefined });
  });
});

describe('replaceEntityParamsInUrl', () => {
  it('replaces {entity_type} and {entity_id} in the URL', () => {
    const url = '/api/{entity_type}/{entity_id}';
    const result = replaceEntityParamsInUrl(url, 'node', '123');
    expect(result).toBe('/api/node/123');
  });

  it('replaces all template params in the URL', () => {
    const url =
      '/api/{entity_type}/{template_bundle}/{template_view_mode}/{entity_id}';
    const result = replaceEntityParamsInUrl(
      url,
      'article',
      '456',
      'bundle',
      'view',
    );
    expect(result).toBe('/api/article/bundle/view/456');
  });

  it('throws an error if a required param is missing', () => {
    const url = '/api/{entity_type}/{entity_id}';
    expect(() => replaceEntityParamsInUrl(url, undefined, '123')).toThrow();
    expect(() => replaceEntityParamsInUrl(url, 'node', undefined)).toThrow();
  });

  it('returns the URL unchanged if no params are present', () => {
    const url = '/api/static/path';
    const result = replaceEntityParamsInUrl(url);
    expect(result).toBe('/api/static/path');
  });
});

describe('getEditorLanguage', () => {
  it('reads the language of the entity editor route', () => {
    expect(getEditorLanguage('/canvas/editor/canvas_page/1?language=fr')).toBe(
      'fr',
    );
    expect(
      getEditorLanguage(
        '/canvas/editor/canvas_page/1/component/abc?language=fr',
      ),
    ).toBe('fr');
  });

  it('ignores the default translation and the preview route', () => {
    expect(getEditorLanguage('/canvas/editor/canvas_page/1')).toBe('');
    expect(
      getEditorLanguage('/canvas/preview/canvas_page/1/full?language=fr'),
    ).toBe('');
  });
});

describe('addEditorLanguageToUrl', () => {
  it('names the translation on editor API requests for its entity', () => {
    expect(
      addEditorLanguageToUrl(
        'canvas/api/v0/layout/canvas_page/1',
        'fr',
        'canvas_page',
        '1',
      ),
    ).toBe('canvas/api/v0/layout/canvas_page/1?canvas_langcode=fr');
    expect(
      addEditorLanguageToUrl(
        '/canvas/api/v0/form/content-entity/canvas_page/1/default?a=b',
        'fr',
        'canvas_page',
        '1',
      ),
    ).toBe(
      '/canvas/api/v0/form/content-entity/canvas_page/1/default?a=b&canvas_langcode=fr',
    );
  });

  it('leaves other requests unchanged', () => {
    // Another entity.
    expect(
      addEditorLanguageToUrl(
        'canvas/api/v0/layout/canvas_page/12',
        'fr',
        'canvas_page',
        '1',
      ),
    ).toBe('canvas/api/v0/layout/canvas_page/12');
    // Not an entity-scoped editor endpoint.
    expect(
      addEditorLanguageToUrl(
        '/canvas/api/v0/auto-saves/pending',
        'fr',
        'canvas_page',
        '1',
      ),
    ).toBe('/canvas/api/v0/auto-saves/pending');
    // A translation preview, which names its language itself.
    expect(
      addEditorLanguageToUrl(
        'canvas/api/v0/layout/canvas_page/1?canvas_preview_langcode=fr',
        'fr',
        'canvas_page',
        '1',
      ),
    ).toBe('canvas/api/v0/layout/canvas_page/1?canvas_preview_langcode=fr');
    // A request that names its translation itself.
    expect(
      addEditorLanguageToUrl(
        'canvas/api/v0/form/content-entity/canvas_page/1/default?canvas_langcode=de',
        'fr',
        'canvas_page',
        '1',
      ),
    ).toBe(
      'canvas/api/v0/form/content-entity/canvas_page/1/default?canvas_langcode=de',
    );
    // A content auto-save for a listed pending change.
    expect(
      addEditorLanguageToUrl(
        '/canvas/api/v0/content/auto-save/canvas_page/1',
        'fr',
        'canvas_page',
        '1',
      ),
    ).toBe('/canvas/api/v0/content/auto-save/canvas_page/1');
    // The default translation.
    expect(
      addEditorLanguageToUrl(
        'canvas/api/v0/layout/canvas_page/1',
        '',
        'canvas_page',
        '1',
      ),
    ).toBe('canvas/api/v0/layout/canvas_page/1');
  });
});

describe('getRequestTranslation', () => {
  const api = (type: 'query' | 'mutation', pageDataOwner: unknown) =>
    ({ type, getState: () => ({ pageDataOwner }) }) as unknown as Parameters<
      typeof getRequestTranslation
    >[0];
  const frOwner = { entityType: 'canvas_page', entityId: '1', langcode: 'fr' };

  it('follows the loaded data for writes and the route for reads', () => {
    window.history.pushState({}, '', '/canvas/editor/canvas_page/1');
    expect(getRequestTranslation(api('mutation', frOwner))).toEqual({
      language: 'fr',
      entityType: 'canvas_page',
      entityId: '1',
    });
    expect(getRequestTranslation(api('query', frOwner)).language).toBe('');
  });

  it('follows the route for writes without loaded data', () => {
    window.history.pushState(
      {},
      '',
      '/canvas/editor/canvas_page/1?language=fr',
    );
    expect(getRequestTranslation(api('mutation', null))).toEqual({
      language: 'fr',
      entityType: 'canvas_page',
      entityId: '1',
    });
  });
});
