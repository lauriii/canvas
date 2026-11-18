// Carry the ambient declaration of the virtual components module into any
// TypeScript program that includes this file. Consumer apps type-check this
// runtime source directly, without the package's tsconfig.
// eslint-disable-next-line @typescript-eslint/triple-slash-reference
/// <reference path="./virtual.d.ts" />

import canvasComponents from 'virtual:@drupal-canvas/headless/components';
import { computed, defineComponent, h } from 'vue';
import { convertLegacyCustomElementsFormat } from '@drupal-canvas/headless';
import { CanvasComponentTree as VueCanvasComponentTree } from '@drupal-canvas/json-render-vue';

import type { CanvasComponentTree } from '@drupal-canvas/headless';
import type { CanvasComponentRegistry } from '@drupal-canvas/json-render-vue';
import type { PropType } from 'vue';

export type { CanvasComponentRegistry } from '@drupal-canvas/json-render-vue';

/** Supplies Nuxt's generated component registry to the Vue renderer. */
export default defineComponent({
  name: 'CanvasComponentTree',
  props: {
    tree: {
      type: [Object, null] as PropType<CanvasComponentTree | null>,
      required: true,
    },
    components: {
      type: Object as PropType<CanvasComponentRegistry>,
      default: () => canvasComponents,
    },
    preview: {
      type: Boolean,
      default: false,
    },
  },
  setup(props) {
    const components = computed(() => props.components ?? canvasComponents);
    const tree = computed(() =>
      convertLegacyCustomElementsFormat(props.tree, components.value),
    );
    return () =>
      h(VueCanvasComponentTree, {
        tree: tree.value,
        components: components.value,
        preview: props.preview,
      });
  },
});
