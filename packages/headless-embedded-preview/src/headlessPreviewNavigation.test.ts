// @vitest-environment jsdom

import { afterEach, describe, expect, it, vi } from 'vitest';

import { observeHeadlessPreviewAvailability } from './headlessPreviewAvailability';
import {
  createDrupalHostNavigator,
  createDrupalPathResolver,
} from './headlessPreviewNavigation';

afterEach(() => {
  vi.useRealTimers();
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
  Reflect.deleteProperty(window.navigator, 'userActivation');
  document.body.replaceChildren();
  window.history.replaceState(null, '', '/');
});

describe('Drupal preview behavior', () => {
  it.each([
    ['#details', false, '', '/app', ''],
    ['#', false, '', '/app', ''],
    ['', false, '', '/app', ''],
    ['', true, '', '/app', ''],
    ['#details', true, '/cms', '/app', '?display=full&filter=a%20b'],
    ['#', true, '/cms/index.php', '', '?display=full'],
    [
      '#details',
      true,
      '/cms',
      '/app',
      '?display=full',
      '&_canvas_language=pl&_canvas_viewMode=teaser&_canvas_pageVariant=alternate',
    ],
  ] as const)(
    'authenticates and keeps fragments and controls in sync from "%s", node preview: %s',
    async (
      fragment,
      nodePreview,
      drupalBasePath,
      frontendBasePath,
      query,
      contextQuery: string = '',
    ) => {
      vi.useFakeTimers();
      vi.resetModules();
      const userActivation = { hasBeenActive: false, isActive: false };
      Object.defineProperty(window.navigator, 'userActivation', {
        configurable: true,
        value: userActivation,
      });
      const path = nodePreview
        ? '/fr/node/preview/preview-uuid/full'
        : '/article';
      const hostPath = `${drupalBasePath}${path}${query}`;
      const activationQuery = `${query}${query ? '&' : '?'}_canvas_excludeAutoSave=true`;
      const savedQuery = `${query}${contextQuery}${query ? '&' : '?'}_canvas_excludeAutoSave=true`;
      const frontendUrl = `https://frontend.example${frontendBasePath}${path}${savedQuery}`;
      window.history.replaceState(null, '', `${hostPath}${fragment}`);
      document.body.innerHTML = `
        ${nodePreview ? '<div class="node-preview-container"><a href="/node/1/edit">Back to content editing</a></div>' : ''}
        <main data-off-canvas-main-canvas>
          <div id="theme-content">Theme content</div>
          <div class="canvas-headless-preview">
            <div class="canvas-headless-preview__messages"><div data-drupal-messages>Page saved</div></div>
            <iframe src="about:blank"></iframe>
            <div class="canvas-headless-preview__error" hidden>Unavailable</div>
          </div>
        </main>`;
      const behaviors: Record<
        string,
        {
          attach(context: Document): void;
          detach(context: Document, settings: unknown, trigger: string): void;
        }
      > = {};
      const once = Object.assign(
        (_id: string, selector: string, context: Document) =>
          Array.from(context.querySelectorAll(selector)),
        {
          remove: (_id: string, selector: string, context: Document) =>
            Array.from(context.querySelectorAll(selector)),
        },
      );
      vi.stubGlobal('Drupal', { behaviors });
      vi.stubGlobal('once', once);
      vi.stubGlobal('drupalSettings', {
        canvas: {
          headlessPreview: {
            contentApiPath: `${drupalBasePath}/canvas/content-api`,
            drupalBasePath,
            frontendBasePath,
            frontendOrigin: 'https://frontend.example',
            previewUrl: `https://frontend.example${frontendBasePath}${path}${query}${contextQuery}`,
          },
        },
      });
      const fetchMock = vi
        .fn()
        .mockImplementation(async (url: string | URL) => {
          if (url.toString() === `${drupalBasePath}/session/token`) {
            return new Response('csrf-token');
          }
          return new Response(JSON.stringify({ assertion: 'preview-proof' }));
        });
      vi.stubGlobal('fetch', fetchMock);
      await import('./canvas-headless-preview');
      const behavior = behaviors.canvasHeadlessPreview;
      behavior.attach(document);
      const iframe = document.querySelector('iframe')!;
      try {
        await vi.advanceTimersByTimeAsync(1);
        expect(fetchMock).toHaveBeenCalledWith(
          `${drupalBasePath}/session/token`,
          { credentials: 'same-origin' },
        );
        expect(iframe.src).toBe(
          `https://frontend.example${frontendBasePath}/api/draft?assertion=preview-proof`,
        );
        expect(fetchMock).toHaveBeenCalledWith(
          new URL(
            `${drupalBasePath}/canvas-headless/assertion?` +
              new URLSearchParams({
                path: `${path}${activationQuery}${fragment}`,
              }),
            window.location.origin,
          ),
          expect.objectContaining({
            method: 'POST',
            headers: {
              Accept: 'application/json',
              'X-CSRF-Token': 'csrf-token',
            },
          }),
        );
        if (nodePreview) {
          expect(
            document.querySelector('.node-preview-container')!.parentElement,
          ).toBe(document.querySelector('.canvas-headless-preview'));
        }
        // The activation endpoint redirects the browser to the frontend page.
        iframe.src = `${frontendUrl}${fragment}`;
        expect(iframe.contentWindow!.location.href).toBe(
          `${frontendUrl}${fragment}`,
        );
        expect(
          document.querySelector('[data-drupal-messages]')!.closest('[inert]'),
        ).toBeNull();
        expect(
          document.querySelector('#theme-content')!.hasAttribute('inert'),
        ).toBe(true);

        const postMessage = vi.spyOn(iframe.contentWindow!, 'postMessage');
        iframe.dispatchEvent(new Event('load'));
        const handshake = postMessage.mock.calls.find(
          ([message]) => message.type === 'canvas-headless:status-request',
        )![0];
        const send = (data: Record<string, unknown>) =>
          window.dispatchEvent(
            new MessageEvent('message', {
              source: iframe.contentWindow,
              origin: 'https://frontend.example',
              data,
            }),
          );
        send({
          type: 'canvas-headless:renew-request',
          hostSessionId: handshake.hostSessionId,
          path: '/another-page?display=teaser&_canvas_language=pl&_canvas_viewMode=teaser&_canvas_pageVariant=alternate&_canvas_excludeAutoSave=true#heading',
        });
        await vi.advanceTimersByTimeAsync(1);
        expect(fetchMock).toHaveBeenCalledWith(
          new URL(
            `${drupalBasePath}/canvas-headless/assertion?` +
              new URLSearchParams({
                path: '/another-page?display=teaser&_canvas_language=pl&_canvas_viewMode=teaser&_canvas_pageVariant=alternate&_canvas_excludeAutoSave=true#heading',
                renewal: '1',
              }),
            window.location.origin,
          ),
          expect.objectContaining({ method: 'POST' }),
        );
        // Navigation readiness confirms the activated frontend is available.
        send({ type: 'canvas-headless:navigation-ready' });
        await vi.advanceTimersByTimeAsync(10_000);
        expect(iframe.hidden).toBe(false);
        expect(
          (
            document.querySelector(
              '.canvas-headless-preview__error',
            ) as HTMLElement
          ).hidden,
        ).toBe(true);

        // A navigation message alone must not trigger a route check or navigation.
        const currentUrl = window.location.href;
        const requestCount = fetchMock.mock.calls.length;
        send({
          type: 'canvas-headless:navigation',
          hostSessionId: handshake.hostSessionId,
          url: `${frontendUrl}#comments`,
        });
        await vi.advanceTimersByTimeAsync(1);
        expect(fetchMock).toHaveBeenCalledTimes(requestCount);
        expect(window.location.href).toBe(currentUrl);
        expect(iframe.contentWindow!.location.href).toBe(
          `${frontendUrl}${fragment}`,
        );

        // A real interaction inside the iframe also activates its parent window.
        userActivation.hasBeenActive = true;
        userActivation.isActive = true;
        send({
          type: 'canvas-headless:navigation',
          hostSessionId: handshake.hostSessionId,
          url: `${frontendUrl}#comments`,
        });
        await vi.advanceTimersByTimeAsync(1);
        expect(window.location.hash).toBe('#comments');
        expect(window.location.search).toBe(query);
        expect(iframe.contentWindow!.location.href).toBe(
          `${frontendUrl}#comments`,
        );

        send({
          type: 'canvas-headless:navigation',
          hostSessionId: handshake.hostSessionId,
          url: `${frontendUrl}#comments`,
        });
        await vi.advanceTimersByTimeAsync(1);
        expect(iframe.contentWindow!.location.href).toBe(
          `${frontendUrl}#comments`,
        );

        send({
          type: 'canvas-headless:navigation',
          hostSessionId: handshake.hostSessionId,
          url: `${frontendUrl}#`,
        });
        await vi.advanceTimersByTimeAsync(1);
        expect(window.location.href.endsWith(`${hostPath}#`)).toBe(true);
        expect(iframe.contentWindow!.location.href).toBe(`${frontendUrl}#`);

        if (nodePreview) {
          const confirmLeave = vi.fn((event: MouseEvent) => {
            if ((event.target as Element).closest('a')) {
              event.preventDefault();
            }
          });
          document.addEventListener('click', confirmLeave);
          try {
            send({
              type: 'canvas-headless:navigation',
              hostSessionId: handshake.hostSessionId,
              url: `https://frontend.example${frontendBasePath}/another-node`,
            });
            await vi.advanceTimersByTimeAsync(1);
            expect(confirmLeave).toHaveBeenCalledOnce();
            expect(window.location.pathname).toBe(`${drupalBasePath}${path}`);
          } finally {
            document.removeEventListener('click', confirmLeave);
          }
        }

        // Browser history can change the host hash without a frontend click.
        window.history.replaceState(null, '', `${hostPath}#details`);
        window.dispatchEvent(new HashChangeEvent('hashchange'));
        expect(iframe.contentWindow!.location.href).toBe(
          `${frontendUrl}#details`,
        );

        window.history.replaceState(null, '', `${hostPath}#`);
        window.dispatchEvent(new HashChangeEvent('hashchange'));
        expect(iframe.contentWindow!.location.href).toBe(`${frontendUrl}#`);

        // Recovery uses a fresh URL carrying this host's saved-content choice.
        send({
          type: 'canvas-headless:status',
          status: 'expired',
          hostSessionId: handshake.hostSessionId,
          path: `${path}${query}${contextQuery}#recovered`,
        });
        await vi.advanceTimersByTimeAsync(1);
        expect(fetchMock).toHaveBeenCalledWith(
          new URL(
            `${drupalBasePath}/canvas-headless/assertion?` +
              new URLSearchParams({ path: `${path}${savedQuery}#recovered` }),
            window.location.origin,
          ),
          expect.objectContaining({ method: 'POST' }),
        );
      } finally {
        behavior.detach(document, {}, 'unload');
      }
      const detachedUrl = iframe.contentWindow!.location.href;
      window.history.replaceState(null, '', `${hostPath}#after-detach`);
      window.dispatchEvent(new HashChangeEvent('hashchange'));
      expect(iframe.contentWindow!.location.href).toBe(detachedUrl);
      expect(
        document.querySelector('#theme-content')!.hasAttribute('inert'),
      ).toBe(false);
      if (nodePreview) {
        expect(
          document.querySelector('.node-preview-container')!.parentElement,
        ).toBe(document.body);
      }
    },
  );
});

