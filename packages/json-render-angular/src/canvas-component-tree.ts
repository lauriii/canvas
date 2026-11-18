import { NgComponentOutlet } from '@angular/common';
import {
  Component,
  computed,
  DestroyRef,
  Directive,
  forwardRef,
  inject,
  InjectionToken,
  Injector,
  input,
} from '@angular/core';
import { DomSanitizer } from '@angular/platform-browser';
import {
  areCanvasComponentTreeChildrenEmpty,
  CANVAS_EMPTY_REGION_PLACEHOLDER_CLASS,
  CANVAS_EMPTY_SLOT_PLACEHOLDER_CLASS,
  CANVAS_MARKER_ELEMENT,
  CANVAS_PREVIEW_CONTENT_REGION_ELEMENT,
  CANVAS_SLOT_PLACEHOLDER_ELEMENT,
  findCanvasComponent,
  getCanvasComponentTreeElementSlots,
  getCanvasTemplateMarkerAttributes,
  getJsonRenderComponentData,
  isCanvasComponentTreeSlotEmpty,
  prepareCanvasJsonRender,
  reportMissingCanvasComponent,
  reportMissingCanvasComponentUuid,
  reportMissingCanvasMarkerComponentUuid,
} from '@drupal-canvas/json-render/renderer';

import type { Type } from '@angular/core';
import type {
  CanvasComponentTree as CanvasComponentTreeDocument,
  CanvasComponentTreeElement,
} from '@drupal-canvas/json-render';
import type { CanvasMarker } from '@drupal-canvas/json-render/renderer';

/** Standalone Angular components, keyed by component.yml machine name or exact wire type. */
export type CanvasComponentRegistry = Record<string, Type<unknown>>;
interface SlotContext {
  tree: () => CanvasComponentTreeDocument;
  node: () => CanvasComponentTreeElement;
  elementKey: () => string;
  components: () => CanvasComponentRegistry;
  editor: () => boolean;
  path: () => string;
}
const SLOT_CONTEXT = new InjectionToken<SlotContext>('Canvas slot context');
const contents = { style: 'display: contents' };

/** Template elements are inert, layout-neutral boundaries understood by shared geometry. */
@Directive({
  selector: 'template[canvasMarker]',
  host: {
    '[attr.data-canvas-marker]': 'attributes()["data-canvas-marker"]',
    '[attr.data-canvas-type]': 'attributes()["data-canvas-type"]',
    '[attr.data-canvas-uuid]': 'attributes()["data-canvas-uuid"] ?? null',
    '[attr.data-canvas-slot-name]':
      'attributes()["data-canvas-slot-name"] ?? null',
    '[attr.data-canvas-region-id]':
      'attributes()["data-canvas-region-id"] ?? null',
  },
})
export class CanvasMarkerDirective {
  readonly canvasMarker = input.required<CanvasMarker>();
  protected readonly attributes = computed<Record<string, string | undefined>>(
    () => ({ ...getCanvasTemplateMarkerAttributes(this.canvasMarker()) }),
  );
}

@Component({
  selector: 'canvas-markup',
  host: contents,
  template:
    '<span style="display: contents" [innerHTML]="trustedHtml()"></span>',
})
export class CanvasMarkup {
  readonly html = input.required<string>();
  private readonly sanitizer = inject(DomSanitizer);
  // Only Drupal's trusted rendered HTML belongs here, never untrusted user input.
  protected readonly trustedHtml = computed(() =>
    this.sanitizer.bypassSecurityTrustHtml(this.html()),
  );
}

/** Resolves ordered element references through the prepared document. */
@Component({
  selector: 'canvas-children',
  host: contents,
  imports: [forwardRef(() => CanvasElement)],
  template: `
    @for (childKey of children(); track childKey) {
      <canvas-element
        [tree]="tree()"
        [elementKey]="childKey"
        [components]="components()"
        [editor]="editor()"
        [path]="path() + ':' + $index"
      />
    }
  `,
})
export class CanvasChildren {
  readonly tree = input.required<CanvasComponentTreeDocument>();
  readonly children = input.required<string[]>();
  readonly components = input.required<CanvasComponentRegistry>();
  readonly editor = input(false);
  readonly path = input('tree');
}

