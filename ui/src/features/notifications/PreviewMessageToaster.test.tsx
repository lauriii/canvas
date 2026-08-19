import { Provider } from 'react-redux';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, render, screen } from '@testing-library/react';

import { makeStore } from '@/app/store';

import { TOAST_DURATION } from './constants';
import { addPreviewMessages } from './previewMessagesSlice';
import PreviewMessageToaster from './PreviewMessageToaster';

describe('PreviewMessageToaster', () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('shows a preview message and dismisses it when it times out', () => {
    const store = makeStore();
    render(
      <Provider store={store}>
        <PreviewMessageToaster />
      </Provider>,
    );

    act(() => {
      store.dispatch(
        addPreviewMessages([{ type: 'warning', message: 'Not previewed.' }]),
      );
    });
    expect(screen.getByText('Not previewed.')).toBeInTheDocument();

    act(() => {
      vi.advanceTimersByTime(TOAST_DURATION);
    });
    expect(screen.queryByText('Not previewed.')).not.toBeInTheDocument();
  });

  it('does not stack a message that is already shown', () => {
    const store = makeStore();
    render(
      <Provider store={store}>
        <PreviewMessageToaster />
      </Provider>,
    );

    const message = [
      { type: 'warning', message: 'Repeated by every preview.' },
    ];
    act(() => {
      store.dispatch(addPreviewMessages(message));
      store.dispatch(addPreviewMessages(message));
    });

    expect(screen.getAllByText('Repeated by every preview.')).toHaveLength(1);
  });

  it('does not stack a message one response returned twice', () => {
    const store = makeStore();
    render(
      <Provider store={store}>
        <PreviewMessageToaster />
      </Provider>,
    );

    // Drupal repeats a message within one request when addMessage() is called
    // with $repeat = TRUE.
    act(() => {
      store.dispatch(
        addPreviewMessages([
          { type: 'warning', message: 'Returned twice.' },
          { type: 'warning', message: 'Returned twice.' },
        ]),
      );
    });

    expect(screen.getAllByText('Returned twice.')).toHaveLength(1);
  });
});
