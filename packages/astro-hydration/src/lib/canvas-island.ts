// @cspell:ignore vnode
import { decodeSerializedProps } from './decode-serialized-props';

import type { VNode } from 'preact';

interface AstroIslandElement extends HTMLElement {
  attributeChangedCallback(): void;
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

  const isVnode = (element: any): element is VNode => {
    // Expected keys are 'type', 'key' and 'props'.
    const expected = ['type', 'key', 'props'];
    const keys = Object.keys(element);
    return expected.filter((key) => keys.includes(key)).length === 3;
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

        await element.hydrator(element)(element.Component, props, slots, {
          client: element.getAttribute('client'),
        });
        // Restore Astro's normal post-hydration markers for re-hydration.
        element.removeAttribute('ssr');
        element.dispatchEvent(new CustomEvent('astro:hydrate'));
      };
    }
    attributeChangedCallback() {
      // Add the ssr attribute back so we can re-hydrate when props change.
      this.setAttribute('ssr', '');
      // Astro hydration's preact renderer clears the innerHTML of the element
      // before rendering. During rendering preact sets a _children property on
      // the element that it uses for diffing the virtual-dom during rendering.
      // When the innerHTML has been removed, the presence of this property
      // means re-rendering doesn't work. The property is named _children in
      // development but in production the property name is minified. The type
      // of the property is a Preact VNode. We find this property by filtering
      // all properties of this element and then removing those that are VNodes.
      Object.entries(this)
        .filter(([key]) => key.startsWith('_'))
        .filter(([, value]) => isVnode(value))
        .forEach(([key]) => {
          delete this[key as keyof this];
        });
      super.attributeChangedCallback();
    }
  }
  customElements.define('canvas-island', CanvasIsland);
})();
