// @vitest-environment jsdom
// cspell:ignore dzień jours plik pliki plików

import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { renderToStaticMarkup } from 'react-dom/server';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
  CanvasContextProvider,
  useHasCanvasContext,
  usePageContext,
  useSiteContext,
  useTranslation,
} from './context';
import { resetCanvasDataReports } from './data-inspection';
import { resetWarnings } from './warnings';

import type { CanvasContext } from './context';

const context: CanvasContext = {
  page: {
    pageTitle: 'About us',
    breadcrumbs: [{ key: '<front>', text: 'Home', url: '/' }],
    mainEntity: null,
  },
  site: {
    branding: { homeUrl: '/', siteName: 'Canvas', siteSlogan: '' },
    baseUrl: 'https://drupal.example',
    themeAssets: { logo: { url: '' }, favicon: { url: '', mimeType: '' } },
  },
};

function Header() {
  const page = usePageContext();
  const site = useSiteContext();
  return (
    <header>
      {site?.branding.siteName ?? 'no-site'}:{page?.pageTitle ?? 'no-page'}
    </header>
  );
}

describe('page and site context', () => {
  beforeEach(() => {
    resetWarnings();
    resetCanvasDataReports();
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('returns the provided values without warnings', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    const html = renderToStaticMarkup(
      <CanvasContextProvider context={context}>
        <Header />
      </CanvasContextProvider>,
    );
    expect(html).toBe('<header>Canvas:About us</header>');
    expect(warn).not.toHaveBeenCalled();
  });

  it('treats valid empty values as available context', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    const empty: CanvasContext = {
      page: { pageTitle: '', breadcrumbs: [], mainEntity: null },
      site: {
        ...context.site!,
        themeAssets: { logo: { url: '' }, favicon: { url: '', mimeType: '' } },
      },
    };
    const html = renderToStaticMarkup(
      <CanvasContextProvider context={empty}>
        <Header />
      </CanvasContextProvider>,
    );
    expect(html).toBe('<header>Canvas:</header>');
    expect(warn).not.toHaveBeenCalled();
  });

  it('returns null and warns once without a provider', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    const html = renderToStaticMarkup(
      <>
        <Header />
        <Header />
      </>,
    );
    expect(html).toBe(
      '<header>no-site:no-page</header><header>no-site:no-page</header>',
    );
    expect(warn).toHaveBeenCalledTimes(2);
    expect(warn.mock.calls[0][0]).toContain('usePageContext()');
    expect(warn.mock.calls[0][0]).toContain('CanvasContextProvider');
    expect(warn.mock.calls[1][0]).toContain('useSiteContext()');
  });

  it('returns null and warns for an explicit null value', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    const html = renderToStaticMarkup(
      <CanvasContextProvider context={{ page: null, site: context.site }}>
        <Header />
      </CanvasContextProvider>,
    );
    expect(html).toBe('<header>Canvas:no-page</header>');
    expect(warn).toHaveBeenCalledTimes(1);
    expect(warn.mock.calls[0][0]).toContain('`context.page` is null');
  });

  it('reports hook data to the embedding document once per value', async () => {
    const postMessage = vi.fn();
    vi.spyOn(window, 'parent', 'get').mockReturnValue({
      postMessage,
    } as unknown as Window);
    const container = document.createElement('div');
    const root = createRoot(container);
    await act(async () => {
      root.render(
        <CanvasContextProvider context={context}>
          <Header />
        </CanvasContextProvider>,
      );
    });
    await act(async () => {
      root.render(
        <CanvasContextProvider context={{ ...context }}>
          <Header />
        </CanvasContextProvider>,
      );
    });
    const reports = postMessage.mock.calls.map(([message]) => message);
    expect(reports).toEqual([
      {
        type: '_canvas_useswr_data_fetch',
        id: 'usePageContext()',
        data: context.page,
      },
      {
        type: '_canvas_useswr_data_fetch',
        id: 'useSiteContext()',
        data: context.site,
      },
    ]);
    await act(async () => {
      root.unmount();
    });
  });

  it('exposes whether a provider is mounted', () => {
    function Probe() {
      return <>{String(useHasCanvasContext())}</>;
    }
    expect(renderToStaticMarkup(<Probe />)).toBe('false');
    expect(
      renderToStaticMarkup(
        <CanvasContextProvider context={context}>
          <Probe />
        </CanvasContextProvider>,
      ),
    ).toBe('true');
  });

  it('translates strings like Drupal.t()', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    function Greeting() {
      const { t } = useTranslation();
      return (
        <p>
          {t('Hello @name', { '@name': '<b>@names</b>' })}|
          {t('@count of @counts', { '@count': 1, '@counts': 2 })}|
          {t('May', {}, { context: 'Long month name' })}|{t('Untranslated')}
        </p>
      );
    }
    const translated: CanvasContext = {
      ...context,
      translations: {
        strings: {
          '': { 'Hello @name': 'Bonjour @name', '@count of @counts': '' },
          'Long month name': { May: 'mai' },
        },
      },
    };
    expect(
      renderToStaticMarkup(
        <CanvasContextProvider context={translated}>
          <Greeting />
        </CanvasContextProvider>,
      ),
    ).toBe('<p>Bonjour &lt;b&gt;@names&lt;/b&gt;|1 of 2|mai|Untranslated</p>');
    // No translations is valid context: source strings, no warning.
    expect(
      renderToStaticMarkup(
        <CanvasContextProvider context={context}>
          <Greeting />
        </CanvasContextProvider>,
      ),
    ).toBe('<p>Hello &lt;b&gt;@names&lt;/b&gt;|1 of 2|May|Untranslated</p>');
    expect(warn).not.toHaveBeenCalled();
    renderToStaticMarkup(<Greeting />);
    expect(warn).toHaveBeenCalledTimes(1);
    expect(warn.mock.calls[0][0]).toContain('useTranslation()');
  });

  it('formats plurals like Drupal.formatPlural()', () => {
    function Items({ count }: { count: number }) {
      const { formatPlural } = useTranslation();
      return (
        <>
          {formatPlural(count, '1 file', '@count files')}|
          {formatPlural(count, '1 day', '@count days', {}, { context: 'Age' })}|
          {formatPlural(count, '1 item', '@count items')};
        </>
      );
    }
    const render = (translations: CanvasContext['translations']) =>
      renderToStaticMarkup(
        <CanvasContextProvider context={{ ...context, translations }}>
          {[1, 2, 5, 22].map((count) => (
            <Items key={count} count={count} />
          ))}
        </CanvasContextProvider>,
      );
    // Polish has three plural forms.
    const polish: CanvasContext['translations'] = {
      strings: {
        '': {
          '1 file\x03@count files': '1 plik\x03@count pliki\x03@count plików',
        },
        Age: { '1 day\x03@count days': '1 dzień\x03@count dni' },
      },
      pluralFormula: { 1: 0, 2: 1, 3: 1, 4: 1, 22: 1, default: 2 },
    };
    expect(render(polish)).toBe(
      [
        // Translated, three forms; a two-form translation falls back to its
        // last form; untranslated strings use the source rule.
        '1 plik|1 dzień|1 item;',
        '2 pliki|2 dni|2 items;',
        '5 plików|5 dni|5 items;',
        '22 pliki|22 dni|22 items;',
      ].join(''),
    );
    // Without a formula, translations use the first form for 1 only.
    expect(
      render({
        strings: { Age: { '1 day\x03@count days': '1 jour\x03@count jours' } },
      }),
    ).toBe(
      [
        '1 file|1 jour|1 item;',
        '2 files|2 jours|2 items;',
        '5 files|5 jours|5 items;',
        '22 files|22 jours|22 items;',
      ].join(''),
    );
  });
});
