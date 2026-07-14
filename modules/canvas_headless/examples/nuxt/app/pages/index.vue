<script setup lang="ts">
import { articlePath, canvasPagePath } from '#shared/content';

import type { ContentLists } from '#shared/content';

// Fetched through the app's own server route, where the draft session
// cookies live; useFetch forwards them during SSR.
const { data: content } = await useFetch<ContentLists>('/api/content');

const canvasPages = computed(() => content.value?.canvasPages ?? []);
const articles = computed(() => content.value?.articles ?? []);
</script>

<template>
  <main class="mx-auto w-full max-w-2xl px-6 py-10">
    <h1 class="mb-6 text-3xl font-bold">Canvas pages</h1>
    <p v-if="canvasPages.length === 0">No Canvas pages are visible.</p>
    <ul v-else class="space-y-3">
      <li v-for="page in canvasPages" :key="page.id">
        <NuxtLink :to="canvasPagePath(page)" class="underline">
          {{ page.attributes.title }}
        </NuxtLink>
        {{ ' ' }}
        <span class="text-xs text-gray-500">
          {{ page.attributes.status ? 'published' : 'unpublished' }}
        </span>
      </li>
    </ul>

    <h1 class="mt-10 mb-6 text-3xl font-bold">Articles</h1>
    <p v-if="articles.length === 0">No articles are visible.</p>
    <ul v-else class="space-y-3">
      <li v-for="article in articles" :key="article.id">
        <NuxtLink :to="articlePath(article)" class="underline">
          {{ article.attributes.title }}
        </NuxtLink>
        {{ ' ' }}
        <span class="text-xs text-gray-500">
          {{ article.attributes.status ? 'published' : 'unpublished' }}
        </span>
      </li>
    </ul>
  </main>
</template>