describe('headless preview availability', () => {
  it('reports an unavailable frontend when the handshake times out', () => {
    vi.useFakeTimers();
    const iframe = document.createElement('iframe');
    document.body.appendChild(iframe);
    const onAvailable = vi.fn();
    const onUnavailable = vi.fn();
    const destroy = observeHeadlessPreviewAvailability({
      iframe,
      frontendOrigin: 'https://frontend.example',
      onAvailable,
      onUnavailable,
      timeout: 100,
    });

    vi.advanceTimersByTime(100);

    expect(onUnavailable).toHaveBeenCalledOnce();
    expect(onAvailable).not.toHaveBeenCalled();
    destroy();
  });

  it.each(['canvas-headless:status', 'canvas-headless:navigation-ready'])(
    'accepts %s only from the embedded frontend',
    (type) => {
      vi.useFakeTimers();
      const iframe = document.createElement('iframe');
      document.body.appendChild(iframe);
      const onAvailable = vi.fn();
      const onUnavailable = vi.fn();
      const destroy = observeHeadlessPreviewAvailability({
        iframe,
        frontendOrigin: 'https://frontend.example',
        onAvailable,
        onUnavailable,
        timeout: 100,
      });

      window.dispatchEvent(
        new MessageEvent('message', {
          data: { type },
          origin: 'https://other.example',
          source: iframe.contentWindow,
        }),
      );
      window.dispatchEvent(
        new MessageEvent('message', {
          data: { type: 'canvas-headless:other' },
          origin: 'https://frontend.example',
          source: iframe.contentWindow,
        }),
      );
      window.dispatchEvent(
        new MessageEvent('message', {
          data: { type },
          origin: 'https://frontend.example',
          source: window,
        }),
      );
      expect(onAvailable).not.toHaveBeenCalled();

      window.dispatchEvent(
        new MessageEvent('message', {
          data: { type },
          origin: 'https://frontend.example',
          source: iframe.contentWindow,
        }),
      );
      vi.advanceTimersByTime(100);

      expect(onAvailable).toHaveBeenCalledOnce();
      expect(onUnavailable).not.toHaveBeenCalled();
      destroy();
    },
  );
});

