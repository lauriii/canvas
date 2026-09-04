import { describe, expect, it, vi } from 'vitest';

import {
  applyEntityLanguagePrefix,
  getEntityDefaultLangcode,
  getEntityLanguagePrefix,
  recordEntityDefaultLangcode,
} from '@/utils/entity-language';

vi.mock('@/utils/drupal-globals', () => ({
  getCanvasSettings: () => ({}),
  getLanguages: () => [
    { id: 'en', name: 'English', direction: 'ltr', isDefault: true },
    {
      id: 'de',
      name: 'German',
      direction: 'ltr',
      isDefault: false,
      urlPrefix: 'de',
    },
    {
      id: 'fr',
      name: 'French',
      direction: 'ltr',
      isDefault: false,
      urlPrefix: '',
    },
  ],
}));

describe('entity-language', () => {
  it('records and returns per-entity default langcodes', () => {
    recordEntityDefaultLangcode('canvas_page', '10', 'de');
    expect(getEntityDefaultLangcode('canvas_page', '10')).toBe('de');
    expect(getEntityDefaultLangcode('canvas_page', '11')).toBeUndefined();
  });

  it('returns a prefix only for non-default languages with a URL prefix', () => {
    recordEntityDefaultLangcode('canvas_page', '20', 'de');
    recordEntityDefaultLangcode('canvas_page', '21', 'en');
    recordEntityDefaultLangcode('canvas_page', '22', 'fr');
    expect(getEntityLanguagePrefix('canvas_page', '20')).toBe('de');
    // The site default language never needs a prefix.
    expect(getEntityLanguagePrefix('canvas_page', '21')).toBe('');
    // A language without a configured URL prefix cannot be prefixed.
    expect(getEntityLanguagePrefix('canvas_page', '22')).toBe('');
    // Unknown entities pass through.
    expect(getEntityLanguagePrefix('canvas_page', '99')).toBe('');
  });

  it('prefixes entity-scoped editor API URLs', () => {
    recordEntityDefaultLangcode('canvas_page', '30', 'de');
    expect(
      applyEntityLanguagePrefix('canvas/api/v0/layout/canvas_page/30'),
    ).toBe('de/canvas/api/v0/layout/canvas_page/30');
    expect(
      applyEntityLanguagePrefix('/canvas/api/v0/layout/canvas_page/30'),
    ).toBe('/de/canvas/api/v0/layout/canvas_page/30');
    expect(
      applyEntityLanguagePrefix('/canvas/api/v0/content/canvas_page/30'),
    ).toBe('/de/canvas/api/v0/content/canvas_page/30');
    expect(
      applyEntityLanguagePrefix(
        '/canvas/api/v0/content/auto-save/canvas_page/30',
      ),
    ).toBe('/de/canvas/api/v0/content/auto-save/canvas_page/30');
    expect(
      applyEntityLanguagePrefix(
        '/canvas/api/v0/form/content-entity/canvas_page/30/default',
      ),
    ).toBe('/de/canvas/api/v0/form/content-entity/canvas_page/30/default');
    expect(
      applyEntityLanguagePrefix(
        '/canvas/api/v0/form/component-instance/canvas_page/30',
      ),
    ).toBe('/de/canvas/api/v0/form/component-instance/canvas_page/30');
  });

  it('keeps query strings intact', () => {
    recordEntityDefaultLangcode('canvas_page', '40', 'de');
    expect(
      applyEntityLanguagePrefix(
        'canvas/api/v0/layout/canvas_page/40?canvas_preview_langcode=fr',
      ),
    ).toBe('de/canvas/api/v0/layout/canvas_page/40?canvas_preview_langcode=fr');
  });

  it('leaves non-matching and non-registered URLs unchanged', () => {
    recordEntityDefaultLangcode('canvas_page', '50', 'de');
    // Entity whose language is unknown.
    expect(
      applyEntityLanguagePrefix('canvas/api/v0/layout/canvas_page/51'),
    ).toBe('canvas/api/v0/layout/canvas_page/51');
    // Non-entity-scoped endpoints.
    expect(applyEntityLanguagePrefix('canvas/api/v0/config/component')).toBe(
      'canvas/api/v0/config/component',
    );
    expect(applyEntityLanguagePrefix('/canvas/api/v0/auto-saves/pending')).toBe(
      '/canvas/api/v0/auto-saves/pending',
    );
    // The entity-create POST URL has no entity ID.
    expect(
      applyEntityLanguagePrefix('/canvas/api/v0/content/canvas_page'),
    ).toBe('/canvas/api/v0/content/canvas_page');
    // Server-generated links (with more path segments) are self-contained.
    expect(
      applyEntityLanguagePrefix(
        '/canvas/api/v0/content/canvas_page/50/translations?language=fr',
      ),
    ).toBe('/canvas/api/v0/content/canvas_page/50/translations?language=fr');
    // Template layouts are config-entity-scoped: no translation upcast.
    expect(
      applyEntityLanguagePrefix(
        'canvas/api/v0/layout-content-template/node.article.full/50',
      ),
    ).toBe('canvas/api/v0/layout-content-template/node.article.full/50');
  });
});
