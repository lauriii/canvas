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
});
