import { describe, expect, it, vi } from 'vitest';

import { addPreviewMessages } from '@/features/notifications/previewMessagesSlice';
import { withPreviewMessages } from '@/services/baseQuery';

import type { BaseQueryApi } from '@reduxjs/toolkit/query/react';

const api = (dispatch: ReturnType<typeof vi.fn>) =>
  ({ dispatch }) as unknown as BaseQueryApi;

describe('withPreviewMessages', () => {
  it('dispatches the messages a preview response returns', async () => {
    const dispatch = vi.fn();
    const messages = [{ type: 'status', message: 'Not previewed.' }];
    const wrapped = withPreviewMessages(async () => ({
      data: { html: '<p></p>', messages },
    }));

    await wrapped('canvas/api/v0/layout/node/1', api(dispatch), {});

    // Not toHaveBeenCalledWith: each notification gets a generated id.
    expect(dispatch).toHaveBeenCalledTimes(1);
    const action = dispatch.mock.calls[0][0];
    expect(action.type).toBe(addPreviewMessages.type);
    expect(action.payload).toMatchObject([
      { type: 'info', message: 'Not previewed.' },
    ]);
  });

  it.each([
    ['a response without messages', { data: { html: '<p></p>' } }],
    ['an empty message list', { data: { html: '<p></p>', messages: [] } }],
    ['a non-object response', { data: 'plain text' }],
    ['an error response', { error: { status: 500, data: null } }],
  ])('dispatches nothing for %s', async (_name, result) => {
    const dispatch = vi.fn();
    const wrapped = withPreviewMessages(async () => result as never);

    await wrapped('canvas/api/v0/config/component', api(dispatch), {});

    expect(dispatch).not.toHaveBeenCalled();
  });
});
