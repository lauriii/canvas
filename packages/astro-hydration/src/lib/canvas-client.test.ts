import { useJsonApiClient, usePageContext } from 'drupal-canvas/react';
import { h, render } from 'preact';
import { act } from 'preact/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
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
