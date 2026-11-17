/**
 * @file
 * The Canvas client renderer for Code Component islands.
 *
 * Wraps Astro's Preact client renderer so every island, including islands
 * nested through slots, renders inside the shared `CanvasContextProvider` and
 * `JsonApiClientProvider` from `drupal-canvas`. The providers add no DOM
 * wrapper, and component authors do not change their exports.
 *
 * `drupal-canvas` and `preact` are imported through the page's import map
 * (see astro.config.mjs), so the providers here and the hooks a component
 * imports share one context instance. So is each library that takes an
 * island's hydration data, such as `swr`, and Canvas's own
 * `@drupal-canvas/astro-hydration/hydration-data`, which decodes it with the
 * same copy of devalue Canvas SSR encoded it with.
 *
 * @see docs/adr/0021-code-component-runtime-compatibility-across-frontend-modes.md
 * @see \Drupal\canvas\Element\AstroIsland
 */

import {
  createJsonApiClient,
  drupalSettingsToCanvasContext,
  drupalSettingsToJsonApiRuntimeConfig,
  readCanvasDataV0,
} from 'drupal-canvas';
import {
  CanvasContextProvider,
  JsonApiClientProvider,
} from 'drupal-canvas/react';
import { h } from 'preact';
import { useEffect, useReducer, useRef } from 'preact/hooks';
import preactRenderer from '@astrojs/preact/client.js';

import type { CanvasContext, CanvasJsonApiClient } from 'drupal-canvas';
import type { ComponentType, VNode } from 'preact';

// The providers are typed against React; Drupal maps `react` to
// `preact/compat`, so they render as Preact components here.
const ContextProvider = CanvasContextProvider as unknown as ComponentType<{
  context: CanvasContext;
}>;
const ClientProvider = JsonApiClientProvider as unknown as ComponentType<{
  client: CanvasJsonApiClient;
}>;

type ClientRenderer = (
  element: HTMLElement,
) => (
  Component: ComponentType<Record<string, unknown>>,
  props: Record<string, unknown>,
  slots: Record<string, string>,
  metadata: { client: string },
) => Promise<void>;

/** The attribute Drupal sets on preview islands. */
const PREVIEW_ATTRIBUTE = 'data-canvas-preview';

/**
 * The attribute Canvas SSR sets on a hydrated island whose libraries rendered
 * from state the browser doesn't have yet: each library's state by its name,
 * encoded by devalue, which carries what JSON can't, such as a `Date` or a
 * structure that refers to itself.
 *
 * @see \Drupal\canvas_ssr\Element\AstroIslandSsr
 */
const HYDRATION_DATA_ATTRIBUTE = 'hydration-data';

/** Wraps an island's component in what gives a library its state. */
type HydrationWrapper = (component: VNode<unknown>) => VNode<unknown>;

/**
 * Whether an island is still hydrating: true until its first render in the
 * browser, the one that must match its markup, is done. And whether its
 * first render replaces its markup instead, since it can't use all of its
 * hydration data, so it would render something else.
 */
type Hydration = { hydrating: boolean; fresh: boolean };

/** What useSWR() returns, as far as the SWR consumer reads it. */
type SwrResponse = {
  data: unknown;
  error: unknown;
  isValidating: boolean;
  isLoading: boolean;
  mutate: unknown;
};

type SwrHook = (key: unknown, fetcher: unknown, config: unknown) => SwrResponse;

/** The SWR cache, as far as the SWR consumer uses it. */
type SwrCache = {
  get(key: string): { data?: unknown } | undefined;
  set(key: string, state: object): void;
};

/**
 * Consumers of hydration data, by the name of the library that provided it
 * on the server. Each loads its library through the page's import map, so the
 * wrapper it returns reaches the instance components import, and only for
 * islands that carry its data.
 */
const hydrationDataConsumers: Record<
  string,
  (data: unknown, hydration: Hydration) => Promise<HydrationWrapper>
