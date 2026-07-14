<script setup lang="ts">
/**
 * Catch-all page: resolves the current path through Drupal's routing via
 * the SDK's fetchPage() (proxied by this app's /api/page server route)
 * and renders proof of what came back — the raw payload. The Canvas custom elements
 * renderer is still in development, so this deliberately shows the API's
 * output rather than attempting a full page render.
 */
const route = useRoute();

const slug = computed(() =>
  Array.isArray(route.params.slug)
    ? route.params.slug.join('/')
    : (route.params.slug ?? ''),
);
const path = computed(
  () => `/${slug.value.split('/').map(encodeURIComponent).join('/')}`,
);

interface PageData {
  title?: string;
  content_format?: string;
  [key: string]: unknown;
}

const { data: page } = await useFetch<PageData | null>(
  () => `/api/page${path.value}`,
);

// The proxy route answers 404 for a missing page; carry that through to
// this document's own response during SSR.
if (import.meta.server && !page.value) {
  setResponseStatus(useRequestEvent()!, 404);
}

useHead({
  title: () => (page.value?.title ? String(page.value.title) : 'Not found'),
});
</script>

<template>
  <main class="mx-auto w-full max-w-2xl px-6 py-10">
    <p class="mb-6">
      <NuxtLink to="/" class="text-sm underline">← All content</NuxtLink>
    </p>
    <template v-if="page">
      <h1 class="mb-2 text-3xl font-bold">{{ page.title }}</h1>
      <p class="mb-6 text-sm text-gray-500">
        Resolved through Drupal's routing for
        <code class="rounded bg-gray-100 px-1">{{ path }}</code> — content
        format <code>{{ page.content_format }}</code>.
      </p>

      <h2 class="mt-8 mb-2 text-lg font-semibold">Raw page payload</h2>
      <pre class="overflow-x-auto rounded bg-gray-100 p-3 text-xs">{{
        JSON.stringify(page, null, 2)
      }}</pre>
    </template>
    <template v-else>
      <h1 class="mb-2 text-3xl font-bold">Not found</h1>
      <p class="text-sm text-gray-500">
        Drupal answered nothing for
        <code class="rounded bg-gray-100 px-1">{{ path }}</code>.
      </p>
    </template>
  </main>
</template>
