import { decodeSerializedProps } from './decode-serialized-props';

interface AstroIslandElement extends HTMLElement {
  attributeChangedCallback(): void;
  handleHydrationError(error: unknown): void;
}

interface AstroIslandElementConstructor {
  new (...params: any[]): AstroIslandElement;
}

interface AstroHydrator {
  (
    element: HTMLElement,
  ): (
    Component: unknown,
    props: Record<string, unknown>,
    slots: Record<string, string>,
    options: { client: string | null },
  ) => Promise<void>;
}

interface CanvasIslandElement extends AstroIslandElement {
  Component?: unknown;
  hydrator?: AstroHydrator;
  hydrate?: () => Promise<void>;
  getAttribute(qualifiedName: string): string | null;
  hasAttribute(qualifiedName: string): boolean;
  isConnected: boolean;
  parentElement: HTMLElement | null;
  querySelectorAll(selectors: string): NodeListOf<Element>;
  removeAttribute(qualifiedName: string): void;
  dispatchEvent(event: Event): boolean;
}

// Declare the Drupal runtime before any Code Component module evaluates, so
// the legacy getters and `new JsonApiClient()` keep working on Drupal pages.
// This header script runs before the islands' module scripts.
// @see packages/drupal-canvas/src/runtime.ts
(globalThis as Record<string, unknown>).__drupalCanvasRuntime = {
  environment: 'drupal',
};

(() => {
  const AstroIsland = customElements.get(
    'astro-island',
  ) as AstroIslandElementConstructor;

  if (AstroIsland === undefined) {
    throw new Error();
  }

  // Unmounts a removed island now. Astro defers this to the next view
  // transition, whose astro:after-swap event a Drupal page never fires, and
  // the deferred listener would keep the island referenced from the document.
  // @see node_modules/astro/dist/runtime/server/astro-island.js
  const unmountRemovedIsland = (island: Element) => {
    const { unmount } = island as Element & { unmount?: () => void };
    if (unmount) {
      document.removeEventListener('astro:after-swap', unmount);
      unmount();
    }
  };

  class CanvasIsland extends AstroIsland {
    static observedAttributes = ['props'];
    constructor() {
      super();
      const element = this as CanvasIslandElement;
      // Port of Astro's AstroIsland.hydrate. The props-decoding step differs:
      // Drupal's AstroIsland element JSON-encodes each prop value individually
      // before embedding it in the props attribute
      // @see \Drupal\canvas\Element\AstroIsland::buildProps(), so values
      // arrive as JSON strings and must be JSON.parse'd again, instead of unwrapped
      // from Astro tuples that Astro's reviver expects.
      //
      // Everything else (nested SSR island ordering, slot template ownership, post-hydration markers)
      // is kept identical to upstream so nested islands and re-hydration keep
      // working. When upgrading Astro, diff against:
      // @see node_modules/astro/dist/runtime/server/astro-island.js
      element.hydrate = async () => {
        if (!element.hydrator || !element.isConnected) {
          return;
        }
        // Preserve Astro's nested-island ordering: child islands must wait
        // until a parent SSR island finishes hydrating.
        const parentSsrIsland =
          element.parentElement?.closest('astro-island[ssr]');
        if (parentSsrIsland) {
          parentSsrIsland.addEventListener('astro:hydrate', element.hydrate!, {
            once: true,
          });
          return;
        }

        const slots: Record<string, string> = {};
        const slotTemplates = element.querySelectorAll(
          'template[data-astro-template]',
        );

        // Collect only this island's named slot templates before hydration so
        // nested islands keep ownership of their own slot content.
        for (const template of slotTemplates) {
          const ownerIsland = template.closest(element.tagName);
          if (ownerIsland?.isSameNode(element)) {
            slots[template.getAttribute('data-astro-template') || 'default'] =
              template.innerHTML;
            template.remove();
          }
        }

        const astroSlots = element.querySelectorAll('astro-slot');
        // Astro can also materialize slots as <astro-slot> wrappers.
        for (const astroSlot of astroSlots) {
          const ownerIsland = astroSlot.closest(element.tagName);
          if (ownerIsland?.isSameNode(element)) {
            slots[astroSlot.getAttribute('name') || 'default'] =
              astroSlot.innerHTML;
          }
        }

        let props: Record<string, unknown>;
        try {
          // Drupal's AstroIsland element serializes each public prop as a JSON
          // string inside props="...", and Astro passes that attribute into
          // hydration.
          const rawProps = element.hasAttribute('props')
            ? JSON.parse(element.getAttribute('props') ?? '{}')
            : {};
          props = decodeSerializedProps(rawProps, element);
        } catch (error) {
          let componentUrl =
            element.getAttribute('component-url') || '<unknown>';
          const componentExport = element.getAttribute('component-export');
          if (componentExport) {
            componentUrl += ` (export ${componentExport})`;
          }
          console.error(
            `[hydrate] Error parsing props for component ${componentUrl}`,
            element.getAttribute('props'),
            error,
          );
          throw error;
        }

        // The renderer only renders an island marked ssr: first, and again from
        // scratch after a props change, replacing its content. Unmount the
        // previous render first, or its components stay mounted on removed
        // markup. Without the mark, as when a moved island reconnects, the
        // renderer keeps the mounted island, and so must this.
        const rendering = element.hasAttribute('ssr');
        const nestedIslands = rendering
          ? [...element.querySelectorAll(element.tagName)]
          : [];
        if (rendering) {
          element.dispatchEvent(new CustomEvent('astro:unmount'));
        }

        try {
          await element.hydrator(element)(element.Component, props, slots, {
            client: element.getAttribute('client'),
          });
        } finally {
          // The render replaced the islands nested in its slots with new ones,
          // made from the slots read above, even if it then failed.
          nestedIslands.forEach(unmountRemovedIsland);
        }
        // Restore Astro's normal post-hydration markers for re-hydration.
        element.removeAttribute('ssr');
        element.dispatchEvent(new CustomEvent('astro:hydrate'));
      };
    }
    handleHydrationError(error: unknown) {
      // An island that failed to hydrate stays marked ssr, like one that is
      // yet to hydrate. This mark tells them apart, for example for the
      // Canvas UI, which shows a preview once its islands have hydrated.
      this.setAttribute('data-hydration-failed', '');
      super.handleHydrationError(error);
    }
    attributeChangedCallback() {
      // Add the ssr attribute back so we can re-hydrate when props change.
      this.setAttribute('ssr', '');
      super.attributeChangedCallback();
    }
  }
  customElements.define('canvas-island', CanvasIsland);
})();
