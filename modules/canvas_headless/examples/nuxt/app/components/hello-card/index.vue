<script setup lang="ts">
/**
 * A minimal Canvas code component. Its metadata lives in the sibling
 * component.yml, which the component metadata endpoint
 * (/api/canvas/components) exposes to the embedding Drupal Canvas site.
 * Drupal registers metadata only — the app renders the component itself,
 * so a .vue entry is as valid as a React one.
 */
defineProps<{
  title: string;
}>();
</script>

<template>
  <div class="rounded border border-gray-200 p-4">
    <h2 class="mb-2 text-lg font-semibold">{{ title }}</h2>
    <slot name="content" />
  </div>
</template>
