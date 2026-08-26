import { describe, expect, it } from 'vitest';

import { toNextMetadata } from './head';

import type { PageHead } from '@drupal-canvas/headless';

const head: PageHead = {
  title: 'Article',
  meta: [
    {
      name: 'description',
      property: 'og:description',
      content: 'Description',
    },
    { property: 'og:title', content: 'OG article' },
    { property: 'article:author', content: 'Unsupported property' },
    { name: 'custom-name', content: 'Preserved' },
  ],
  link: [
    { rel: 'canonical', href: 'https://example.com/article' },
    {
      rel: 'alternate',
      href: 'https://example.com/fr/article',
      hreflang: 'fr',
    },
  ],
};

describe('toNextMetadata', () => {
  it('maps native metadata without changing property semantics', () => {
    expect(toNextMetadata(head)).toEqual({
      title: { absolute: 'Article' },
      description: 'Description',
      alternates: {
        canonical: 'https://example.com/article',
        languages: { fr: 'https://example.com/fr/article' },
      },
      openGraph: { description: 'Description', title: 'OG article' },
      other: { 'custom-name': 'Preserved' },
    });
  });
});
