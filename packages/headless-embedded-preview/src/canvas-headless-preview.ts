/**
 * @file
 * Hosts authenticated headless previews inside Drupal entity routes.
 */

import {
  createHeadlessPreviewHost,
  parsePreviewRequest,
  withPreviewContext,
} from '@drupal-canvas/headless-host';

import { observeHeadlessPreviewAvailability } from './headlessPreviewAvailability';
import {
  createDrupalHostNavigator,
  createDrupalPathResolver,
  getUrlFragment,
} from './headlessPreviewNavigation';

import type { HeadlessPreviewHost } from '@drupal-canvas/headless-host';
import type { DrupalHostNavigator } from './headlessPreviewNavigation';

interface HeadlessPreviewSettings {
  contentApiPath: string;
  drupalBasePath: string;
  frontendBasePath: string;
  frontendOrigin: string;
  previewUrl: string;
}

interface PreviewState {
  destroyAvailabilityObserver: () => void;
  syncFragment: () => void;
  host: HeadlessPreviewHost | null;
  hiddenElements: HiddenElementState[];
  navigator: DrupalHostNavigator;
  restoreControls: () => void;
}

interface HiddenElementState {
  element: Element;
  ariaHidden: string | null;
  wasInert: boolean;
}

interface DrupalBehavior {
  attach(context: Document | Element): void;
  detach?(
    context: Document | Element,
    settings: unknown,
    trigger: string,
  ): void;
}

interface DrupalGlobals {
  Drupal: {
    behaviors: Record<string, DrupalBehavior>;
  };
  drupalSettings: {
    canvas?: {
      headlessPreview?: HeadlessPreviewSettings;
    };
  };
  once: {
    (id: string, selector: string, context?: Document | Element): HTMLElement[];
    remove(
      id: string,
      selector: string,
      context?: Document | Element,
    ): HTMLElement[];
  };
}

const { Drupal, drupalSettings, once } = window as unknown as DrupalGlobals;
const previewStates = new WeakMap<HTMLElement, PreviewState>();

/** Makes the preview the only interactive branch inside Drupal's main canvas. */
function hidePreviewSiblings(
  preview: HTMLElement,
  mainCanvas: Element | null,
): HiddenElementState[] {
  if (!mainCanvas?.contains(preview)) {
    return [];
  }

  const hiddenElements: HiddenElementState[] = [];
  let current: Element = preview;
  while (current !== mainCanvas) {
    const parent = current.parentElement;
    if (!parent) {
      break;
    }
    for (const sibling of Array.from(parent.children)) {
      if (sibling === current) {
        continue;
      }
      hiddenElements.push({
        element: sibling,
        ariaHidden: sibling.getAttribute('aria-hidden'),
        wasInert: sibling.hasAttribute('inert'),
      });
      sibling.setAttribute('inert', '');
      sibling.setAttribute('aria-hidden', 'true');
    }
    current = parent;
  }
  return hiddenElements;
}

