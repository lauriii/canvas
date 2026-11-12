import { Provider } from 'react-redux';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, render, waitFor } from '@testing-library/react';

import { useAppSelector } from '@/app/hooks';
import { makeStore } from '@/app/store';
import IframeSwapper from '@/features/layout/preview/IframeSwapper';
import {
  notePreviewEdit,
  previewEditCount,
} from '@/features/layout/preview/previewEdits';
import {
  selectPreviewHtml,
  setHtml,
} from '@/features/pagePreview/previewSlice';

const page = (text: string) =>
  `<!DOCTYPE html><html><head></head><body><p>${text}</p></body></html>`;

const setIsReloading = vi.fn();

function Swapper() {
  return (
    <IframeSwapper
      srcDocument={useAppSelector(selectPreviewHtml)}
      headStyles=""
      setIsReloading={setIsReloading}
      setInPlaceUpdates={() => {}}
      interactive={false}
    />
  );
}

describe('IframeSwapper', () => {
  beforeEach(() => {
    vi.stubGlobal('requestAnimationFrame', (cb: FrameRequestCallback) =>
      setTimeout(() => cb(performance.now()), 0),
    );
  });
  afterEach(() => vi.unstubAllGlobals());

  it('swaps in a loading document when an older render arrives meanwhile', async () => {
    const store = makeStore();
    store.dispatch(setHtml(page('initial'), previewEditCount()));
    const { container } = render(
      <Provider store={store}>
        <Swapper />
      </Provider>,
    );
    const activeFrame = () =>
      container.querySelector<HTMLIFrameElement>(
        'iframe[data-canvas-swap-active="true"]',
      )!;
    await waitFor(() => expect(setIsReloading).toHaveBeenLastCalledWith(false));
    const editsBeforeEdit = previewEditCount();

    // An edit shown in the preview; its render starts loading.
    notePreviewEdit();
    act(() => {
      store.dispatch(setHtml(page('newer'), previewEditCount()));
    });
    expect(setIsReloading).toHaveBeenLastCalledWith(true);
    // A render requested before the edit arrives while that one loads.
    act(() => {
      store.dispatch(setHtml(page('older'), editsBeforeEdit));
    });

    // jsdom doesn't fire load when srcdoc changes, so fire it as a browser
    // does once the loading document is parsed.
    const loadingFrame = container.querySelector<HTMLIFrameElement>(
      'iframe[data-canvas-swap-active="false"]',
    )!;
    expect(loadingFrame.srcdoc).toContain('newer');
    act(() => {
      loadingFrame.dispatchEvent(new Event('load'));
    });

    await waitFor(() => expect(activeFrame().srcdoc).toContain('newer'));
    expect(setIsReloading).toHaveBeenLastCalledWith(false);
  });
});