> = {
  // Each key with what its fetcher resolved to, keyed as SWR keys it.
  //
  // SWR's fallback isn't enough: SWR reports a key whose data is only its
  // fallback as loading until it revalidates, and data another island put
  // in its cache wins over it. So while the island hydrates, a middleware
  // gives each key the state the server rendered with: its data, neither
  // loading nor validating. A key SWR's cache doesn't have yet is put there
  // as loaded, so SWR doesn't show a loading state for it. One it has is left
  // alone, and the hook renders again once mounted, to show the cache's.
  //
  // The server just fetched each key, so SWR doesn't fetch it again when the
  // hook mounts. It still does for a hook that sets revalidateOnMount, or
  // that has an onSuccess, which SWR calls only once a fetch the hook
  // started succeeds: a component that sets state from it would otherwise
  // wait for the next revalidation. The same goes for either set by an
  // SWRConfig. SWR reads revalidateOnMount only for a hook's first render,
  // and the rest of its config from its latest, so it then revalidates on
  // focus, reconnect, an interval, a mutate() or a new key as it would
  // without SSR.
  swr: async (data, hydration) => {
    if (!Array.isArray(data)) {
      throw new Error('SWR hydration data is not a list of keys and values.');
    }
    const { SWRConfig, unstable_serialize } = await import('swr');
    // What a hook's onSuccess is when nothing sets one.
    const noOnSuccess = (
      SWRConfig as unknown as { defaultValue: { onSuccess: unknown } }
    ).defaultValue.onSuccess;
    const served = new Map(
      (data as Array<[Parameters<typeof unstable_serialize>[0], unknown]>).map(
        ([key, value]) => [unstable_serialize(key), value],
      ),
    );
    const middleware =
      (useSWRNext: SwrHook): SwrHook =>
      (key, fetcher, config) => {
        const [, rerender] = useReducer((version: number) => version + 1, 0);
        const hid = useRef(false);
        useEffect(() => {
          if (hid.current) {
            rerender(undefined);
          }
        }, []);
        if (!hydration.hydrating) {
          return useSWRNext(key, fetcher, config);
        }
        const serialized = unstable_serialize(
          key as Parameters<typeof unstable_serialize>[0],
        );
        // SWR fetches nothing for a key it serializes as "".
        if (serialized === '' || !served.has(serialized)) {
          return useSWRNext(key, fetcher, config);
        }
        const value = served.get(serialized);
        const cache = (config as { cache: SwrCache }).cache;
        if (cache.get(serialized) === undefined) {
          cache.set(serialized, {
            data: value,
            isLoading: false,
            isValidating: false,
          });
        } else {
          hid.current = true;
        }
        const options = config as {
          revalidateOnMount?: boolean;
          onSuccess?: unknown;
        };
        const swr = useSWRNext(key, fetcher, {
          ...options,
          revalidateOnMount:
            options.revalidateOnMount ?? options.onSuccess !== noOnSuccess,
        });
        // SWR re-renders a hook only for the state its component read, so
        // each read still goes through to it.
        return {
          get data() {
            void swr.data;
            return value;
          },
          get error() {
            void swr.error;
            return undefined;
          },
          get isValidating() {
            void swr.isValidating;
            return false;
          },
          get isLoading() {
            void swr.isLoading;
            return false;
          },
          mutate: swr.mutate,
        };
      };
    const Config = SWRConfig as unknown as ComponentType<{ value: object }>;
    return (component) =>
      h(Config, { value: { use: [middleware] } }, component) as VNode<unknown>;
  },
};

/**
 * Each server-rendered island's wrappers, so its first render has the state
 * its markup was rendered with.
 */
const hydrationWrappers = new WeakMap<HTMLElement, HydrationWrapper[]>();

/**
 * Reads an island's hydration data, if it has any, into its wrappers.
 *
 * Data that can't be decoded, that no consumer here knows, or that its
 * consumer can't take, is left out with a warning, and the island renders
 * afresh: its markup was rendered from that data, so its first render here
 * would differ from it.
 *
 * @return
 *   Whether the island is hydrating, for its consumers, and whether it
 *   renders afresh.
 */