Drupal.behaviors.canvasHeadlessPreview = {
  attach(context) {
    const settings = drupalSettings.canvas?.headlessPreview;
    if (!settings) {
      return;
    }

    once(
      'canvas-headless-preview',
      '.canvas-headless-preview',
      context,
    ).forEach((preview) => {
      const iframe = preview.querySelector('iframe');
      const error = preview.querySelector('.canvas-headless-preview__error');
      if (
        !(iframe instanceof HTMLIFrameElement) ||
        !(error instanceof HTMLElement)
      ) {
        return;
      }

      // Fragments never reach Drupal's server. Copy them into the frontend URL
      // on initial load and when host navigation or browser history changes it.
      const previewUrl = new URL(
        withPreviewContext(settings.previewUrl, {
          ...parsePreviewRequest(settings.previewUrl),
          excludeAutoSave: true,
        }),
      );
      const getFragment = () => getUrlFragment(new URL(window.location.href));
      previewUrl.hash = getFragment();
      const syncFragment = () => {
        previewUrl.hash = getFragment();
        // Keep one history entry per host navigation. Setting src would add
        // a second entry for the iframe and make Back stop there first.
        iframe.contentWindow?.location.replace(previewUrl.toString());
      };
      const resolveDrupalPath = createDrupalPathResolver(settings);
      const navigator = createDrupalHostNavigator({
        assignLocation: (url) => {
          const target = new URL(url);
          // Repeated clicks on the current fragment do not fire hashchange,
          // but must still scroll the iframe back to that target.
          if (
            target.href === window.location.href &&
            getUrlFragment(target) !== ''
          ) {
            syncFragment();
          }
          const current = new URL(window.location.href);
          if (
            document.querySelector('.node-preview-container') &&
            (target.origin !== current.origin ||
              target.pathname !== current.pathname ||
              target.search !== current.search)
          ) {
            // Dispatch a real link click so core's node preview behavior can
            // confirm leaving the unsaved form preview.
            const link = document.createElement('a');
            link.href = url;
            link.hidden = true;
            preview.append(link);
            link.click();
            link.remove();
            return;
          }
          window.location.assign(url);
        },
        frontendOrigin: settings.frontendOrigin,
        resolveDrupalPath,
      });
      const state: PreviewState = {
        restoreControls: () => {},
        syncFragment,
        destroyAvailabilityObserver: observeHeadlessPreviewAvailability({
          iframe,
          frontendOrigin: settings.frontendOrigin,
          onAvailable: () => {
            iframe.hidden = false;
            error.hidden = true;
          },
          onUnavailable: () => {
            iframe.hidden = true;
            error.hidden = false;
          },
        }),
        host: null,
        hiddenElements: hidePreviewSiblings(
          preview,
          document.querySelector('[data-off-canvas-main-canvas]'),
        ),
        navigator,
      };

      // Core places the edit backlink and view-mode selector in page_top.
      // Keep the same form and its behaviors, above the iframe and messages.
      const controls = document.querySelector('.node-preview-container');
      if (controls?.parentNode) {
        const parent = controls.parentNode;
        const next = controls.nextSibling;
        preview.prepend(controls);
        state.restoreControls = () => parent.insertBefore(controls, next);
      }

      state.host = createHeadlessPreviewHost({
        iframe,
        frontendOrigin: settings.frontendOrigin,
        draftUrl: `${settings.frontendOrigin}${settings.frontendBasePath}/api/draft`,
        fetchAssertion: async (params: Record<string, string>) => {
          const csrf = await fetch(`${settings.drupalBasePath}/session/token`, {
            credentials: 'same-origin',
          });
          if (!csrf.ok) {
            throw new Error('Could not obtain a preview CSRF token.');
          }
          const url = new URL(
            `${settings.drupalBasePath}/canvas-headless/assertion`,
            window.location.origin,
          );
          Object.entries(params).forEach(([key, value]) =>
            url.searchParams.set(key, value),
          );
          // Keep Drupal's selected content on activation, renewal, and recovery.
          url.searchParams.set(
            'path',
            withPreviewContext(params.path, {
              ...parsePreviewRequest(params.path),
              excludeAutoSave: true,
            }),
          );
          const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
              Accept: 'application/json',
              'X-CSRF-Token': await csrf.text(),
            },
          });
          if (!response.ok) {
            throw new Error('Could not authenticate the entity preview.');
          }
          const body = await response.json();
          if (typeof body.assertion !== 'string') {
            throw new Error('The preview assertion is missing.');
          }
          return body.assertion;
        },
        onEvent: (event) => {
          if (event.type === 'active') {
            iframe.hidden = false;
            error.hidden = true;
          }
          if (
            event.type === 'activation-failed' ||
            event.type === 'recovery-failed' ||
            event.type === 'renew-failed'
          ) {
            iframe.hidden = true;
            error.hidden = false;
          }
        },
        onNavigate: (absoluteUrl, { openInNewTab }) =>
          navigator.navigate(absoluteUrl, openInNewTab),
      });
      previewStates.set(preview, state);
      window.addEventListener('hashchange', syncFragment);
      const currentUrl = new URL(window.location.href);
      // Match Drupal's site-relative request path, retaining language prefixes.
      let path = currentUrl.pathname;
      if (
        settings.drupalBasePath &&
        (path === settings.drupalBasePath ||
          path.startsWith(`${settings.drupalBasePath}/`))
      ) {
        path = path.slice(settings.drupalBasePath.length) || '/';
      }
      void state.host.activate({
        path: path + currentUrl.search + getUrlFragment(currentUrl),
      });
    });
  },

  detach(context, _settings, trigger) {
    if (trigger !== 'unload') {
      return;
    }
    once
      .remove('canvas-headless-preview', '.canvas-headless-preview', context)
      .forEach((preview) => {
        const state = previewStates.get(preview);
        if (!state) {
          return;
        }
        state.destroyAvailabilityObserver();
        window.removeEventListener('hashchange', state.syncFragment);
        state.navigator.destroy();
        state.host?.destroy();
        state.restoreControls();
        for (const hidden of state.hiddenElements) {
          if (hidden.wasInert) {
            hidden.element.setAttribute('inert', '');
          } else {
            hidden.element.removeAttribute('inert');
          }
          if (hidden.ariaHidden === null) {
            hidden.element.removeAttribute('aria-hidden');
          } else {
            hidden.element.setAttribute('aria-hidden', hidden.ariaHidden);
          }
        }
        previewStates.delete(preview);
      });
  },
};
