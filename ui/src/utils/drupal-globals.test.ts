import { beforeEach, describe, expect, it, vi } from 'vitest';

import type { DrupalSettings } from '@drupal-canvas/types';

const loadGlobals = async (
  canvas: Partial<DrupalSettings['canvas']>,
  baseUrl = '/',
) => {
  vi.resetModules();
  vi.doUnmock('@/utils/drupal-globals');
  Object.assign(window, {
    Drupal: {},
    drupalSettings: { canvas, path: { baseUrl } },
  });
  return import('@/utils/drupal-globals');
};

describe('setCanvasHeadlessFrontend', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    window.localStorage.clear();
  });

  it('updates the active headless frontend without reloading the page', async () => {
    const canvas: Partial<DrupalSettings['canvas']> = {};
    const { setCanvasHeadlessFrontend } = await loadGlobals(canvas);

    setCanvasHeadlessFrontend('HTTPS://FRONTEND.EXAMPLE:443/app');

    expect(canvas.headless).toEqual({
      frontendUrl: 'https://frontend.example/app',
      frontends: ['https://frontend.example/app'],
      frontendOrigin: 'https://frontend.example',
      draftUrl: 'https://frontend.example/app/api/draft',
      assertionUrl: '/canvas-headless/assertion',
    });
    expect(window.localStorage.getItem('canvas-headless-active-frontend')).toBe(
      'https://frontend.example/app',
    );
  });

  it('preserves the configured frontend list when switching', async () => {
    const canvas: Partial<DrupalSettings['canvas']> = {
      headless: {
        frontendUrl: 'https://first.example',
        frontends: ['https://first.example', 'https://second.example'],
        frontendOrigin: 'https://first.example',
        draftUrl: 'https://first.example/api/draft',
        assertionUrl: '/canvas-headless/assertion',
      },
    };
    const { setCanvasHeadlessFrontend } = await loadGlobals(canvas);

    setCanvasHeadlessFrontend('https://second.example');

    expect(canvas.headless?.frontendUrl).toBe('https://second.example');
    expect(canvas.headless?.frontends).toEqual([
      'https://first.example',
      'https://second.example',
    ]);
  });

  it('preserves the active frontend when the configured list changes', async () => {
    const canvas: Partial<DrupalSettings['canvas']> = {
      headless: {
        frontendUrl: 'https://second.example',
        frontends: ['https://first.example', 'https://second.example'],
        frontendOrigin: 'https://second.example',
        draftUrl: 'https://second.example/api/draft',
        assertionUrl: '/canvas-headless/assertion',
      },
    };
    const { setCanvasHeadlessFrontends } = await loadGlobals(canvas);

    setCanvasHeadlessFrontends([
      'https://second.example',
      'https://first.example',
      'https://third.example',
    ]);

    expect(canvas.headless?.frontendUrl).toBe('https://second.example');
    expect(canvas.headless?.frontends).toEqual([
      'https://second.example',
      'https://first.example',
      'https://third.example',
    ]);
  });

  it('selects the first frontend when the active frontend is removed', async () => {
    const canvas: Partial<DrupalSettings['canvas']> = {
      headless: {
        frontendUrl: 'https://removed.example',
        frontends: ['https://first.example', 'https://removed.example'],
        frontendOrigin: 'https://removed.example',
        draftUrl: 'https://removed.example/api/draft',
        assertionUrl: '/canvas-headless/assertion',
      },
    };
    const { setCanvasHeadlessFrontends } = await loadGlobals(canvas);

    setCanvasHeadlessFrontends([
      'https://first.example',
      'https://second.example',
    ]);

    expect(canvas.headless?.frontendUrl).toBe('https://first.example');
    expect(window.localStorage.getItem('canvas-headless-active-frontend')).toBe(
      'https://first.example',
    );
  });

  it('restores the last selected configured frontend', async () => {
    const canvas: Partial<DrupalSettings['canvas']> = {
      headless: {
        frontendUrl: 'https://first.example',
        frontends: ['https://first.example', 'https://second.example/app'],
        frontendOrigin: 'https://first.example',
        draftUrl: 'https://first.example/api/draft',
        assertionUrl: '/canvas-headless/assertion',
      },
    };
    const { restoreCanvasHeadlessFrontend } = await loadGlobals(canvas);
    window.localStorage.setItem(
      'canvas-headless-active-frontend',
      'https://second.example/app',
    );

    restoreCanvasHeadlessFrontend();

    expect(canvas.headless?.frontendUrl).toBe('https://second.example/app');
    expect(canvas.headless?.draftUrl).toBe(
      'https://second.example/app/api/draft',
    );
  });

  it('discards a stored frontend that is no longer configured', async () => {
    const canvas: Partial<DrupalSettings['canvas']> = {
      headless: {
        frontendUrl: 'https://first.example',
        frontends: ['https://first.example'],
        frontendOrigin: 'https://first.example',
        draftUrl: 'https://first.example/api/draft',
        assertionUrl: '/canvas-headless/assertion',
      },
    };
    const { restoreCanvasHeadlessFrontend } = await loadGlobals(canvas);
    window.localStorage.setItem(
      'canvas-headless-active-frontend',
      'https://removed.example',
    );

    restoreCanvasHeadlessFrontend();

    expect(canvas.headless?.frontendUrl).toBe('https://first.example');
    expect(
      window.localStorage.getItem('canvas-headless-active-frontend'),
    ).toBeNull();
  });

  it('uses Drupal’s base URL for the assertion endpoint', async () => {
    const canvas: Partial<DrupalSettings['canvas']> = {};
    const { setCanvasHeadlessFrontend } = await loadGlobals(canvas, '/drupal/');

    setCanvasHeadlessFrontend('https://frontend.example');

    expect(canvas.headless?.assertionUrl).toBe(
      '/drupal/canvas-headless/assertion',
    );
  });

  it('disables headless preview when the last frontend is removed', async () => {
    const canvas: Partial<DrupalSettings['canvas']> = {
      headless: {
        frontendUrl: 'https://frontend.example',
        frontends: ['https://frontend.example'],
        frontendOrigin: 'https://frontend.example',
        draftUrl: 'https://frontend.example/api/draft',
        assertionUrl: '/canvas-headless/assertion',
      },
    };
    const { setCanvasHeadlessFrontend } = await loadGlobals(canvas);
    window.localStorage.setItem(
      'canvas-headless-active-frontend',
      'https://frontend.example',
    );

    setCanvasHeadlessFrontend();

    expect(canvas.headless).toBeUndefined();
    expect(
      window.localStorage.getItem('canvas-headless-active-frontend'),
    ).toBeNull();
  });
});
