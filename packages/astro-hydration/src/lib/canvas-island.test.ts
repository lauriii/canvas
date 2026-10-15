import { describe, expect, it, vi } from 'vitest';

import { decodeSerializedProps } from './decode-serialized-props';

const loadModule = async () => {
  if (customElements.get('astro-island') === undefined) {
    customElements.define('astro-island', class extends HTMLElement {});
  }
  await import('./canvas-island');
};

describe('decodeSerializedProps', () => {
  it('decodes JSON-serialized props back to their original shapes', async () => {
    await loadModule();
    const element = document.createElement('canvas-island');
    element.setAttribute('uid', 'test-island');
    element.setAttribute('component-url', '/component.js');

    expect(
      decodeSerializedProps(
        {
          title: JSON.stringify('Hello'),
          count: JSON.stringify(0),
          ratio: JSON.stringify(1.25),
          visible: JSON.stringify(false),
          subtitle: JSON.stringify(null),
          emptyText: JSON.stringify(''),
          tags: JSON.stringify([]),
          config: JSON.stringify({}),
          cta: JSON.stringify({
            text: 'Read more',
            href: {
              uri: 'https://example.com',
              options: { target: '_blank' },
            },
            flags: [true, false, null],
          }),
          message: JSON.stringify('Line 1\n"quoted" / slash'),
          image: JSON.stringify({ src: '/cat.jpg', alt: '🦙' }),
        },
        element,
      ),
    ).toEqual({
      title: 'Hello',
      count: 0,
      ratio: 1.25,
      visible: false,
      subtitle: null,
      emptyText: '',
      tags: [],
      config: {},
      cta: {
        text: 'Read more',
        href: {
          uri: 'https://example.com',
          options: { target: '_blank' },
        },
        flags: [true, false, null],
      },
      message: 'Line 1\n"quoted" / slash',
      image: { src: '/cat.jpg', alt: '🦙' },
    });
  });

  it('returns an empty object when no props are provided', async () => {
    await loadModule();
    const element = document.createElement('canvas-island');

    expect(decodeSerializedProps({}, element)).toEqual({});
  });

  it('logs with context and preserves the raw string when a prop payload is malformed', async () => {
    await loadModule();
    const element = document.createElement('canvas-island');
    element.setAttribute('uid', 'broken-island');
    element.setAttribute('component-url', '/broken-component.js');
    const errorSpy = vi.spyOn(console, 'error').mockImplementation(() => {});

    expect(
      decodeSerializedProps(
        {
          good: JSON.stringify('ok'),
          broken: '{not json}',
          count: JSON.stringify(2),
        },
        element,
      ),
    ).toEqual({
      good: 'ok',
      broken: '{not json}',
      count: 2,
    });
    expect(errorSpy).toHaveBeenCalledWith(
      '[hydrate] Error decoding prop "broken" for component /broken-component.js (uid broken-island)',
      '{not json}',
      expect.any(Error),
    );

    errorSpy.mockRestore();
  });
});
