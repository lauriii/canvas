// @vitest-environment jsdom
import { describe, expect, it, vi } from 'vitest';
import { DRAFT_SESSION_REFRESH_EVENT } from '@drupal-canvas/headless/client';

import canvasDraftRefresh from './draft-refresh.client';

import type { NuxtApp } from 'nuxt/app';

describe('canvasDraftRefresh', () => {
  it('refreshes the consuming Nuxt app after a draft refresh request', async () => {
    const callHookParallel = vi.fn().mockResolvedValue([]);
    canvasDraftRefresh({
      hooks: { callHookParallel },
    } as unknown as NuxtApp);
    const event = new Event(DRAFT_SESSION_REFRESH_EVENT, {
      cancelable: true,
    });

    document.dispatchEvent(event);

    expect(event.defaultPrevented).toBe(true);
    await vi.waitFor(() =>
      expect(callHookParallel).toHaveBeenCalledExactlyOnceWith(
        'app:data:refresh',
      ),
    );
  });
});