async function readHydrationData(element: HTMLElement): Promise<Hydration> {
  const hydration = { hydrating: true, fresh: false };
  const encoded = element.getAttribute(HYDRATION_DATA_ATTRIBUTE);
  if (encoded === null || hydrationWrappers.has(element)) {
    return hydration;
  }
  let data: unknown;
  try {
    const { parse } =
      await import('@drupal-canvas/astro-hydration/hydration-data');
    data = parse(encoded);
    if (typeof data !== 'object' || data === null) {
      throw new Error('It is not data by library name.');
    }
  } catch (e) {
    console.warn(
      'Canvas island hydration data could not be decoded, so the island renders afresh.',
      e,
    );
    hydrationWrappers.set(element, []);
    hydration.fresh = true;
    return hydration;
  }
  const libraries = Object.entries(data as object);
  const consumers = libraries.filter(([name]) =>
    Object.hasOwn(hydrationDataConsumers, name),
  );
  if (consumers.length !== libraries.length) {
    console.warn(
      'Canvas island hydration data is for a library no consumer here knows, so the island renders afresh.',
      libraries
        .map(([name]) => name)
        .filter((name) => !Object.hasOwn(hydrationDataConsumers, name)),
    );
    hydration.fresh = true;
  }
  const wrappers = await Promise.allSettled(
    consumers.map(([name, value]) =>
      hydrationDataConsumers[name](value, hydration),
    ),
  );
  hydrationWrappers.set(
    element,
    wrappers.flatMap((wrapper, i) => {
      if (wrapper.status === 'fulfilled') {
        return [wrapper.value];
      }
      console.warn(
        `Canvas island hydration data for ${consumers[i][0]} was left out, so the island renders afresh.`,
        wrapper.reason,
      );
      hydration.fresh = true;
      return [];
    }),
  );
  return hydration;
}

/**
 * One client per configuration and preview state, shared by every island on
 * the page while those stay unchanged.
 */
const clients = new Map<string, CanvasJsonApiClient>();

function getClient(preview: boolean): CanvasJsonApiClient | null {
  const config = drupalSettingsToJsonApiRuntimeConfig(readCanvasDataV0(), {
    preview,
  });
  if (config === null) {
    return null;
  }
  const key = JSON.stringify(config);
  let client = clients.get(key);
  if (!client) {
    // Drupal previews read working copies through the editor's own session:
    // same-origin requests carry the Drupal session cookie.
    client = createJsonApiClient({ ...config, credentials: 'same-origin' });
    clients.set(key, client);
  }
  return client;
}

/**
 * The event that announces updated `drupalSettings`; mounted islands re-read
 * the page and site data when it fires. Drupal's own settings updates (an
 * AJAX response merging settings and attaching behaviors) trigger it through
 * the behavior registered below; other code dispatches it on `window`.
 */
export const CANVAS_SETTINGS_UPDATED_EVENT = 'drupal-canvas:settings-updated';

const settingsListeners = new Set<() => void>();

/** Notifies every mounted island that `drupalSettings` changed. */
export function notifyCanvasSettingsUpdated(): void {
  for (const listener of settingsListeners) {
    listener();
  }
}

function subscribeToSettings(listener: () => void): () => void {
  settingsListeners.add(listener);
  return () => {
    settingsListeners.delete(listener);
  };
}

type DrupalGlobal = {
  behaviors?: Record<string, { attach?: (...args: unknown[]) => void }>;
  AjaxCommands?: {
    prototype: {
      settings?: (...args: unknown[]) => unknown;
    };
  };
};

const PATCHED = Symbol.for('drupal-canvas.settings-command');

/**
 * Connects to Drupal's settings lifecycle. The AJAX `settings` command is
 * where a response's settings reach `drupalSettings`; core attaches no
 * behavior for a settings-only response, so the command itself announces the
 * update after merging. Behavior attachment (after `insert` commands, which
 * merge settings first) announces it too. The command is patched when the
 * AJAX framework is present, re-checked on every attachment in case the
 * framework was loaded later.
 */