/**
 * Render a Canvas slot within an app component's own Angular view.
 * No projectableNodes, detached DOM, extra app inputs, or hydration opt-outs.
 */
@Component({
  selector: 'canvas-slot',
  host: contents,
  imports: [CanvasChildren, CanvasMarkerDirective],
  template: `
    @if (id(); as id) {
      <template
        [canvasMarker]="{ position: 'start', type: 'slot', id }"
      ></template>
    }
    @if (id() && empty()) {
      <div aria-hidden="true" [class]="emptyClass"></div>
    }
    <canvas-children
      [tree]="context.tree()"
      [children]="children()"
      [components]="context.components()"
      [editor]="context.editor()"
      [path]="context.path() + ':' + name()"
    />
    @if (id(); as id) {
      <template
        [canvasMarker]="{ position: 'end', type: 'slot', id }"
      ></template>
    }
  `,
})
export class CanvasSlot {
  readonly name = input('default');
  protected readonly context = inject(SLOT_CONTEXT);
  protected readonly emptyClass = CANVAS_EMPTY_SLOT_PLACEHOLDER_CLASS;
  protected readonly slot = computed(
    () =>
      getCanvasComponentTreeElementSlots(this.context.node()).find(
        ([name]) => name === this.name(),
      )?.[1],
  );
  protected readonly empty = computed(() =>
    isCanvasComponentTreeSlotEmpty(this.context.tree(), this.slot()),
  );
  protected readonly children = computed(() =>
    this.context.editor() && this.empty() ? [] : (this.slot() ?? []),
  );
  protected readonly id = computed(() => {
    const uuid = getJsonRenderComponentData(
      this.context.node(),
      this.context.components(),
      this.context.elementKey(),
    )?.componentUuid;
    return this.context.editor() && uuid ? `${uuid}/${this.name()}` : null;
  });
}

