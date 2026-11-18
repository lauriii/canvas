// @vitest-environment jsdom

import {
  useJsonApiClient,
  usePageContext,
  useSiteContext,
} from 'drupal-canvas/react';
import { renderToStaticMarkup } from 'react-dom/server';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { CanvasComponentTree } from './canvas-component-tree';

function Header() {
  const page = usePageContext();
  const site = useSiteContext();
  const client = useJsonApiClient();
  return (
    <header>
      {page?.pageTitle ?? 'no-page'}|{site?.branding.siteName ?? 'no-site'}|
      {client ? client.baseUrl : 'no-client'}
    </header>
  );
}

// In its own file so no earlier render in the module registry has emitted
// the deduplicated warnings already.
describe('CanvasComponentTree without providers', () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('reports a missing provider once and renders null-safe output', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    const html = renderToStaticMarkup(
      <CanvasComponentTree
        tree={{
          root: 'root',
          elements: {
            root: {
              type: 'renderless-container',
              props: {},
              children: ['one', 'two'],
            },
            one: {
              type: 'js.header',
              props: {},
              children: [],
            },
            two: {
              type: 'js.header',
              props: {},
              children: [],
            },
          },
        }}
        components={{ header: Header }}
      />,
    );
    expect(html).toContain('no-page|no-site|no-client');
    const messages = warn.mock.calls.map(([message]) => String(message));
    expect(messages.filter((m) => m.includes('usePageContext()'))).toHaveLength(
      1,
    );
    expect(messages.filter((m) => m.includes('useSiteContext()'))).toHaveLength(
      1,
    );
    expect(
      messages.filter((m) => m.includes('useJsonApiClient()')),
    ).toHaveLength(1);
  });
});
