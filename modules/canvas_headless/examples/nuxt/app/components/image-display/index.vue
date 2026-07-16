<script setup lang="ts">
interface CanvasImage {
  src: string;
  alt: string;
  width: number;
  height: number;
}

defineProps<{
  image: CanvasImage;
}>();
</script>

<template>
  <img
    class="h-auto w-full rounded-2xl md:col-span-2"
    :src="image.src"
    :alt="image.alt"
    :width="image.width"
    :height="image.height"
  />
</template>
