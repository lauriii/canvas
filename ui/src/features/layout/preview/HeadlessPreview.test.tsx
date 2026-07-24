import { Provider } from 'react-redux';
import { MemoryRouter, Route, Routes, useNavigate } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import { act, render, screen, waitFor } from '@testing-library/react';

import { makeStore } from '@/app/store';
import { setViewportMinHeight, setViewportWidth } from '@/features/ui/uiSlice';

import HeadlessPreview from './HeadlessPreview';

import type { HeadlessPreviewHostEvent } from '@drupal-canvas/headless-host';
import type { HeadlessSettings } from '@drupal-canvas/types';

let latestOnHeight: ((height: number) => void) | undefined;
let navigateTo: ReturnType<typeof useNavigate> | undefined;
const setViewportHeight = vi.fn();
const PREVIEW_HEIGHT_PROPERTY = '--canvas-headless-preview-height';

vi.mock('@drupal-canvas/headless-host', () => ({
  createHeadlessPreviewHost: vi.fn(
    ({
      onEvent,
      onHeight,
    }: {
      onEvent?: (event: HeadlessPreviewHostEvent) => void;
      onHeight?: (height: number) => void;
    }) => {
      latestOnHeight = onHeight;
      return {
        activate: vi.fn().mockResolvedValue(undefined),
        destroy: vi.fn(),
        refresh: vi.fn(),
        setViewportHeight,
      };
    },
  ),
}));

const NavigationBridge = () => {
  navigateTo = useNavigate();
  return null;
};

const SETTINGS: HeadlessSettings = {
  frontendUrl: 'http://localhost:3000',
  frontends: ['http://localhost:3000'],
  frontendOrigin: 'http://localhost:3000',
  draftUrl: 'http://localhost:3000/api/draft',
  assertionUrl: '/canvas-headless/assertion',
};

function renderPreview(viewportMinHeight: number) {
  const store = makeStore();
  store.dispatch(setViewportWidth(800));
  store.dispatch(setViewportMinHeight(viewportMinHeight));

  render(
    <Provider store={store}>
      <MemoryRouter initialEntries={['/node/1']}>
        <NavigationBridge />
        <Routes>
          <Route
            path="/:entityType/:entityId"
            element={<HeadlessPreview settings={SETTINGS} autoSavesHash={{}} />}
          />
        </Routes>
      </MemoryRouter>
    </Provider>,
  );

  return store;
}

function getPreviewHeight(element: HTMLElement) {
  return {
    declaration: element.style.height,
    value: element.style.getPropertyValue(PREVIEW_HEIGHT_PROPERTY),
  };
}

describe('HeadlessPreview', () => {
  it('falls back to viewportMinHeight before any height report arrives', () => {
    renderPreview(500);
    const iframe = screen.getByTestId('canvas-headless-iframe');
    expect(getPreviewHeight(iframe)).toEqual({
      declaration: `var(${PREVIEW_HEIGHT_PROPERTY})`,
      value: '500px',
    });
  });

  it('follows reported content height when it exceeds viewportMinHeight', () => {
    renderPreview(500);
    act(() => latestOnHeight?.(1200));

    const iframe = screen.getByTestId('canvas-headless-iframe');
    expect(getPreviewHeight(iframe)).toEqual({
      declaration: `var(${PREVIEW_HEIGHT_PROPERTY})`,
      value: '1200px',
    });
  });

  it('floors the iframe height at viewportMinHeight for shorter content', () => {
    renderPreview(500);
    act(() => latestOnHeight?.(200));

    const iframe = screen.getByTestId('canvas-headless-iframe');
    expect(getPreviewHeight(iframe)).toEqual({
      declaration: `var(${PREVIEW_HEIGHT_PROPERTY})`,
      value: '500px',
    });
  });

  it('keeps the previous frame height until the next page reports its height', () => {
    renderPreview(500);
    act(() => latestOnHeight?.(1200));

    act(() => navigateTo?.('/node/2'));

    expect(screen.getByTestId('canvas-headless-viewport')).toHaveStyle({
      height: '1200px',
    });
    expect(
      getPreviewHeight(screen.getByTestId('canvas-headless-iframe')),
    ).toEqual({
      declaration: `var(${PREVIEW_HEIGHT_PROPERTY})`,
      value: '1200px',
    });
    expect(
      getPreviewHeight(screen.getByTestId('canvas-headless-pending-iframe')),
    ).toEqual({
      declaration: `var(${PREVIEW_HEIGHT_PROPERTY})`,
      value: '500px',
    });

    act(() => latestOnHeight?.(300));

    expect(screen.getByTestId('canvas-headless-viewport')).toHaveStyle({
      height: '500px',
    });
    expect(
      screen.queryByTestId('canvas-headless-pending-iframe'),
    ).not.toBeInTheDocument();
  });

  it('keeps a height committed while the host temporarily probes the iframe', () => {
    renderPreview(500);
    const iframe = screen.getByTestId('canvas-headless-iframe');
    const heightDeclaration = iframe.style.height;

    iframe.style.height = '1500px';
    act(() => latestOnHeight?.(1200));
    iframe.style.height = heightDeclaration;

    expect(getPreviewHeight(iframe)).toEqual({
      declaration: `var(${PREVIEW_HEIGHT_PROPERTY})`,
      value: '1200px',
    });
  });

  it('sends device viewport-height changes to the embedded app', async () => {
    const store = renderPreview(500);
    await waitFor(() => expect(setViewportHeight).toHaveBeenCalledWith(500));

    act(() => {
      store.dispatch(setViewportMinHeight(800));
    });

    await waitFor(() => expect(setViewportHeight).toHaveBeenCalledWith(800));
    expect(
      getPreviewHeight(screen.getByTestId('canvas-headless-iframe')),
    ).toEqual({
      declaration: `var(${PREVIEW_HEIGHT_PROPERTY})`,
      value: '800px',
    });
  });
});
