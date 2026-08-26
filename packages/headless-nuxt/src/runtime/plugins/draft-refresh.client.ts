import {
  createAsyncRefreshQueue,
  DRAFT_SESSION_REFRESH_EVENT,
} from '@drupal-canvas/headless/client';

import type { NuxtApp } from 'nuxt/app';

/** Refreshes the consuming Nuxt application's data after a Canvas auto-save. */
export default function canvasDraftRefresh(nuxtApp: NuxtApp): void {
  const queue = createAsyncRefreshQueue(
    async () => {
      await nuxtApp.hooks.callHookParallel('app:data:refresh');
    },
    (error) =>
      console.error('[canvas] Failed to refresh Nuxt draft data.', error),
  );

  document.addEventListener(DRAFT_SESSION_REFRESH_EVENT, (event) => {
    event.preventDefault();
    void queue.request();
  });
}
