import { describe, expect, it, vi } from 'vitest';

import { createAsyncRefreshQueue } from './refresh-queue';

describe('createAsyncRefreshQueue', () => {
  it('coalesces requests received during a refresh into one later pass', async () => {
    const resolvers: Array<() => void> = [];
    const refresh = vi.fn(
      () =>
        new Promise<void>((resolve) => {
          resolvers.push(resolve);
        }),
    );
    const queue = createAsyncRefreshQueue(refresh, vi.fn());

    const first = queue.request();
    const second = queue.request();
    const third = queue.request();

    expect(refresh).toHaveBeenCalledOnce();
    expect(first).toBe(second);
    expect(second).toBe(third);

    resolvers.shift()?.();
    await Promise.resolve();
    expect(refresh).toHaveBeenCalledTimes(2);

    resolvers.shift()?.();
    await first;
  });

  it('starts a new refresh after the previous queue drains', async () => {
    const refresh = vi.fn().mockResolvedValue(undefined);
    const queue = createAsyncRefreshQueue(refresh, vi.fn());

    await queue.request();
    await queue.request();

    expect(refresh).toHaveBeenCalledTimes(2);
  });

  it('starts a new drain for a request queued as the refresh settles', async () => {
    let resolveFirst: () => void = () => {};
    const firstRefresh = new Promise<void>((resolve) => {
      resolveFirst = resolve;
    });
    const refresh = vi
      .fn<() => Promise<void>>()
      .mockReturnValueOnce(firstRefresh)
      .mockResolvedValue(undefined);
    const queue = createAsyncRefreshQueue(refresh, vi.fn());

    const first = queue.request();
    let second: Promise<void> | undefined;
    void firstRefresh.then(() => {
      second = queue.request();
    });
    resolveFirst();

    await first;
    await vi.waitFor(() => expect(refresh).toHaveBeenCalledTimes(2));
    await expect(second).resolves.toBeUndefined();
  });

  it('reports a failed refresh and still processes a newer request', async () => {
    const error = new Error('Refresh failed');
    const resolvers: Array<{
      resolve: () => void;
      reject: (error: Error) => void;
    }> = [];
    const refresh = vi.fn(
      () =>
        new Promise<void>((resolve, reject) => {
          resolvers.push({ resolve, reject });
        }),
    );
    const onError = vi.fn();
    const queue = createAsyncRefreshQueue(refresh, onError);

    const first = queue.request();
    const second = queue.request();
    resolvers.shift()?.reject(error);

    await vi.waitFor(() => expect(refresh).toHaveBeenCalledTimes(2));
    expect(onError).toHaveBeenCalledExactlyOnceWith(error);

    resolvers.shift()?.resolve();
    await expect(first).resolves.toBeUndefined();
    await expect(second).resolves.toBeUndefined();
  });
});
