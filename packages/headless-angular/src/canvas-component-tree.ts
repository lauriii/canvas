import { NgComponentOutlet } from '@angular/common';
import { Component, computed, input } from '@angular/core';
import { convertLegacyCustomElementsFormat } from '@drupal-canvas/headless';
import { CanvasComponentTree as JsonRenderCanvasComponentTree } from '@drupal-canvas/json-render-angular';

import type { CanvasComponentTree as CanvasComponentTreeDocument } from '@drupal-canvas/headless';
import type { CanvasComponentRegistry } from '@drupal-canvas/json-render-angular';

/**
 * Renders a Canvas content API document with the app's component registry.
 *
 * @todo Remove this wrapper and re-export `CanvasComponentTree` from `@drupal-canvas/json-render-angular` in `index.ts` when Canvas 1.13 is no longer supported.
 */
@Component({
  selector: 'canvas-component-tree',
  host: { style: 'display: contents' },
  imports: [NgComponentOutlet],
  template: `
    <ng-container *ngComponentOutlet="renderer; inputs: rendererInputs()" />
  `,
})
export class CanvasComponentTree {
  readonly tree = input.required<CanvasComponentTreeDocument | null>();
  readonly components = input.required<CanvasComponentRegistry>();
  readonly preview = input(false);
  protected readonly renderer = JsonRenderCanvasComponentTree;
  protected readonly rendererInputs = computed(() => ({
    tree: convertLegacyCustomElementsFormat(this.tree(), this.components()),
    components: this.components(),
    preview: this.preview(),
  }));
}
