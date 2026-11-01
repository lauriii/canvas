import { describe, expect, it } from 'vitest';

import { injectPreviewAssets } from '@/features/layout/preview/previewAssets';

describe('injectPreviewAssets', () => {
  it('adds assets and merges settings into a document', () => {
    const html =
      '<!DOCTYPE html><html><head><title>Page</title></head><body><p>Hi</p>' +
      '<script type="application/json" data-drupal-selector="drupal-settings-json">' +
      '{"keep":1,"nested":{"a":1}}</script><script src="/core.js"></script></body></html>';
    expect(injectPreviewAssets(html, [])).toBe(html);

    const result = new DOMParser().parseFromString(
      injectPreviewAssets(html, [
        {
          css: [{ rel: 'stylesheet', href: '/new.css', media: 'all' }],
          jsHeader: [],
          jsFooter: [{ src: '/new.js', defer: true, nomodule: false }],
          settings: {
            nested: { b: 2 },
            added: true,
            unsafe: "</script><script>alert('x')</script>&",
          },
        },
      ]),
      'text/html',
    );

    expect(result.head.querySelector('link')!.outerHTML).toBe(
      '<link rel="stylesheet" href="/new.css" media="all">',
    );
    // New scripts follow the page's own, so their dependencies load first.
    expect(
      [...result.body.querySelectorAll('script[src]')].map((script) =>
        script.getAttribute('src'),
      ),
    ).toEqual(['/core.js', '/new.js']);
    expect(result.body.querySelector('script[src="/new.js"]')!.outerHTML).toBe(
      '<script src="/new.js" defer=""></script>',
    );
    const settingsElements = result.querySelectorAll(
      '[data-drupal-selector="drupal-settings-json"]',
    );
    expect(settingsElements).toHaveLength(1);
    expect(settingsElements[0].textContent).not.toMatch(/[<>&']/);
    expect(JSON.parse(settingsElements[0].textContent!)).toEqual({
      keep: 1,
      nested: { a: 1, b: 2 },
      added: true,
      unsafe: "</script><script>alert('x')</script>&",
    });
    // No value ends the settings element early.
    expect(result.querySelectorAll('script:not([src])')).toHaveLength(1);
  });
});
