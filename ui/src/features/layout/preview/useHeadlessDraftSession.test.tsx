import { beforeEach, describe, expect, it, vi } from 'vitest';
import { act, renderHook } from '@testing-library/react';

import { useHeadlessDraftSession } from './useHeadlessDraftSession';

import type { RefObject } from 'react';
import type { HeadlessPreviewHostEvent } from '@drupal-canvas/headless-host';
import type { HeadlessSettings } from '@drupal-canvas/types';

const hostMocks = vi.hoisted(() => ({
  activate: vi.fn(),
  attach: vi.fn(),
  destroy: vi.fn(),
  refresh: vi.fn(),
  setViewportHeight: vi.fn(),
}));

const hostEvents = vi.hoisted(
  () => [] as Array<(event: HeadlessPreviewHostEvent) => void>,
);

vi.mock('@drupal-canvas/headless-host', () => ({
  CANVAS_COMPONENT_PREVIEW_PATH: '/api/canvas/component-preview',
  CANVAS_COMPONENT_PREVIEW_QUERY: 'componentId',
  createHeadlessPreviewHost: vi.fn(
    ({ onEvent }: { onEvent: (event: HeadlessPreviewHostEvent) => void }) => {
      hostEvents.push(onEvent);
      return hostMocks;
    },
  ),
}));

const settings: HeadlessSettings = {
  frontendUrl: 'https://frontend.example',
  frontends: ['https://frontend.example'],
  frontendOrigin: 'https://frontend.example',
  draftUrl: 'https://frontend.example/api/draft',
  assertionUrl: '/canvas-headless/assertion',
};

describe('useHeadlessDraftSession', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    hostEvents.length = 0;
  });

  it('waits for the main preview session before attaching a thumbnail', () => {
    const mainIframeRef = {
      current: document.createElement('iframe'),
    } as RefObject<HTMLIFrameElement>;
    const iframeRef = {
      current: document.createElement('iframe'),
    } as RefObject<HTMLIFrameElement>;

    const main = renderHook(() =>
      useHeadlessDraftSession(mainIframeRef, settings, 'canvas_page', '7'),
    );
    const thumbnail = renderHook(() =>
      useHeadlessDraftSession(
        iframeRef,
        settings,
        'canvas_page',
        '7',
        undefined,
        800,
        { componentPreviewId: 'js.example' },
      ),
    );

    expect(hostMocks.activate).toHaveBeenCalledWith({
      entity_type: 'canvas_page',
      entity: '7',
    });
    expect(hostMocks.attach).not.toHaveBeenCalled();

    act(() => {
      hostEvents[0]({ type: 'active', tokenExpiresAt: Date.now() + 60_000 });
    });

    expect(hostMocks.attach).toHaveBeenCalledWith(
      'https://frontend.example/api/canvas/component-preview?componentId=js.example',
    );
    expect(hostMocks.setViewportHeight).toHaveBeenCalledWith(800);

    thumbnail.unmount();
    main.unmount();
    expect(hostMocks.destroy).toHaveBeenCalledTimes(2);
  });

  it('attaches thumbnails below the configured frontend base path', () => {
    const settingsWithBasePath: HeadlessSettings = {
      ...settings,
      frontendUrl: 'https://frontend.example/app',
      draftUrl: 'https://frontend.example/app/api/draft',
    };
    const mainIframeRef = {
      current: document.createElement('iframe'),
    } as RefObject<HTMLIFrameElement>;
    const thumbnailIframeRef = {
      current: document.createElement('iframe'),
    } as RefObject<HTMLIFrameElement>;
    const main = renderHook(() =>
      useHeadlessDraftSession(
        mainIframeRef,
        settingsWithBasePath,
        'canvas_page',
        '7',
      ),
    );
    act(() => {
      hostEvents[0]({ type: 'active', tokenExpiresAt: Date.now() + 60_000 });
    });

    const thumbnail = renderHook(() =>
      useHeadlessDraftSession(
        thumbnailIframeRef,
        settingsWithBasePath,
        'canvas_page',
        '7',
        undefined,
        800,
        { componentPreviewId: 'js.example' },
      ),
    );

    expect(hostMocks.attach).toHaveBeenCalledWith(
      'https://frontend.example/app/api/canvas/component-preview?componentId=js.example',
    );
    thumbnail.unmount();
    main.unmount();
  });

  it('hides thumbnail geometry when the main preview becomes inactive', () => {
    const mainIframeRef = {
      current: document.createElement('iframe'),
    } as RefObject<HTMLIFrameElement>;
    const thumbnailIframeRef = {
      current: document.createElement('iframe'),
    } as RefObject<HTMLIFrameElement>;
    const main = renderHook(() =>
      useHeadlessDraftSession(mainIframeRef, settings, 'canvas_page', '7'),
    );

    act(() => {
      hostEvents[0]({ type: 'active', tokenExpiresAt: Date.now() + 60_000 });
    });

    const thumbnail = renderHook(() =>
      useHeadlessDraftSession(
        thumbnailIframeRef,
        settings,
        'canvas_page',
        '7',
        undefined,
        800,
        { componentPreviewId: 'js.example' },
      ),
    );
    const componentGeometry = [
      {
        type: 'component' as const,
        id: 'component-uuid',
        markerFormat: 'template' as const,
        rect: {
          top: 0,
          right: 100,
          bottom: 50,
          left: 0,
          width: 100,
          height: 50,
        },
      },
    ];

    act(() => {
      hostEvents[1]({ type: 'geometry', geometry: componentGeometry });
    });
    expect(thumbnail.result.current.geometry).toEqual(componentGeometry);

    act(() => {
      hostEvents[0]({ type: 'recovering' });
    });
    expect(thumbnail.result.current.geometry).toEqual([]);

    thumbnail.unmount();
    main.unmount();
  });

  it('stays attached while a replacement main preview is already active', () => {
    const firstMainIframeRef = {
      current: document.createElement('iframe'),
    } as RefObject<HTMLIFrameElement>;
    const secondMainIframeRef = {
      current: document.createElement('iframe'),
    } as RefObject<HTMLIFrameElement>;
    const thumbnailIframeRef = {
      current: document.createElement('iframe'),
    } as RefObject<HTMLIFrameElement>;
    const firstMain = renderHook(() =>
      useHeadlessDraftSession(firstMainIframeRef, settings, 'canvas_page', '7'),
    );
    const secondMain = renderHook(() =>
      useHeadlessDraftSession(
        secondMainIframeRef,
        settings,
        'canvas_page',
        '8',
      ),
    );

    act(() => {
      hostEvents[0]({ type: 'active', tokenExpiresAt: Date.now() + 60_000 });
      hostEvents[1]({ type: 'active', tokenExpiresAt: Date.now() + 60_000 });
    });

    const thumbnail = renderHook(() =>
      useHeadlessDraftSession(
        thumbnailIframeRef,
        settings,
        'canvas_page',
        '8',
        undefined,
        800,
        { componentPreviewId: 'js.example' },
      ),
    );
    expect(hostMocks.attach).toHaveBeenCalledOnce();

    vi.clearAllMocks();
    firstMain.unmount();

    // Only the old main host is destroyed. The thumbnail remains attached to
    // the session already reported by its replacement.
    expect(hostMocks.destroy).toHaveBeenCalledOnce();

    thumbnail.unmount();
    secondMain.unmount();
  });
});
