// cspell:ignore janvier
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';

import { CanvasContextProvider } from './context';
import { useDateFormatter } from './date-formatter';

import type { CanvasContext } from './context';
import type { CanvasDateFormatOptions } from './date-utils';

const site = (langcode?: string): NonNullable<CanvasContext['site']> => ({
  branding: { homeUrl: '/', siteName: 'Test', siteSlogan: '' },
  baseUrl: '/',
  themeAssets: { logo: { url: '' }, favicon: { url: '', mimeType: '' } },
  ...(langcode !== undefined && { langcode }),
});

function render(
  value: string | null,
  options?: CanvasDateFormatOptions,
  context?: CanvasContext,
): string {
  function Probe() {
    const formatDate = useDateFormatter();
    return <span>{formatDate(value, options)}</span>;
  }
  const tree = <Probe />;
  return renderToStaticMarkup(
    context ? (
      <CanvasContextProvider context={context}>{tree}</CanvasContextProvider>
    ) : (
      tree
    ),
  );
}

describe('useDateFormatter', () => {
  it('formats in the language of the site context', () => {
    expect(
      render('2026-01-15', undefined, { page: null, site: site('en') }),
    ).toBe('<span>1/15/26</span>');
    expect(
      render('2026-01-15', undefined, { page: null, site: site('fr') }),
    ).toBe('<span>15/01/2026</span>');
  });

  it('passes options through', () => {
    expect(
      render(
        '2026-01-15',
        { dateStyle: 'long' },
        { page: null, site: site('fr') },
      ),
    ).toBe('<span>15 janvier 2026</span>');
  });

  it('formats in English when the site context has no langcode', () => {
    expect(render('2026-01-15', undefined, { page: null, site: site() })).toBe(
      '<span>1/15/26</span>',
    );
  });

  it('formats in English when the integration supplies no site context', () => {
    expect(render('2026-01-15', undefined, { page: null, site: null })).toBe(
      '<span>1/15/26</span>',
    );
  });

  it('formats in English without a provider', () => {
    expect(render('2026-01-15')).toBe('<span>1/15/26</span>');
  });

  it('returns an empty string for an empty value', () => {
    expect(render(null, undefined, { page: null, site: site('fr') })).toBe(
      '<span></span>',
    );
  });
});
