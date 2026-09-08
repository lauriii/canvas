import { describe, expect, it } from 'vitest';

import { surrogateKeyHeader } from './page';

import type { Page } from './page';

function page(tags: string[]): Page {
  return {
    content: null,
    head: { title: 'x' },
    route: {
      name: 'r',
      requestUri: '/x',
      params: {},
      managedByCanvas: true,
      entity: null,
    },
    cacheability: { tags },
  };
}

describe('surrogateKeyHeader', () => {
  it('joins cache tags by spaces for a Surrogate-Key header', () => {
    expect(
      surrogateKeyHeader(
        page(['canvas_page:1', 'config:canvas.component.js.header']),
      ),
    ).toBe('canvas_page:1 config:canvas.component.js.header');
  });

  it('is empty when there are no tags', () => {
    expect(surrogateKeyHeader(page([]))).toBe('');
  });
});
