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

  it('swaps in a document once when it starts loading while another waits to hydrate', async () => {
    const store = makeStore();
    store.dispatch(setHtml(page('initial'), previewEditCount()));
    const { container } = render(
      <Provider store={store}>
        <Swapper />
      </Provider>,
    );
    await waitFor(() => expect(setIsReloading).toHaveBeenLastCalledWith(false));

    act(() => {
      store.dispatch(setHtml(page('next'), previewEditCount()));
    });
    const loadingFrame = container.querySelector<HTMLIFrameElement>(
      'iframe[data-canvas-swap-active="false"]',
    )!;
    // jsdom doesn't load srcdoc.
    const { body } = document.implementation.createHTMLDocument();
    body.innerHTML = '<template data-astro-template></template>';
    vi.spyOn(loadingFrame, 'contentDocument', 'get').mockReturnValue(
      body.ownerDocument,
    );
    act(() => {
      loadingFrame.dispatchEvent(new Event('load'));
    });
    await act(() => new Promise((resolve) => setTimeout(resolve, 20)));
    expect(loadingFrame.dataset.canvasSwapActive).toBe('false');

    // A newer render starts loading in that iframe while it waits.
    act(() => {
      store.dispatch(setHtml(page('newer'), previewEditCount()));
    });
    act(() => {
      loadingFrame.dispatchEvent(new Event('load'));
    });
    body.firstElementChild!.remove();
    await waitFor(() =>
      expect(loadingFrame.dataset.canvasSwapActive).toBe('true'),
    );
    // The wait of the render it replaced doesn't swap it out again.
    await act(() => new Promise((resolve) => setTimeout(resolve, 20)));
    expect(loadingFrame.dataset.canvasSwapActive).toBe('true');
    expect(loadingFrame.srcdoc).toContain('newer');
    expect(setIsReloading).toHaveBeenLastCalledWith(false);
  });

  it('compares a render with the one shown, not with an older one it skipped', async () => {
    const instances = (...texts: string[]) =>
      texts
        .map(
          (text) =>
            `<!-- canvas-start-${text[0]} --><p>${text}</p><!-- canvas-end-${text[0]} -->`,
        )
        .join('');
    const store = makeStore();
    store.dispatch(setHtml(page(instances('a', 'b')), previewEditCount()));
    const { container } = render(
      <Provider store={store}>
        <Swapper />
      </Provider>,
    );
    await waitFor(() => expect(setIsReloading).toHaveBeenLastCalledWith(false));
    const activeFrame = container.querySelector<HTMLIFrameElement>(
      'iframe[data-canvas-swap-active="true"]',
    )!;
    // jsdom doesn't load srcdoc.
    const { body } = document.implementation.createHTMLDocument();
    body.innerHTML = instances('a', 'b');
    vi.spyOn(activeFrame, 'contentDocument', 'get').mockReturnValue(
      body.ownerDocument,
    );
    const editsBeforeMove = previewEditCount();
    setIsReloading.mockClear();

    // A move shown ahead of the server.
    const texts = () =>
      Array.from(body.querySelectorAll('p'), (p) => p.textContent);
    const b = body.querySelectorAll('p')[1];
    body.prepend(...Array.from(body.childNodes).slice(3));
    notePreviewEdit();
    // A render requested before the move, with a changed prop, is skipped.
    act(() => {
      store.dispatch(setHtml(page(instances('a2', 'b')), editsBeforeMove));
    });
    expect(texts()).toEqual(['b', 'a']);

    // The render of the move has that prop too. Only it is updated.
    act(() => {
      store.dispatch(setHtml(page(instances('b', 'a2')), previewEditCount()));
    });
    expect(texts()).toEqual(['b', 'a2']);
    expect(body.querySelector('p')).toBe(b);
    expect(setIsReloading).not.toHaveBeenCalledWith(true);
  });
});
