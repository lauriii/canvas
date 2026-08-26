/**
 * @file
 * `<canvas-draft-session>`: the draft session lifecycle as a framework-free
 * custom element, for consumers without a component runtime of their own
 * (Astro, Nuxt without a session store, plain server-rendered pages). It
 * wraps the state machine in ./draft-session the way the React
 * `<DraftSession>` in @drupal-canvas/headless-react does, with the DOM as
 * the presentation contract instead of a render prop:
 *
 * - The element owns the machine lifecycle: one machine per session epoch,
 *   re-created in place when a renewal delivers a new tokenExpiresAt (the
 *   'renewed' event carries it, so no server re-render is needed).
 * - A host refresh request reloads the current document so server-rendered
 *   adapters fetch the latest Canvas auto-save data. Before reloading, the
 *   element emits a cancelable refresh event so framework adapters can use
 *   their own data-refresh or navigation primitive instead.
 * - Session state is reflected as host attributes (`expired`, `embedded`,
 *   `renew-state`) and announced via a bubbling
 *   `canvas-draft-session:change` CustomEvent, for consumers that want to
 *   drive their own presentation.
 * - Children opt into the protocol's intended visibility rules by marking
 *   themselves: `data-draft-session-view="active"` renders only while the
 *   session is live *and* the page is standalone (embedded, the host owns
 *   the session chrome); `data-draft-session-view="expired"` renders once
 *   the session has expired, embedded or not — expiry going invisible
 *   inside an iframe is the failure mode the renewal protocol exists for,
 *   so the expired view is the last-resort fallback for a host that does
 *   not speak it. A `data-draft-session-renew-link` element gets its href
 *   pointed at the renew URL with the current path, and is hidden when
 *   embedded (the link is a top-level navigation through Drupal, which
 *   makes no sense inside the frame) or when there is no renew URL.
 *
 * The element reads its configuration attributes once, when connected: it
 * serves server-rendered multi-page documents, where a new navigation is a
 * new document and a fresh element. The one exception is `path`, which is
 * observed: a client-routed app (Nuxt, a Next.js page transition) keeps
 * the element alive across navigations, and the host must hear about the
 * current path — status reports and the renew link both carry it. Without
 * the attribute the path is read from window.location at connect time.
 *
 * Alongside the session machine, the element also runs a content-height
 * reporter (./height-report) for the same `editor-origin`: an independent
 * exchange that lets the host size the preview iframe to fit.
 */

import { createDraftSession } from './draft-session';
import { createCanvasGeometryBridge } from './geometry-bridge';
import { createHeightReporter } from './height-report';

import type { DraftSession, DraftSessionRenewState } from './draft-session';
import type { CanvasGeometryBridge } from './geometry-bridge';
import type { HeightReporter } from './height-report';

export const DRAFT_SESSION_ELEMENT_TAG = 'canvas-draft-session';

/**
 * What the `canvas-draft-session:change` event's detail carries — the same
 * snapshot the React adapter's render prop receives.
 */
export interface DraftSessionElementSnapshot {
  embedded: boolean;
  expired: boolean;
  renewState: DraftSessionRenewState;
  path: string;
  renewUrl: string | null;
  tokenExpiresAt: number | null;
}

/**
 * The event name state changes are announced under (bubbling, composed).
 */
export const DRAFT_SESSION_CHANGE_EVENT = 'canvas-draft-session:change';

/**
 * Cancelable event emitted when the host reports new Canvas auto-save data.
 * Preventing its default behavior tells the element that an adapter owns the
 * refresh; otherwise the current document reloads.
 */
export const DRAFT_SESSION_REFRESH_EVENT =
  'canvas-draft-session:refresh-requested';

// The element must be importable in server-side module graphs (an Astro or
// Nuxt component imports it next to server code), where HTMLElement does
// not exist. The stand-in base is never instantiated there: only
// defineDraftSessionElement() — browser-only by nature — registers it.
const BaseElement: typeof HTMLElement =
  typeof HTMLElement === 'undefined'
    ? (class {} as unknown as typeof HTMLElement)
    : HTMLElement;

export class DraftSessionElement extends BaseElement {
  static observedAttributes = ['path'];

  #machine: DraftSession | null = null;
  #geometryBridge: CanvasGeometryBridge | null = null;
  #heightReporter: HeightReporter | null = null;
  #connected = false;
  #tokenExpiresAt: number | null = null;
  #expired = false;
  #embedded = false;
  #renewUrl: string | null = null;
  #path = '/';

