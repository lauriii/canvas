import { describe, expect, it } from 'vitest';

import { isPageRedirect, serializeJsonForHtml } from '../index';

describe('isPageRedirect', () => {
  it('distinguishes redirect and page results', () => {
    expect(
      isPageRedirect({
        redirect: {
          external: false,
          url: '/new-location',
          statusCode: 301,
        },
      }),
    ).toBe(true);
    expect(
      isPageRedirect({
        content: { element: 'canvas-page' },
        head: { title: 'Page' },
        route: {
          name: 'entity.canvas_page.canonical',
          requestUri: '/page',
          params: {},
          managedByCanvas: true,
          entity: {
            entityType: 'canvas_page',
            bundle: 'canvas_page',
            id: '1',
            uuid: 'page-uuid',
            langcode: 'en',
          },
        },
      }),
    ).toBe(false);
    expect(
      isPageRedirect({
        content: null,
        head: { title: 'Empty page' },
        route: {
          name: 'user.login',
          requestUri: '/user/login',
          params: {},
          managedByCanvas: false,
          entity: null,
        },
      }),
    ).toBe(false);
  });
});

describe('serializeJsonForHtml', () => {
  it('keeps JSON parseable while escaping HTML-significant characters', () => {
    const value = {
      text: '</script><script>alert("x")</script>&\u2028\u2029',
    };

    const serialized = serializeJsonForHtml(value);

    expect(serialized).not.toContain('<');
    expect(serialized).not.toContain('>');
    expect(serialized).not.toContain('&');
    expect(JSON.parse(serialized)).toEqual(value);
  });
});
