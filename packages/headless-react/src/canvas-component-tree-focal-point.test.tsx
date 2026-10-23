// @vitest-environment jsdom

/**
 * An `image-with-focal-point` prop, as the content API returns it, renders
 * through `Image` on the server and hydrates with the same crop.
 */

import { act } from 'react';
import { Image } from 'drupal-canvas';
import { hydrateRoot } from 'react-dom/client';
import { renderToString } from 'react-dom/server';
import { describe, expect, it } from 'vitest';

import { CanvasComponentTree } from './canvas-component-tree';

import type { ComponentProps } from 'react';
import type { CanvasContext } from 'drupal-canvas';

const context: CanvasContext = {
  page: { pageTitle: 'Page', breadcrumbs: [], mainEntity: null },
  site: {
    branding: { homeUrl: '/', siteName: 'Site', siteSlogan: '' },
    baseUrl: 'https://drupal.example',
    themeAssets: { logo: { url: '' }, favicon: { url: '', mimeType: '' } },
  },
};

// The props shape asserted by FocalPointContentTest in canvas_headless.
const tree = {
  element: 'js-focal-point-external',
  props: {
    canvasUuid: 'focal',
    image: {
      src: 'https://drupal.example/sites/default/files/image-2.jpg',
      alt: 'image-2.jpg',
      width: 1200,
      height: 600,
      focalPoint: { x: 10, y: 20 },
    },
  },
};

function FocalPointExternal({
  image,
}: {
  image: ComponentProps<typeof Image>;
}) {
  return <Image {...image} style={{ width: 300, height: 150 }} />;
}

const App = () => (
  <CanvasComponentTree
    tree={tree}
    components={{ focal_point_external: FocalPointExternal }}
    context={context}
  />
);

describe('image-with-focal-point props', () => {
  it('crop around the focal point on the server and after hydration', async () => {
    const globalWithWindow = globalThis as { window?: unknown };
    const savedWindow = globalWithWindow.window;
    delete globalWithWindow.window;
    let html: string;
    try {
      html = renderToString(<App />);
    } finally {
      globalWithWindow.window = savedWindow;
    }
    expect(html).toContain('object-fit:cover');
    expect(html).toContain('object-position:10% 20%');

    (
      globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }
    ).IS_REACT_ACT_ENVIRONMENT = true;
    const container = document.createElement('div');
    container.innerHTML = html;
    document.body.append(container);
    const recoverable: unknown[] = [];
    await act(async () => {
      hydrateRoot(container, <App />, {
        onRecoverableError: (cause) => recoverable.push(cause),
      });
    });
    expect(recoverable).toEqual([]);
    expect(container.querySelector('img')?.style.objectPosition).toBe(
      '10% 20%',
    );
  });
});
