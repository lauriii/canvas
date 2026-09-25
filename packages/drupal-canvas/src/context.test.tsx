// @vitest-environment jsdom

import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { renderToStaticMarkup } from 'react-dom/server';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
  CanvasContextProvider,
  useHasCanvasContext,
  usePageContext,
  useSiteContext,
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
});
