import { useJsonApiClient, usePageContext } from 'drupal-canvas/react';
import { h, render } from 'preact';
import { useState } from 'preact/hooks';
import { act } from 'preact/test-utils';
import useSWR from 'swr';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { stringify } from '@drupal-canvas/astro-hydration/hydration-data';

import renderer, {
  CANVAS_SETTINGS_UPDATED_EVENT,
  notifyCanvasSettingsUpdated,
  wrapIslandComponent,
} from './canvas-client';

import type { ComponentType } from 'preact';

type Settings = {
  canvasData: { v0: Record<string, unknown> };
};

function setSettings(v0: Record<string, unknown>): void {
  (globalThis as { drupalSettings?: Settings }).drupalSettings = {
    canvasData: { v0 },
  };
}

/** A Code Component reading both hooks. */
const Probe: ComponentType<Record<string, unknown>> = () => {
  const page = usePageContext();
  const client = useJsonApiClient();
  return h(
    'p',
    null,
    `${page?.pageTitle ?? 'no-page'}|${
      client
        ? `${client.runtimeConfig.resourceVersion ?? 'default'}:${client.runtimeConfig.preview}`
        : 'no-client'
    }`,
  );
};

function island(preview: boolean): HTMLElement {
  const element = document.createElement('canvas-island');
  if (preview) {
    element.setAttribute('data-canvas-preview', 'true');
  }
  document.body.append(element);
  return element;
}