describe('headless preview Drupal path resolution', () => {
  it.each([
    '',
    '&_canvas_excludeAutoSave=true',
    '&_canvas_excludeAutoSave=false',
  ])(
    'moves a Drupal-owned frontend path to Drupal without the preview parameter "%s"',
    async (previewQuery) => {
      const fetchImpl = vi.fn<typeof fetch>().mockResolvedValue(
        new Response('{}', {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        }),
      );
      const resolvePath = createDrupalPathResolver({
        contentApiPath: '/subdir/canvas/content-api',
        drupalBasePath: '/subdir',
        frontendBasePath: '/app',
        locationOrigin: 'https://drupal.example',
        fetchImpl,
      });

      await expect(
        resolvePath(`/app/node/123?view=full${previewQuery}#details`),
      ).resolves.toBe(
        'https://drupal.example/subdir/node/123?view=full#details',
      );
      await expect(resolvePath('/app/node/123#')).resolves.toBe(
        'https://drupal.example/subdir/node/123#',
      );
      expect(fetchImpl).toHaveBeenCalledWith(
        new URL(
          'https://drupal.example/subdir/canvas/content-api?requestUri=%2Fnode%2F123%3Fview%3Dfull',
        ),
        expect.objectContaining({
          cache: 'no-store',
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
          signal: expect.any(AbortSignal),
        }),
      );
    },
  );

  it('leaves the path with the frontend when Drupal does not resolve it', async () => {
    const resolvePath = createDrupalPathResolver({
      contentApiPath: '/canvas/content-api',
      drupalBasePath: '',
      frontendBasePath: '',
      locationOrigin: 'https://drupal.example',
      fetchImpl: vi
        .fn<typeof fetch>()
        .mockResolvedValue(new Response('', { status: 404 })),
    });

    await expect(resolvePath('/frontend-only')).resolves.toBeNull();
  });

  it('does not resolve network failures or protocol-relative paths', async () => {
    const fetchImpl = vi
      .fn<typeof fetch>()
      .mockRejectedValue(new Error('offline'));
    const resolvePath = createDrupalPathResolver({
      contentApiPath: '/canvas/content-api',
      drupalBasePath: '',
      frontendBasePath: '',
      locationOrigin: 'https://drupal.example',
      fetchImpl,
    });

    await expect(resolvePath('/unavailable')).resolves.toBeNull();
    await expect(resolvePath('//other.example/path')).resolves.toBeNull();
    expect(fetchImpl).toHaveBeenCalledTimes(1);
  });

  it('stops a route lookup after four seconds', async () => {
    vi.useFakeTimers();
    const fetchImpl = vi.fn<typeof fetch>(
      (_input, init) =>
        new Promise((_resolve, reject) => {
          init?.signal?.addEventListener('abort', () => {
            reject(new DOMException('Aborted', 'AbortError'));
          });
        }),
    );
    const resolvePath = createDrupalPathResolver({
      contentApiPath: '/canvas/content-api',
      drupalBasePath: '',
      frontendBasePath: '',
      locationOrigin: 'https://drupal.example',
      fetchImpl,
    });

    const result = resolvePath('/slow-route');
    await vi.advanceTimersByTimeAsync(4_000);

    await expect(result).resolves.toBeNull();
  });
});