/** Renders a referenced component, trusted markup, or structural element. */
@Component({
  selector: 'canvas-element',
  host: contents,
  imports: [
    NgComponentOutlet,
    CanvasChildren,
    CanvasMarkerDirective,
    CanvasMarkup,
  ],
  template: `
    @if (!slotPlaceholder()) {
      @if (region()) {
        <template
          [canvasMarker]="{ position: 'start', type: 'region', id: 'content' }"
        ></template>
        @if (empty()) {
          <div aria-hidden="true" [class]="emptyClass"></div>
        }
      }
      @if (componentId(); as id) {
        <template
          [canvasMarker]="{ position: 'start', type: 'component', id }"
        ></template>
      }
      @if (node().type === 'drupal-markup') {
        @if (markup(); as html) {
          <canvas-markup [html]="html" />
        }
      } @else if (data(); as data) {
        @if (implementation(); as implementation) {
          <ng-container
            *ngComponentOutlet="
              implementation;
              inputs: data.props;
              injector: slotInjector
            "
          />
        }
      } @else {
        <canvas-children
          [tree]="tree()"
          [children]="children()"
          [components]="components()"
          [editor]="editor()"
          [path]="path()"
        />
      }
      @if (componentId(); as id) {
        <template
          [canvasMarker]="{ position: 'end', type: 'component', id }"
        ></template>
      }
      @if (region()) {
        <template
          [canvasMarker]="{ position: 'end', type: 'region', id: 'content' }"
        ></template>
      }
    }
  `,
})
export class CanvasElement {
  readonly tree = input.required<CanvasComponentTreeDocument>();
  readonly elementKey = input.required<string>();
  readonly components = input.required<CanvasComponentRegistry>();
  readonly editor = input(false);
  readonly path = input('tree');
  protected readonly node = computed(
    () => this.tree().elements[this.elementKey()],
  );
  protected readonly data = computed(() => {
    const node = this.node();
    if (
      [
        CANVAS_MARKER_ELEMENT,
        CANVAS_PREVIEW_CONTENT_REGION_ELEMENT,
        CANVAS_SLOT_PLACEHOLDER_ELEMENT,
        'drupal-markup',
      ].includes(node.type)
    ) {
      return null;
    }
    return getJsonRenderComponentData(
      node,
      this.components(),
      this.elementKey(),
    );
  });
  protected readonly componentId = computed(() => {
    if (!this.editor()) return undefined;
    if (this.node().type === CANVAS_MARKER_ELEMENT) {
      const uuid = this.elementKey();
      if (!uuid) reportMissingCanvasMarkerComponentUuid(this.path());
      return uuid;
    }
    return this.implementation() ? this.data()?.componentUuid : undefined;
  });
  protected readonly region = computed(
    () =>
      this.editor() &&
      this.node().type === CANVAS_PREVIEW_CONTENT_REGION_ELEMENT,
  );
  protected readonly slotPlaceholder = computed(
    () => this.node().type === CANVAS_SLOT_PLACEHOLDER_ELEMENT,
  );
  protected readonly markup = computed(() => {
    const node = this.node();
    return node.type === 'drupal-markup' &&
      typeof node.props.markup === 'string'
      ? node.props.markup
      : null;
  });
  protected readonly empty = computed(() =>
    areCanvasComponentTreeChildrenEmpty(this.tree(), this.children()),
  );
  protected readonly emptyClass = CANVAS_EMPTY_REGION_PLACEHOLDER_CLASS;
  protected readonly children = computed(() =>
    getCanvasComponentTreeElementSlots(this.node()).flatMap(([, keys]) => keys),
  );
  protected readonly implementation = computed(() => {
    const data = this.data();
    if (!data) return null;
    const component = findCanvasComponent(this.components(), data);
    if (!component) reportMissingCanvasComponent(data, this.path());
    else if (this.editor() && !data.componentUuid)
      reportMissingCanvasComponentUuid(data, this.path());
    return component ?? null;
  });
  protected readonly slotInjector = Injector.create({
    parent: inject(Injector),
    providers: [
      {
        provide: SLOT_CONTEXT,
        useValue: {
          elementKey: this.elementKey,
          tree: this.tree,
          node: this.node,
          components: this.components,
          editor: this.editor,
          path: this.path,
        } satisfies SlotContext,
      },
    ],
  });
  constructor() {
    inject(DestroyRef).onDestroy(() => this.slotInjector.destroy());
  }
}

/** Render a validated Canvas document, with editor boundaries enabled explicitly. */
@Component({
  selector: 'canvas-component-tree',
  host: contents,
  imports: [CanvasChildren, CanvasMarkerDirective],
  template: `
    @if (prepared().needsOuterRegion) {
      <template
        [canvasMarker]="{ position: 'start', type: 'region', id: 'content' }"
      ></template>
      @if (prepared().emptyRegion) {
        <div aria-hidden="true" [class]="emptyClass"></div>
      }
    }
    @if (prepared().spec; as spec) {
      <canvas-children
        [tree]="spec"
        [children]="spec.root ? [spec.root] : []"
        [components]="components()"
        [editor]="preview()"
      />
    }
    @if (prepared().needsOuterRegion) {
      <template
        [canvasMarker]="{ position: 'end', type: 'region', id: 'content' }"
      ></template>
    }
  `,
})
export class CanvasComponentTree {
  readonly tree = input.required<CanvasComponentTreeDocument | null>();
  readonly components = input.required<CanvasComponentRegistry>();
  readonly preview = input(false);
  protected readonly prepared = computed(() =>
    prepareCanvasJsonRender(this.tree(), { preview: this.preview() }),
  );
  protected readonly emptyClass = CANVAS_EMPTY_REGION_PLACEHOLDER_CLASS;
}