function connectToDrupal(): void {
  const drupal = (globalThis as { Drupal?: DrupalGlobal }).Drupal;
  if (!drupal) {
    return;
  }
  const commands = drupal.AjaxCommands?.prototype as
    | (DrupalGlobal['AjaxCommands'] extends infer T
        ? T extends { prototype: infer P }
          ? P & { [PATCHED]?: boolean }
          : never
        : never)
    | undefined;
  if (commands?.settings && !commands[PATCHED]) {
    const settings = commands.settings;
    commands.settings = function (this: unknown, ...args: unknown[]) {
      const result = settings.apply(this, args);
      notifyCanvasSettingsUpdated();
      return result;
    };
    commands[PATCHED] = true;
  }
  if (drupal.behaviors && !drupal.behaviors.canvasIslandSettings) {
    drupal.behaviors.canvasIslandSettings = {
      attach: () => {
        connectToDrupal();
        notifyCanvasSettingsUpdated();
      },
    };
  }
}

if (typeof window !== 'undefined') {
  window.addEventListener(CANVAS_SETTINGS_UPDATED_EVENT, () =>
    notifyCanvasSettingsUpdated(),
  );
  // The `canvas/astro.hydration` library depends on `core/drupal`, so the
  // Drupal object exists before this module (a deferred module script)
  // evaluates.
  connectToDrupal();
}

/**
 * Wrapped component types, one per island element and Code Component: the
 * wrapper reads the island's own preview state, so islands of the same
 * component on one page never share a wrapper, while a rerender of one
 * island keeps its component identity.
 */
const wrapped = new WeakMap<
  HTMLElement,
  WeakMap<
    ComponentType<Record<string, unknown>>,
    ComponentType<Record<string, unknown>>
  >
>();

/** Wraps a Code Component in the providers for one island element. */
export function wrapIslandComponent(
  Component: ComponentType<Record<string, unknown>>,
  element: HTMLElement,
): ComponentType<Record<string, unknown>> {
  let byComponent = wrapped.get(element);
  if (!byComponent) {
    byComponent = new WeakMap();
    wrapped.set(element, byComponent);
  }
  let Wrapped = byComponent.get(Component);
  if (Wrapped) {
    return Wrapped;
  }
  Wrapped = (props: Record<string, unknown>): VNode<unknown> => {
    // Re-render on settings updates, and read the settings on every render,
    // so a mounted island receives the current page and site data.
    const [, rerender] = useReducer((version: number) => version + 1, 0);
    useEffect(() => subscribeToSettings(() => rerender(undefined)), []);
    const context = drupalSettingsToCanvasContext(readCanvasDataV0());
    const client = getClient(element.hasAttribute(PREVIEW_ATTRIBUTE));
    const component = (hydrationWrappers.get(element) ?? []).reduce(
      (wrapped, wrap) => wrap(wrapped),
      h(Component, props) as VNode<unknown>,
    );
    return h(
      ContextProvider,
      { context },
      client === null ? component : h(ClientProvider, { client }, component),
    ) as VNode<unknown>;
  };
  Wrapped.displayName = `CanvasIsland(${Component.displayName ?? Component.name ?? 'Component'})`;
  byComponent.set(Component, Wrapped);
  return Wrapped;
}

const render: ClientRenderer = (element) => {
  const renderIsland = (preactRenderer as ClientRenderer)(element);
  return async (Component, props, slots, metadata) => {
    const hydration = await readHydrationData(element);
    try {
      return await renderIsland(
        wrapIslandComponent(Component, element),
        props,
        slots,
        // Astro's Preact renderer replaces the markup for client="only".
        hydration.fresh ? { ...metadata, client: 'only' } : metadata,
      );
    } finally {
      // Preact hydrates in one pass, so later renders are the browser's own.
      hydration.hydrating = false;
    }
  };
};

export default render;