describe('headless preview host navigation', () => {
  it('navigates Drupal only after its route check succeeds', async () => {
    const assignLocation = vi.fn();
    const resolveDrupalPath = vi
      .fn()
      .mockResolvedValue('https://drupal.example/node/123');
    let navigate: (() => void) | undefined;
    const navigator = createDrupalHostNavigator({
      assignLocation,
      frontendOrigin: 'https://frontend.example',
      resolveDrupalPath,
      setNavigationTimer: (callback) => {
        navigate = callback;
        return 7;
      },
    });

    await navigator.navigate(
      'https://frontend.example/node/123?view=full&_canvas_excludeAutoSave=true#details',
    );

    expect(resolveDrupalPath).toHaveBeenCalledExactlyOnceWith(
      '/node/123?view=full#details',
    );
    expect(assignLocation).not.toHaveBeenCalled();
    navigate?.();
    expect(assignLocation).toHaveBeenCalledExactlyOnceWith(
      'https://drupal.example/node/123',
    );
    navigator.destroy();
  });

  it.each(['unresolved', 'error', 'timeout'] as const)(
    'opens the original frontend URL on the host when the route check is %s',
    async (failure) => {
      vi.useFakeTimers();
      const assignLocation = vi.fn();
      let navigate: (() => void) | undefined;
      const setNavigationTimer = vi.fn((callback: () => void) => {
        navigate = callback;
        return 7;
      });
      const openWindow = vi.spyOn(window, 'open');
      const fetchImpl = vi.fn<typeof fetch>(
        (_input, init) =>
          new Promise((_resolve, reject) => {
            init?.signal?.addEventListener('abort', () =>
              reject(new DOMException('Aborted', 'AbortError')),
            );
          }),
      );
      const resolveDrupalPath =
        failure === 'timeout'
          ? createDrupalPathResolver({
              contentApiPath: '/canvas/content-api',
              drupalBasePath: '',
              frontendBasePath: '',
              locationOrigin: 'https://drupal.example',
              fetchImpl,
            })
          : failure === 'error'
            ? vi.fn().mockRejectedValue(new Error('Route failure'))
            : vi.fn().mockResolvedValue(null);
      const navigator = createDrupalHostNavigator({
        assignLocation,
        frontendOrigin: 'https://frontend.example',
        resolveDrupalPath,
        setNavigationTimer,
      });

      const navigation = navigator.navigate(
        'https://frontend.example/catalog?_canvas_excludeAutoSave=true',
      );
      if (failure === 'timeout') await vi.advanceTimersByTimeAsync(4_000);
      await expect(navigation).resolves.toBeUndefined();

      expect(assignLocation).not.toHaveBeenCalled();
      expect(setNavigationTimer).toHaveBeenCalledOnce();
      navigate?.();
      expect(assignLocation).toHaveBeenCalledExactlyOnceWith(
        'https://frontend.example/catalog',
      );
      expect(openWindow).not.toHaveBeenCalled();
      navigator.destroy();
    },
  );

  it.each(['mailto:hello@example.com', 'not a URL'])(
    'ignores unsupported destinations such as %s',
    async (url) => {
      const assignLocation = vi.fn();
      const setNavigationTimer = vi.fn();
      const resolveDrupalPath = vi.fn();
      const navigator = createDrupalHostNavigator({
        assignLocation,
        frontendOrigin: 'https://frontend.example',
        resolveDrupalPath,
        setNavigationTimer,
      });

      await navigator.navigate(url);

      expect(assignLocation).not.toHaveBeenCalled();
      expect(setNavigationTimer).not.toHaveBeenCalled();
      expect(resolveDrupalPath).not.toHaveBeenCalled();
      navigator.destroy();
    },
  );

  it('opens an external HTTP URL on the host without a Drupal lookup', async () => {
    const assignLocation = vi.fn();
    const resolveDrupalPath = vi.fn();
    let navigate: (() => void) | undefined;
    const navigator = createDrupalHostNavigator({
      assignLocation,
      frontendOrigin: 'https://frontend.example',
      resolveDrupalPath,
      setNavigationTimer: (callback) => {
        navigate = callback;
        return 7;
      },
    });

    await navigator.navigate('https://external.example/article');
    navigate?.();

    expect(assignLocation).toHaveBeenCalledExactlyOnceWith(
      'https://external.example/article',
    );
    expect(resolveDrupalPath).not.toHaveBeenCalled();
    navigator.destroy();
  });

  it.each([
    ['https://frontend.example/article', 'https://drupal.example/node/123'],
    ['https://frontend.example/catalog', null],
    ['https://external.example/article', null],
  ])('preserves a new-tab target for %s', async (url, drupalUrl) => {
    const tab: { opener: unknown } = { opener: {} };
    const openWindow = vi.fn().mockReturnValue(tab);
    const assignLocation = vi.fn();
    const setNavigationTimer = vi.fn();
    const navigator = createDrupalHostNavigator({
      assignLocation,
      openWindow,
      frontendOrigin: 'https://frontend.example',
      resolveDrupalPath: vi.fn().mockResolvedValue(drupalUrl),
      setNavigationTimer,
    });

    await navigator.navigate(url, true);

    expect(openWindow).toHaveBeenCalledExactlyOnceWith(
      drupalUrl ?? url,
      '_blank',
      'noopener',
    );
    expect(tab.opener).toBeNull();
    expect(assignLocation).not.toHaveBeenCalled();
    expect(setNavigationTimer).not.toHaveBeenCalled();
    navigator.destroy();
  });

  it.each([
    [false, false],
    [false, true],
    [true, false],
    [true, true],
  ])(
    'supersedes only same-tab lookups, new tabs: first %s, second %s',
    async (firstInNewTab, secondInNewTab) => {
      let finishFirstLookup: ((value: string | null) => void) | undefined;
      const firstLookup = new Promise<string | null>((resolve) => {
        finishFirstLookup = resolve;
      });
      const assignLocation = vi.fn();
      const openWindow = vi.fn();
      let navigate: (() => void) | undefined;
      const setNavigationTimer = vi.fn((callback: () => void) => {
        navigate = callback;
        return 7;
      });
      const navigator = createDrupalHostNavigator({
        assignLocation,
        openWindow,
        frontendOrigin: 'https://frontend.example',
        setNavigationTimer,
        resolveDrupalPath: vi
          .fn()
          .mockReturnValueOnce(firstLookup)
          .mockResolvedValueOnce(null),
      });

      const firstNavigation = navigator.navigate(
        'https://frontend.example/first',
        firstInNewTab,
      );
      await navigator.navigate(
        'https://frontend.example/second',
        secondInNewTab,
      );
      finishFirstLookup?.('https://drupal.example/first');
      await firstNavigation;

      expect(assignLocation).not.toHaveBeenCalled();
      expect(openWindow.mock.calls).toEqual([
        ...(secondInNewTab
          ? [['https://frontend.example/second', '_blank', 'noopener']]
          : []),
        ...(firstInNewTab
          ? [['https://drupal.example/first', '_blank', 'noopener']]
          : []),
      ]);
      if (firstInNewTab && secondInNewTab) {
        expect(setNavigationTimer).not.toHaveBeenCalled();
      } else {
        expect(setNavigationTimer).toHaveBeenCalledOnce();
        navigate?.();
        expect(assignLocation).toHaveBeenCalledExactlyOnceWith(
          secondInNewTab
            ? 'https://drupal.example/first'
            : 'https://frontend.example/second',
        );
      }
      navigator.destroy();
    },
  );

  it.each([false, true])(
    'clears scheduled Drupal navigation when destroyed, intervening new tab: %s',
    async (openInNewTab) => {
      const clearNavigationTimer = vi.fn();
      const assignLocation = vi.fn();
      const openWindow = vi.fn();
      let navigate: (() => void) | undefined;
      const navigator = createDrupalHostNavigator({
        assignLocation,
        clearNavigationTimer,
        openWindow,
        frontendOrigin: 'https://frontend.example',
        resolveDrupalPath: vi
          .fn()
          .mockResolvedValue('https://drupal.example/node/123'),
        setNavigationTimer: (callback) => {
          navigate = callback;
          return 11;
        },
      });

      await navigator.navigate('https://frontend.example/node/123');
      if (openInNewTab) {
        await navigator.navigate('https://external.example/article', true);
        expect(openWindow).toHaveBeenCalledExactlyOnceWith(
          'https://external.example/article',
          '_blank',
          'noopener',
        );
        expect(clearNavigationTimer).not.toHaveBeenCalled();
      }
      navigator.destroy();
      navigate?.();

      expect(clearNavigationTimer).toHaveBeenCalledWith(11);
      expect(assignLocation).not.toHaveBeenCalled();
    },
  );

  it.each([false, true])(
    'ignores pending and subsequent requests after destruction, new tab: %s',
    async (openInNewTab) => {
      let finishLookup: ((value: string | null) => void) | undefined;
      const lookup = new Promise<string | null>((resolve) => {
        finishLookup = resolve;
      });
      const resolveDrupalPath = vi.fn().mockReturnValue(lookup);
      const setNavigationTimer = vi.fn();
      const openWindow = vi.fn();
      const navigator = createDrupalHostNavigator({
        frontendOrigin: 'https://frontend.example',
        resolveDrupalPath,
        setNavigationTimer,
        openWindow,
      });

      const navigation = navigator.navigate(
        'https://frontend.example/first',
        openInNewTab,
      );
      navigator.destroy();
      finishLookup?.('https://drupal.example/first');
      await navigation;
      await navigator.navigate('https://frontend.example/second', openInNewTab);

      expect(resolveDrupalPath).toHaveBeenCalledOnce();
      expect(setNavigationTimer).not.toHaveBeenCalled();
      expect(openWindow).not.toHaveBeenCalled();
    },
  );
});
