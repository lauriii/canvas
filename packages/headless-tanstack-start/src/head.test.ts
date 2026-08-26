import { describe, expect, it } from 'vitest';

import { toTanStackHead } from './head';

import type { PageHead } from '@drupal-canvas/headless';

describe('toTanStackHead', () => {
  it('maps React attributes and safely serialized JSON-LD', () => {
    const head: PageHead = {
      title: 'Article',
      meta: [
        { name: 'description', content: 'Example description' },
        { property: 'og:title', content: 'Article' },
        { 'http-equiv': 'content-language', content: 'en' },
        { charset: 'utf-8' },
      ],
      link: [
        {
          rel: 'alternate',
          href: '/fr',
          charset: 'utf-8',
          hreflang: 'fr',
          crossorigin: 'anonymous',
          fetchpriority: 'high',
          imagesizes: '100vw',
          imagesrcset: '/small.jpg 480w, /large.jpg 960w',
          referrerpolicy: 'strict-origin',
        },
        {
          rel: 'alternate',
          href: '/invalid',
          crossorigin: 'invalid',
          fetchpriority: 'invalid',
          referrerpolicy: 'invalid',
        },
      ],
      script: [
        {
          type: 'application/ld+json',
          textContent: { text: '</script>' },
        },
      ],
    };

    expect(toTanStackHead(head)).toEqual({
      meta: [
        { title: 'Article' },
        { name: 'description', content: 'Example description' },
        { property: 'og:title', content: 'Article' },
        { httpEquiv: 'content-language', content: 'en' },
        { charSet: 'utf-8' },
      ],
      links: [
        {
          rel: 'alternate',
          href: '/fr',
          charSet: 'utf-8',
          hrefLang: 'fr',
          crossOrigin: 'anonymous',
          fetchPriority: 'high',
          imageSizes: '100vw',
          imageSrcSet: '/small.jpg 480w, /large.jpg 960w',
          referrerPolicy: 'strict-origin',
        },
        {
          rel: 'alternate',
          href: '/invalid',
        },
      ],
      scripts: [
        {
          type: 'application/ld+json',
          children: '{"text":"\\u003C/script\\u003E"}',
        },
      ],
    });
  });
});