  connectedCallback(): void {
    const expiresAttribute = this.getAttribute('token-expires-at');
    const parsedExpiry = expiresAttribute === null ? NaN : +expiresAttribute;
    this.#tokenExpiresAt = Number.isFinite(parsedExpiry) ? parsedExpiry : null;
    // Boolean-attribute semantics (presence means true), with one
    // divergence: template engines that stringify booleans instead of
    // omitting them produce initial-expired="false", and reading that as
    // expired would loop the host's session recovery forever.
    this.#expired =
      this.hasAttribute('initial-expired') &&
      this.getAttribute('initial-expired') !== 'false';
    this.#renewUrl = this.getAttribute('renew-url');
    this.#embedded = window.self !== window.top;
    this.#path = this.getAttribute('path') ?? window.location.pathname;
    this.#connected = true;

    this.#startEpoch();
    const editorOrigin = this.getAttribute('editor-origin');
    this.#heightReporter = createHeightReporter({
      editorOrigin,
      embedded: this.#embedded,
    });
    if (this.#embedded && editorOrigin) {
      this.#geometryBridge = createCanvasGeometryBridge({ editorOrigin });
    }
    this.#render();
  }

  disconnectedCallback(): void {
    this.#connected = false;
    this.#machine?.destroy();
    this.#machine = null;
    this.#heightReporter?.destroy();
    this.#heightReporter = null;
    this.#geometryBridge?.destroy();
    this.#geometryBridge = null;
  }

  attributeChangedCallback(
    name: string,
    _oldValue: string | null,
    newValue: string | null,
  ): void {
    // Parse-time attribute sets arrive before connectedCallback, which
    // reads them itself.
    if (!this.#connected || name !== 'path') {
      return;
    }
    const path = newValue ?? window.location.pathname;
    if (path === this.#path) {
      return;
    }
    this.#path = path;
    this.#machine?.setPath(path);
    this.#render();
  }

  /**
   * Creates the machine for the current epoch from the element's state.
   */
  #startEpoch(): void {
    this.#machine?.destroy();
    this.#machine = createDraftSession({
      tokenExpiresAt: this.#tokenExpiresAt,
      initialExpired: this.#expired,
      embedded: this.#embedded,
      path: this.#path,
      editorOrigin: this.getAttribute('editor-origin'),
      renewEndpoint: this.getAttribute('renew-endpoint') ?? undefined,
      onEvent: (event) => {
        if (event.type === 'refresh-requested') {
          const refreshEvent = new Event(DRAFT_SESSION_REFRESH_EVENT, {
            bubbles: true,
            cancelable: true,
            composed: true,
          });
          if (this.dispatchEvent(refreshEvent)) {
            window.location.reload();
          }
          return;
        }
        if (event.type === 'renewed') {
          if (event.tokenExpiresAt === null) {
            // The renewal succeeded (the cookie holds the new token) but
            // the response body did not state the new expiry, so the
            // machine cannot be re-armed in place. Reload to resync with
            // the server-rendered session state — coarse but dependable.
            window.location.reload();
            return;
          }
          this.#tokenExpiresAt = event.tokenExpiresAt;
          this.#expired = false;
          this.#startEpoch();
          this.#render();
          return;
        }
        this.#expired = this.#machine?.getState().expired ?? this.#expired;
        this.#render();
      },
    });
  }

  /**
   * Reflects the current state onto the host and the marked children, and
   * announces it.
   */
  #render(): void {
    const renewState: DraftSessionRenewState =
      this.#machine?.getState().renewState ?? 'idle';

    this.toggleAttribute('expired', this.#expired);
    this.toggleAttribute('embedded', this.#embedded);
    this.setAttribute('renew-state', renewState);

    for (const view of this.querySelectorAll<HTMLElement>(
      '[data-draft-session-view]',
    )) {
      const name = view.getAttribute('data-draft-session-view');
      const visible =
        name === 'expired'
          ? this.#expired
          : name === 'active'
            ? !this.#expired && !this.#embedded
            : false;
      view.hidden = !visible;
    }

    for (const link of this.querySelectorAll<HTMLAnchorElement>(
      '[data-draft-session-renew-link]',
    )) {
      if (this.#embedded || !this.#renewUrl) {
        link.hidden = true;
        continue;
      }
      link.hidden = false;
      link.href = `${this.#renewUrl}?path=${encodeURIComponent(this.#path)}`;
    }

    this.dispatchEvent(
      new CustomEvent<DraftSessionElementSnapshot>(DRAFT_SESSION_CHANGE_EVENT, {
        bubbles: true,
        composed: true,
        detail: {
          embedded: this.#embedded,
          expired: this.#expired,
          renewState,
          path: this.#path,
          renewUrl: this.#renewUrl,
          tokenExpiresAt: this.#tokenExpiresAt,
        },
      }),
    );
  }
}

/**
 * Registers the element under its canonical tag name. Safe to call more
 * than once (bundled twice, imported by two islands): an existing
 * registration wins.
 */
export function defineDraftSessionElement(): void {
  if (!customElements.get(DRAFT_SESSION_ELEMENT_TAG)) {
    customElements.define(DRAFT_SESSION_ELEMENT_TAG, DraftSessionElement);
  }
}
