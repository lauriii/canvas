// @vitest-environment jsdom
// cspell:ignore dzień fichier fichiers jours plik pliki plików Salut

import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { renderToStaticMarkup } from 'react-dom/server';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
  CanvasContextProvider,
  TranslationsProvider,
  useHasCanvasContext,
  usePageContext,
  useSiteContext,
  useTranslation,
} from './context';
import { resetCanvasDataReports } from './data-inspection';
import { resetWarnings } from './warnings';

import type { ReactElement } from 'react';
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

  it('translates with the nearest TranslationsProvider', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    function Greeting() {
      const { t } = useTranslation();
      return (
        <p>
          {t('Hello')}|{t('Bye')}
        </p>
      );
    }
    const drupal: CanvasContext = {
      ...context,
      translations: { strings: { '': { Hello: 'Bonjour', Bye: 'Au revoir' } } },
    };
    const app = { strings: { '': { Hello: 'Salut' } } };
    const html = (element: ReactElement) => renderToStaticMarkup(element);

    // Replaces the context's translations without merging, also when a
    // rendering integration provides the context below it.
    expect(
      html(
        <TranslationsProvider translations={app}>
          <CanvasContextProvider context={drupal}>
            <Greeting />
          </CanvasContextProvider>
        </TranslationsProvider>,
      ),
    ).toBe('<p>Salut|Bye</p>');
    // The nearest provider wins; `null` means no translations.
    expect(
      html(
        <CanvasContextProvider context={drupal}>
          <TranslationsProvider translations={app}>
            <TranslationsProvider translations={null}>
              <Greeting />
            </TranslationsProvider>
          </TranslationsProvider>
        </CanvasContextProvider>,
      ),
    ).toBe('<p>Hello|Bye</p>');
    // Without it, the context's translations apply.
    expect(
      html(
        <CanvasContextProvider context={drupal}>
          <Greeting />
        </CanvasContextProvider>,
      ),
    ).toBe('<p>Bonjour|Au revoir</p>');
    // Translations from the provider alone are not a missing context.
    expect(
      html(
        <TranslationsProvider translations={app}>
          <Greeting />
        </TranslationsProvider>,
      ),
    ).toBe('<p>Salut|Bye</p>');
    expect(warn).not.toHaveBeenCalled();
  });

  it('returns text, not HTML like Drupal.t()', () => {
    function Notice() {
      const { t, formatPlural } = useTranslation();
      const args = { '@name': '<i>Ada</i>' };
      return (
        <p title={t('Hello @name', args)}>
          {t('Hello @name', args)}|
          {formatPlural(2, '1 file for @name', '@count files for @name', args)}
        </p>
      );
    }
    const translated: CanvasContext = {
      ...context,
      translations: {
        strings: {
          '': {
            'Hello @name': '<b>Bonjour</b> @name',
            '1 file for @name\x03@count files for @name':
              '1 fichier pour @name\x03<b>@count</b> fichiers pour @name',
          },
        },
      },
    };
    // HTML in values and translations is escaped like any other text.
    expect(
      renderToStaticMarkup(
        <CanvasContextProvider context={translated}>
          <Notice />
        </CanvasContextProvider>,
      ),
    ).toBe(
      '<p title="&lt;b&gt;Bonjour&lt;/b&gt; &lt;i&gt;Ada&lt;/i&gt;">' +
        '&lt;b&gt;Bonjour&lt;/b&gt; &lt;i&gt;Ada&lt;/i&gt;|' +
        '&lt;b&gt;2&lt;/b&gt; fichiers pour &lt;i&gt;Ada&lt;/i&gt;</p>',
    );
  });

  it('replaces only @ placeholders', () => {
    function Notice() {
      const { t, formatPlural } = useTranslation();
      return (
        <p>
          {t('@name, %name, :url, !raw, name', {
            '@name': 'Ada',
            // @ts-expect-error Only `@` placeholders are supported.
            '%name': 'Grace',
            ':url': 'https://example.com',
            '!raw': '<b>raw</b>',
            name: 'Linus',
          })}
          |
          {formatPlural(2, '1 file', '@count files for %name', {
            // @ts-expect-error Only `@` placeholders are supported.
            '%name': 'Grace',
          })}
        </p>
      );
    }
    // Other keys are ignored, so their placeholders stay in the text.
    expect(
      renderToStaticMarkup(
        <CanvasContextProvider context={context}>
          <Notice />
        </CanvasContextProvider>,
      ),
    ).toBe('<p>Ada, %name, :url, !raw, name|2 files for %name</p>');
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

  it('maps counts above 199 onto the plural formula like Drupal', () => {
    function Files({ count }: { count: number }) {
      const { formatPlural } = useTranslation();
      return <>{formatPlural(count, '1 file', '@count files')};</>;
    }
    // Drupal only lists counts up to 199 in plural formulas.
    const translations: CanvasContext['translations'] = {
      strings: {
        '': {
          '1 file\x03@count files': '1 plik\x03@count pliki\x03@count plików',
        },
      },
      pluralFormula: { 1: 0, 2: 1, 22: 1, 102: 1, 122: 1, default: 2 },
    };
    expect(
      renderToStaticMarkup(
        <CanvasContextProvider context={{ ...context, translations }}>
          {[122, 199, 222, 1002, 212].map((count) => (
            <Files key={count} count={count} />
          ))}
        </CanvasContextProvider>,
      ),
    ).toBe('122 pliki;199 plików;222 pliki;1002 pliki;212 plików;');
  });
});