describe('canvas island client renderer', () => {
  beforeEach(() => {
    setSettings({
      baseUrl: 'https://drupal.example',
      jsonapiSettings: { apiPrefix: 'jsonapi' },
      pageTitle: 'First title',
      breadcrumbs: [],
      mainEntity: null,
    });
  });

  afterEach(() => {
    document.body.innerHTML = '';
  });

  it('configures each island from its own preview state, whichever mounted first', () => {
    const publicIsland = island(false);
    const previewIsland = island(true);
    render(h(wrapIslandComponent(Probe, publicIsland), {}), publicIsland);
    render(h(wrapIslandComponent(Probe, previewIsland), {}), previewIsland);
    expect(publicIsland.textContent).toBe('First title|default:false');
    expect(previewIsland.textContent).toBe('First title|rel:working-copy:true');
    // Same island, same component: the wrapper identity is stable.
    expect(wrapIslandComponent(Probe, publicIsland)).toBe(
      wrapIslandComponent(Probe, publicIsland),
    );
    expect(wrapIslandComponent(Probe, publicIsland)).not.toBe(
      wrapIslandComponent(Probe, previewIsland),
    );
  });

  describe('hydration data', () => {
    // Hydrates an island the way Astro does: server markup in place, then
    // the client renderer.
    const hydrate = async (
      Component: ComponentType<Record<string, unknown>>,
      markup: string,
      hydrationData: string | null,
    ) => {
      const element = island(false);
      element.setAttribute('ssr', '');
      if (hydrationData !== null) {
        element.setAttribute('hydration-data', hydrationData);
      }
      element.innerHTML = markup;
      const serverRendered = element.firstChild;
      await act(() => renderer(element)(Component, {}, {}, { client: 'load' }));
      return { element, serverRendered };
    };

    // Each render's text, so a test sees the first render, not only the
    // last, and each key it fetched. The fetcher never settles, so only
    // hydration data gives data.
    const articles = (
      key: string,
      renders: string[],
      fetched: unknown[] = [],
      options: { revalidateOnMount?: boolean; onSuccess?: () => void } = {},
    ) => {
      const Articles: ComponentType<Record<string, unknown>> = () => {
        const { data, isLoading, isValidating } = useSWR<
          Array<{ title: string }>
        >(
          [key, {}],
          (fetchedKey: unknown) => {
            fetched.push(fetchedKey);
            return new Promise(() => {});
          },
          options,
        );
        const text = isLoading
          ? 'Loading'
          : `${data?.[0]?.title}${isValidating ? ' (refreshing)' : ''}`;
        renders.push(text);
        return h('p', null, text);
      };
      return Articles;
    };

    // SWR revalidates a key that has data on the next animation frame.
    const nextFrame = () =>
      act(() => new Promise<void>((resolve) => setTimeout(resolve, 50)));

    const swrData = (key: string, title: string) =>
      stringify({ swr: [[[key, {}], [{ title }]]] });

    it('hydrates with what the server rendered, never the loading state', async () => {
      const renders: string[] = [];
      const fetched: unknown[] = [];
      const { element, serverRendered } = await hydrate(
        articles('first-render', renders, fetched),
        '<p>Newest Article</p>',
        swrData('first-render', 'Newest Article'),
      );
      // The server's markup, with SWR's state as the server had it: data,
      // and neither loading nor validating.
      expect(renders[0]).toBe('Newest Article');
      expect(renders).not.toContain('Loading');
      // Hydration kept the server's node, rather than rendering another.
      expect(element.firstChild).toBe(serverRendered);
      // The server just fetched it, so the browser doesn't.
      await nextFrame();
      expect(fetched).toEqual([]);
      expect(renders).not.toContain('Loading');
      expect(element.textContent).toBe('Newest Article');
    });

    it('fetches on mount a key the server did not carry, or one whose hook asks or has an onSuccess', async () => {
      const fetched: unknown[] = [];
      const Both: ComponentType<Record<string, unknown>> = () =>
        h('div', null, [
          h(articles('carried', [], fetched), {}),
          h(articles('not-carried', [], fetched), {}),
          h(
            articles('carried-asked', [], fetched, {
              revalidateOnMount: true,
            }),
            {},
          ),
          h(
            articles('carried-on-success', [], fetched, {
              onSuccess: () => {},
            }),
            {},
          ),
        ]);
      await hydrate(
        Both,
        '<div><p>Carried</p><p>Loading</p><p>Asked</p><p>Told</p></div>',
        stringify({
          swr: [
            [['carried', {}], [{ title: 'Carried' }]],
            [['carried-asked', {}], [{ title: 'Asked' }]],
            [['carried-on-success', {}], [{ title: 'Told' }]],
          ],
        }),
      );
      await nextFrame();
      expect(fetched).toEqual([
        ['not-carried', {}],
        ['carried-asked', {}],
        ['carried-on-success', {}],
      ]);
    });

    it('revalidates a carried key after mount as SWR would without SSR', async () => {
      const fetched: unknown[] = [];
      let setKey: (key: string) => void = () => {};
      const Switching: ComponentType<Record<string, unknown>> = () => {
        const [key, set] = useState('later');
        setKey = set;
        return h(articles(key, [], fetched), {});
      };
      const { element } = await hydrate(
        Switching,
        '<p>Later Article</p>',
        swrData('later', 'Later Article'),
      );
      await nextFrame();
      expect(fetched).toEqual([]);
      // On focus, once SWR's focus throttle has passed since mount.
      const now = Date.now();
      const clock = vi.spyOn(Date, 'now').mockReturnValue(now + 10_000);
      window.dispatchEvent(new Event('focus'));
      await nextFrame();
      clock.mockRestore();
      expect(fetched).toEqual([['later', {}]]);
      expect(element.textContent).toBe('Later Article (refreshing)');
      // And for a new key.
      await act(() => setKey('other'));
      expect(fetched).toEqual([
        ['later', {}],
        ['other', {}],
      ]);
    });

    it('hydrates with data JSON cannot carry', async () => {
      // A JSON:API resource deserialized into objects that refer to each
      // other, with a date.
      const article: Record<string, unknown> = {
        title: 'Dated Article',
        changed: new Date('2026-11-16T12:00:00Z'),
      };
      article.author = { name: 'Writer', articles: [article] };
      let served: Record<string, unknown> | undefined;
      const Dated: ComponentType<Record<string, unknown>> = () => {
        const { data } = useSWR<Array<Record<string, unknown>>>(
          ['dated', {}],
          () => new Promise(() => {}),
        );
        served = data?.[0];
        return h('p', null, String(served?.title));
      };
      const { element, serverRendered } = await hydrate(
        Dated,
        '<p>Dated Article</p>',
        stringify({ swr: [[['dated', {}], [article]]] }),
      );
      expect(element.firstChild).toBe(serverRendered);
      expect(served?.changed).toEqual(article.changed);
      expect((served?.author as { articles: unknown[] }).articles[0]).toBe(
        served,
      );
    });

    it('gives each island only its own hydration data', async () => {
      const renders: string[] = [];
      const { element } = await hydrate(
        articles('unseeded', renders),
        '<p>Loading</p>',
        null,
      );
      expect(renders[0]).toBe('Loading');
      expect(element.textContent).toBe('Loading');
    });

    it("hydrates with the island's own data when another island's is already in SWR's cache", async () => {
      const first: string[] = [];
      await hydrate(
        articles('shared', first),
        '<p>Newer Article</p>',
        swrData('shared', 'Newer Article'),
      );
      await nextFrame();
      const second: string[] = [];
      const { element, serverRendered } = await hydrate(
        articles('shared', second),
        '<p>Older Article</p>',
        swrData('shared', 'Older Article'),
      );
      // Its markup was rendered from its own data, which the cache doesn't
      // have, so the first render takes that.
      expect(second[0]).toBe('Older Article');
      expect(element.firstChild).toBe(serverRendered);
      // Then it shows what the cache has.
      await nextFrame();
      expect(element.textContent).toBe('Newer Article');
    });

    it('renders afresh, rather than hydrating, without hydration data it cannot read', async () => {
      for (const hydrationData of [
        '{',
        stringify(5),
        stringify({ swr: 5 }),
        // The server rendered from data for a library the browser doesn't
        // know, so the library starts without it.
        stringify({
          swr: [[['unknown-library', {}], [{ title: 'Server Article' }]]],
          unknown: true,
        }),
      ]) {
        const renders: string[] = [];
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
        const { element, serverRendered } = await hydrate(
          articles(`unreadable-${hydrationData}`, renders),
          '<p>Server Article</p>',
          hydrationData,
        );
        // The server's markup is replaced, not left beside the browser's.
        expect(element.innerHTML).toBe('<p>Loading</p>');
        expect(element.firstChild).not.toBe(serverRendered);
        expect(warn).toHaveBeenCalled();
        warn.mockRestore();
      }
    });
  });

  it('re-renders islands after Drupal merges settings from an AJAX response', async () => {
    // Drupal's AJAX `settings` command, as core implements it for a
    // settings-only response: merge, no behavior attachment.
    class AjaxCommands {
      settings(
        _ajax: unknown,
        response: { settings: Record<string, unknown>; merge: boolean },
      ) {
        const target = (globalThis as unknown as { drupalSettings: Settings })
          .drupalSettings;
        if (response.merge) {
          Object.assign(target.canvasData.v0, response.settings);
        }
      }
    }
    const drupal = {
      behaviors: {} as Record<string, { attach: () => void }>,
      AjaxCommands,
    };
    (globalThis as { Drupal?: unknown }).Drupal = drupal;
    // The `canvas/astro.hydration` library depends on `core/drupal`, so the
    // module evaluates with Drupal present: load it fresh in that state.
    vi.resetModules();
    const [preact, hooks, canvas, client] = await Promise.all([
      import('preact'),
      import('preact/test-utils'),
      import('drupal-canvas/react'),
      import('./canvas-client'),
    ]);
    const FreshProbe: ComponentType<Record<string, unknown>> = () =>
      preact.h('p', null, canvas.usePageContext()?.pageTitle ?? 'no-page');
    expect(drupal.behaviors.canvasIslandSettings).toBeDefined();
    const element = island(false);
    await hooks.act(() => {
      preact.render(
        preact.h(client.wrapIslandComponent(FreshProbe, element), {}),
        element,
      );
    });
    expect(element.textContent).toBe('First title');

    await hooks.act(() => {
      new AjaxCommands().settings(null, {
        settings: { pageTitle: 'AJAX title' },
        merge: true,
      });
    });
    expect(element.textContent).toBe('AJAX title');
    // Behavior attachment (after an `insert` command) announces it too.
    (
      globalThis as unknown as { drupalSettings: Settings }
    ).drupalSettings.canvasData.v0.pageTitle = 'Attached title';
    await hooks.act(() => {
      drupal.behaviors.canvasIslandSettings.attach();
    });
    expect(element.textContent).toBe('Attached title');
    delete (globalThis as { Drupal?: unknown }).Drupal;
    vi.resetModules();
  });

  it('refreshes mounted islands when the settings update is announced', async () => {
    const element = island(false);
    // Flush the effects so the island's subscription is in place.
    await act(() => {
      render(h(wrapIslandComponent(Probe, element), {}), element);
    });
    expect(element.textContent).toBe('First title|default:false');

    setSettings({
      baseUrl: 'https://drupal.example',
      jsonapiSettings: { apiPrefix: 'jsonapi' },
      pageTitle: 'Updated title',
      breadcrumbs: [],
      mainEntity: null,
    });
    // Nothing changes until an update is announced.
    expect(element.textContent).toBe('First title|default:false');
    await act(() => {
      notifyCanvasSettingsUpdated();
    });
    expect(element.textContent).toBe('Updated title|default:false');

    setSettings({
      baseUrl: 'https://drupal.example',
      jsonapiSettings: { apiPrefix: 'jsonapi' },
      pageTitle: 'Event title',
      breadcrumbs: [],
      mainEntity: null,
    });
    await act(() => {
      window.dispatchEvent(new Event(CANVAS_SETTINGS_UPDATED_EVENT));
    });
    expect(element.textContent).toBe('Event title|default:false');
  });
});
