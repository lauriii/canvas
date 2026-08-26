<script setup lang="ts">
import { createError } from 'h3';
import { useAsyncData, useRequestEvent, useRoute } from 'nuxt/app';
import { CANVAS_COMPONENT_PREVIEW_QUERY } from '@drupal-canvas/headless';
import { isPageRedirect } from '@drupal-canvas/headless/server';

import CanvasComponentTree from '../components/CanvasComponentTree';
import { fetchComponentPreview, getDraftData } from '../server/session';

const route = useRoute();
const rawComponentId = route.query[CANVAS_COMPONENT_PREVIEW_QUERY];
const componentId = typeof rawComponentId === 'string' ? rawComponentId : null;
if (!componentId) {
  throw createError({ statusCode: 404 });
}
// The request event exists only during SSR. useAsyncData carries that result
// in the Nuxt payload so hydration does not replace the preview with an empty
// client-side render.
const { data: page } = await useAsyncData(
  `canvas-component-preview:${componentId}`,
  async () => {
    const event = useRequestEvent();
    if (!event || !(await getDraftData(event))) {
      return null;
    }
    const result = await fetchComponentPreview(event, componentId);
    return result && !isPageRedirect(result) ? result : null;
  },
);
if (!page.value) {
  throw createError({ statusCode: 404 });
}
</script>

<template>
  <main data-canvas-component-preview-document>
    <CanvasComponentTree :tree="page?.content ?? null" />
  </main>
</template>

<style>
html,
body {
  margin: 0;
  background: white;
}

[data-canvas-component-preview-document] {
  position: fixed;
  inset: 0;
  z-index: 2147483646;
  overflow: auto;
  background: white;
}

nuxt-devtools {
  display: none !important;
}
</style>
