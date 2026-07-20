<script setup lang="ts">
/**
 * The Nuxt lifecycle around the draft session: fetches the session state
 * from the module's /api/draft/session route (in-process during SSR) and
 * hands it to the <canvas-draft-session> custom element from
 * @drupal-canvas/headless/client, which owns the machine (expiry timing,
 * the renewal protocol with the embedding host, re-arming after a
 * renewal). Renders nothing when draft mode is off. The element's `path`
 * attribute is bound to the route, so client-side navigations keep the
 * host's status reports and the renew link on the current page. Auto-save
 * refresh events use Nuxt's refreshNuxtData() instead of reloading the page.
 *
 * The slot owns presentation: children marked
 * `data-draft-session-view="active"` show while the session is live and
 * the page is standalone (embedded, the host owns the session chrome);
 * `data-draft-session-view="expired"` children show once the session has
 * expired, embedded or not. A `data-draft-session-renew-link` anchor gets
 * its href pointed at Drupal's renew route for the current path. A
 * headless page that only needs the renewal protocol leaves the slot
 * empty.
 */
import { onMounted } from 'vue';
import { refreshNuxtData, useFetch, useRoute } from 'nuxt/app';
import {
  defineDraftSessionElement,
  DRAFT_SESSION_REFRESH_EVENT,
} from '@drupal-canvas/headless/client';

import type { DraftSessionState } from '../server/routes/session-state';

const props = withDefaults(
  defineProps<{
    /**
     * The app route that redeems a fresh assertion into the session; align
     * with the module's injectRoutes: false custom mounting when used.
     */
    renewEndpoint?: string;
    /** The module's session-state route (always registered). */
    sessionEndpoint?: string;
  }>(),
  {
    renewEndpoint: '/api/draft/renew',
    sessionEndpoint: '/api/draft/session',
  },
);

const route = useRoute();
const { data: session } = await useFetch<DraftSessionState>(
  props.sessionEndpoint,
);

/** Refreshes every active useFetch/useAsyncData source after an auto-save. */
function refreshDraftData(event: Event): void {
  event.preventDefault();
  void refreshNuxtData();
}

onMounted(() => {
  defineDraftSessionElement();
});
</script>

<template>
  <canvas-draft-session
    v-if="session?.enabled"
    :token-expires-at="session.tokenExpiresAt ?? undefined"
    :initial-expired="session.expired || undefined"
    :renew-url="session.renewUrl ?? undefined"
    :editor-origin="session.editorOrigin ?? undefined"
    :renew-endpoint="renewEndpoint"
    :path="route.path"
    @[DRAFT_SESSION_REFRESH_EVENT]="refreshDraftData"
  >
    <slot />
  </canvas-draft-session>
</template>

<style>
/* Until the element connects and applies the visibility rules, every
   state view stays hidden — the server cannot know whether the page is
   embedded, so first paint shows nothing, matching the client-decided
   Next.js banner. The [hidden] rule keeps the element's toggling
   authoritative over display properties the app's own styles set. */
canvas-draft-session:not(:defined) [data-draft-session-view],
canvas-draft-session [hidden] {
  display: none !important;
}
</style>
