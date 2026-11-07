import { describe, expect, it, vi } from 'vitest';

import {
  injectPreviewAssets,
  loadPreviewAssets,
} from '@/features/layout/preview/previewAssets';

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

describe('injectPreviewAssets ordering and duplicates', () => {
  it('adds header scripts after the page scripts and skips present assets', () => {
    const html =
      '<!DOCTYPE html><html><head><link rel="stylesheet" href="/a.css"></head>' +
      '<body><script src="/core.js"></script></body></html>';
    const result = new DOMParser().parseFromString(
      injectPreviewAssets(html, [
        {
          css: [{ rel: 'stylesheet', href: '/a.css' }],
          jsHeader: [{ src: '/header.js' }],
          jsFooter: [{ src: '/core.js' }, { src: '/footer.js' }],
          settings: {},
        },
      ]),
      'text/html',
    );
    expect(result.querySelectorAll('link[href="/a.css"]')).toHaveLength(1);
    expect(
      [...result.querySelectorAll('script[src]')].map((script) =>
        script.getAttribute('src'),
      ),
    ).toEqual(['/core.js', '/header.js', '/footer.js']);
  });
});

describe('loadPreviewAssets', () => {
  it('merges copies of settings, skips nomodule waits and attaches behaviors', async () => {
    const iframe = document.createElement('iframe');
    iframe.dataset.canvasSwapActive = 'true';
    document.body.append(iframe);
    const preview = iframe.contentWindow as any;
    preview.Drupal = { attachBehaviors: vi.fn() };
    try {
      const settings = Object.freeze({ widget: Object.freeze({ a: 1 }) });
      await loadPreviewAssets({
        css: [],
        jsHeader: [],
        jsFooter: [{ src: '/legacy.js', nomodule: true }],
        settings,
      });
      // A later merge into the same namespace must not hit frozen objects.
      await loadPreviewAssets({
        css: [],
        jsHeader: [],
        jsFooter: [],
        settings: { widget: { b: 2 } },
      });
      expect(preview.drupalSettings.widget).toEqual({ a: 1, b: 2 });
      expect(preview.Drupal.attachBehaviors).toHaveBeenCalledExactlyOnceWith(
        preview.document,
      );
    } finally {
      iframe.remove();
    }
  });
});

describe('settings merging', () => {
  it('ignores keys that would change object prototypes', () => {
    const html =
      '<!DOCTYPE html><html><head></head><body>' +
      '<script type="application/json" data-drupal-selector="drupal-settings-json">{}</script>' +
      '</body></html>';
    injectPreviewAssets(html, [
      {
        css: [],
        jsHeader: [],
        jsFooter: [],
        settings: JSON.parse('{"__proto__": {"polluted": true}}'),
      },
    ]);
    expect(({} as Record<string, unknown>).polluted).toBeUndefined();
  });
});
