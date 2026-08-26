// @vitest-environment jsdom

import { act, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { renderToStaticMarkup } from 'react-dom/server';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { CanvasComponentTree } from './canvas-component-tree';

describe('CanvasComponentTree', () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('reports and omits an unregistered component subtree', () => {
    const error = vi.spyOn(console, 'error').mockImplementation(() => {});
    const html = renderToStaticMarkup(
      <CanvasComponentTree
        tree={{
          element: 'canvas-page',
          slots: {
            content: [
              {
                element: 'js-missing-card',
                props: { canvasUuid: 'missing-instance' },
                slots: {
                  default: {
                    element: 'js-registered-card',
                    props: { label: 'Nested content' },
                  },
                },
              },
              {
                element: 'js-registered-card',
                props: { label: 'Sibling content' },
              },
            ],
          },
        }}
        components={{
          'registered-card': ({ label }: { label: string }) => <p>{label}</p>,
        }}
      />,
    );

    expect(html).toBe('<p>Sibling content</p>');
    expect(error).toHaveBeenCalledWith(
      '[canvas] Canvas component "missing-card" (instance "missing-instance") is not registered; omitted subtree at "tree:content:0".',
    );
  });

  it('keeps published markup marker-free', () => {
    const tree = {
      element: 'js-card',
      props: { canvasUuid: 'card-one', label: 'Hello' },
    };
    const components = {
      card: ({ label }: { label: string }) => <article>{label}</article>,
    };

    expect(
      renderToStaticMarkup(
        <CanvasComponentTree tree={tree} components={components} />,
      ),
    ).toBe('<article>Hello</article>');
  });

  it('renders empty published content', () => {
    expect(
      renderToStaticMarkup(<CanvasComponentTree tree={null} components={{}} />),
    ).toBe('');
  });

  it('renders children of a synthetic multi-root wrapper in order', () => {
    expect(
      renderToStaticMarkup(
        <CanvasComponentTree
          tree={{
            element: 'renderless-container',
            slots: {
              children: [
                {
                  element: 'js-site-header',
                  props: { canvasUuid: 'header-one' },
                },
                {
                  element: 'js-article',
                  props: { canvasUuid: 'article-one', title: 'Hello' },
                },
                {
                  element: 'js-site-footer',
                  props: { canvasUuid: 'footer-one' },
                },
              ],
            },
          }}
          components={{
            'site-header': () => <header>Header</header>,
            article: ({ title }: { title: string }) => <main>{title}</main>,
            'site-footer': () => <footer>Footer</footer>,
          }}
        />,
      ),
    ).toBe('<header>Header</header><main>Hello</main><footer>Footer</footer>');
  });

  it('keeps a string slot default in published output', () => {
    expect(
      renderToStaticMarkup(
        <CanvasComponentTree
          tree={{
            element: 'js-card',
            slots: {
              default: {
                element: 'drupal-markup',
                slots: { default: '<p>Default example content</p>' },
              },
            },
          }}
          components={{
            card: ({ children }: { children?: React.ReactNode }) => (
              <article>{children}</article>
            ),
          }}
        />,
      ),
    ).toContain('Default example content');
  });

  it('renders component, empty slot, and top-level region boundaries in draft mode', () => {
    const html = renderToStaticMarkup(
      <CanvasComponentTree
        tree={{
          element: 'js-card',
          canvasDraftMode: true,
          props: {
            canvasUuid: 'card-one',
          },
          slots: {
            default: {
              element: 'drupal-markup',
              slots: { default: '<p>Default example content</p>' },
            },
          },
        }}
        components={{
          card: ({ children }: { children?: React.ReactNode }) => (
            <article>{children}</article>
          ),
        }}
      />,
    );

    expect(html).toContain(
      '<template data-canvas-marker="start" data-canvas-type="region" data-canvas-region-id="content"></template>',
    );
    expect(html).toContain(
      '<template data-canvas-marker="start" data-canvas-type="component" data-canvas-uuid="card-one"></template>',
    );
    expect(html).toContain(
      '<template data-canvas-marker="start" data-canvas-type="slot" data-canvas-slot-name="default" data-canvas-uuid="card-one/default"></template>',
    );
    expect(html).toContain('class="canvas--slot-empty-placeholder"');
    expect(html).not.toContain('class="canvas--slot-empty-placeholder" style=');
    expect(html).not.toContain('Default example content');
    expect(html).toContain(
      '<template data-canvas-marker="end" data-canvas-type="slot" data-canvas-slot-name="default" data-canvas-uuid="card-one/default"></template>',
    );
    expect(html).toContain(
      '<template data-canvas-marker="end" data-canvas-type="component" data-canvas-uuid="card-one"></template>',
    );
    expect(html).toContain(
      '<template data-canvas-marker="end" data-canvas-type="region" data-canvas-region-id="content"></template>',
    );
  });

  it('renders the standard empty-region placeholder for an empty draft tree', () => {
    const html = renderToStaticMarkup(
      <CanvasComponentTree
        tree={{ element: 'renderless-container', canvasDraftMode: true }}
        components={{}}
      />,
    );

    expect(html).toContain(
      '<template data-canvas-marker="start" data-canvas-type="region" data-canvas-region-id="content"></template>',
    );
    expect(html).toContain('class="canvas--region-empty-placeholder"');
    expect(html).not.toContain(
      'class="canvas--region-empty-placeholder" style=',
    );
    expect(html).toContain(
      '<template data-canvas-marker="end" data-canvas-type="region" data-canvas-region-id="content"></template>',
    );
  });

  it('renders components without identity but omits their editor markers', () => {
    const error = vi.spyOn(console, 'error').mockImplementation(() => {});
    const html = renderToStaticMarkup(
      <CanvasComponentTree
        tree={{ element: 'js-card', canvasDraftMode: true }}
        components={{ card: () => <article>Card</article> }}
      />,
    );

    expect(html).toContain('<article>Card</article>');
    expect(html).not.toContain('data-canvas-type="component"');
    expect(error).toHaveBeenCalledWith(
      '[canvas] Canvas component "card" has no instance UUID; editor markers were omitted at "tree".',
    );
  });

  it('preserves component identity when siblings are reordered', async () => {
    let nextInstance = 0;
    const Card = ({ label }: { label: string }) => {
      const [instance] = useState(() => ++nextInstance);
      return <article data-label={label} data-instance={instance} />;
    };
    const tree = (order: string[]) => ({
      element: 'canvas-page',
      canvasDraftMode: true as const,
      slots: {
        content: order.map((label) => ({
          element: 'js-card',
          props: { canvasUuid: `card-${label}`, label },
        })),
      },
    });
    const container = document.createElement('div');
    const root = createRoot(container);

    await act(async () => {
      root.render(
        <CanvasComponentTree
          tree={tree(['one', 'two'])}
          components={{ card: Card }}
        />,
      );
    });
    const initialInstances = Object.fromEntries(
      Array.from(container.querySelectorAll('article')).map((element) => [
        element.getAttribute('data-label'),
        element.getAttribute('data-instance'),
      ]),
    );

    await act(async () => {
      root.render(
        <CanvasComponentTree
          tree={tree(['two', 'one'])}
          components={{ card: Card }}
        />,
      );
    });
    const reorderedInstances = Object.fromEntries(
      Array.from(container.querySelectorAll('article')).map((element) => [
        element.getAttribute('data-label'),
        element.getAttribute('data-instance'),
      ]),
    );

    expect(reorderedInstances).toEqual(initialInstances);

    await act(async () => {
      root.render(
        <CanvasComponentTree
          tree={{
            element: 'js-card',
            canvasDraftMode: true,
            props: { canvasUuid: 'root-one', label: 'root' },
          }}
          components={{ card: Card }}
        />,
      );
    });
    const firstRootInstance = container
      .querySelector('article')
      ?.getAttribute('data-instance');

    await act(async () => {
      root.render(
        <CanvasComponentTree
          tree={{
            element: 'js-card',
            canvasDraftMode: true,
            props: { canvasUuid: 'root-two', label: 'root' },
          }}
          components={{ card: Card }}
        />,
      );
    });
    expect(
      container.querySelector('article')?.getAttribute('data-instance'),
    ).not.toBe(firstRootInstance);

    await act(async () => root.unmount());
  });
});
