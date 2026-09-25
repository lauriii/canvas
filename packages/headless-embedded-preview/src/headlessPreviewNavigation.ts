/**
 * @file
 * Resolves headless frontend paths against Drupal's existing content API.
 */

import { parsePreviewRequest } from '@drupal-canvas/headless-host';

export interface DrupalPathResolverOptions {
  contentApiPath: string;
  drupalBasePath: string;
  frontendBasePath: string;
  locationOrigin?: string;
  fetchImpl?: typeof fetch;
}

export interface HostNavigationTab {
  opener: unknown;
}

export interface DrupalHostNavigatorOptions {
  assignLocation?: (url: string) => void;
  clearNavigationTimer?: (timer: number) => void;
  frontendOrigin: string;
  openWindow?: (
    url: string,
    target: string,
    features?: string,
  ) => HostNavigationTab | null;
  resolveDrupalPath: (path: string) => Promise<string | null>;
  setNavigationTimer?: (callback: () => void, delay: number) => number;
}

export interface DrupalHostNavigator {
  destroy(): void;
  navigate(absoluteUrl: string, openInNewTab?: boolean): Promise<void>;
}

const DRUPAL_PATH_RESOLUTION_TIMEOUT_MS = 4_000;

/** Preserves an empty fragment, which still tells the browser to scroll up. */
export function getUrlFragment(url: URL): string {
  return url.hash || (url.href.endsWith('#') ? '#' : '');
}

/**
 * Creates the route check used by the Drupal-hosted headless preview.
 */
export function createDrupalPathResolver({
  contentApiPath,
  drupalBasePath,
  frontendBasePath,
  locationOrigin = window.location.origin,
  fetchImpl = fetch,
}: DrupalPathResolverOptions): (path: string) => Promise<string | null> {
  return async (path: string): Promise<string | null> => {
    if (!path.startsWith('/') || path.startsWith('//')) {
      return null;
    }

    const frontendUrl = new URL(
      parsePreviewRequest(path).requestUri,
      'https://canvas.invalid',
    );
    let drupalPathname = frontendUrl.pathname;
    if (
      frontendBasePath &&
      (drupalPathname === frontendBasePath ||
        drupalPathname.startsWith(`${frontendBasePath}/`))
    ) {
      drupalPathname = drupalPathname.slice(frontendBasePath.length) || '/';
    }

    const requestUri = `${drupalPathname}${frontendUrl.search}`;
    const contentApiUrl = new URL(contentApiPath, locationOrigin);
    contentApiUrl.searchParams.set('requestUri', requestUri);
    const abortController = new AbortController();
    const timeout = setTimeout(
      () => abortController.abort(),
      DRUPAL_PATH_RESOLUTION_TIMEOUT_MS,
    );

    try {
      const response = await fetchImpl(contentApiUrl, {
        cache: 'no-store',
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
        signal: abortController.signal,
      });
      if (!response.ok) {
        return null;
      }
    } catch {
      return null;
    } finally {
      clearTimeout(timeout);
    }

    const normalizedBasePath = drupalBasePath.replace(/\/$/, '');
    const target = new URL(
      `${normalizedBasePath}${drupalPathname}${frontendUrl.search}${getUrlFragment(frontendUrl)}`,
      locationOrigin,
    );
    return target.toString();
  };
}

/**
 * Navigates outside the iframe, using Drupal URLs when its route check succeeds.
 * Other destinations and failed lookups keep the original clicked URL.
 * Only current-tab requests supersede each other; new-tab requests are independent.
 */
export function createDrupalHostNavigator({
  assignLocation = (url) => window.location.assign(url),
  clearNavigationTimer = (timer) => window.clearTimeout(timer),
  frontendOrigin,
  openWindow = (url, target, features) => window.open(url, target, features),
  resolveDrupalPath,
  setNavigationTimer = (callback, delay) => window.setTimeout(callback, delay),
}: DrupalHostNavigatorOptions): DrupalHostNavigator {
  let generation = 0;
  let navigationTimer: number | null = null;
  let destroyed = false;

  return {
    async navigate(absoluteUrl, openInNewTab = false) {
      if (destroyed) {
        return;
      }
      const currentGeneration = openInNewTab ? generation : ++generation;
      if (!openInNewTab && navigationTimer !== null) {
        clearNavigationTimer(navigationTimer);
        navigationTimer = null;
      }

      let frontendUrl: URL;
      try {
        frontendUrl = new URL(absoluteUrl);
      } catch {
        return;
      }
      if (!['http:', 'https:'].includes(frontendUrl.protocol)) {
        return;
      }

      if (frontendUrl.origin === frontendOrigin) {
        frontendUrl = new URL(parsePreviewRequest(frontendUrl.href).requestUri);
      }

      let target = frontendUrl.toString();
      if (frontendUrl.origin === frontendOrigin) {
        try {
          target =
            (await resolveDrupalPath(
              `${frontendUrl.pathname}${frontendUrl.search}${getUrlFragment(frontendUrl)}`,
            )) ?? target;
        } catch {
          // A route-check failure still opens the original URL outside the iframe.
        }
      }
      if (destroyed || (!openInNewTab && currentGeneration !== generation)) {
        return;
      }

      if (openInNewTab) {
        const tab = openWindow(target, '_blank', 'noopener');
        if (tab) {
          tab.opener = null;
        }
        return;
      }

      navigationTimer = setNavigationTimer(() => {
        navigationTimer = null;
        if (currentGeneration === generation) {
          assignLocation(target);
        }
      }, 0);
    },

    destroy() {
      destroyed = true;
      generation += 1;
      if (navigationTimer !== null) {
        clearNavigationTimer(navigationTimer);
        navigationTimer = null;
      }
    },
  };
}
