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
  CANVAS_EMPTY_REGION_PLACEHOLDER_CLASS,
  CANVAS_EMPTY_SLOT_PLACEHOLDER_CLASS,
  CANVAS_PREVIEW_CONTENT_REGION_ELEMENT,
  findCanvasComponent,
  getCanvasComponentRenderData,
  getCanvasTemplateMarkerAttributes,
  hasCanvasPreviewContentRegion,
  isCanvasComponentTreeDraft,
  isCanvasComponentTreeEmpty,
  isCanvasComponentTreeSlotEmpty,
  normalizeCanvasComponentTreeSlot,
  reportMissingCanvasComponent,
  reportMissingCanvasComponentUuid,
} from '@drupal-canvas/headless';

import type { Type } from '@angular/core';
import type {
  CanvasComponentTreeElement,
  CanvasMarker,
} from '@drupal-canvas/headless';

export {
  CanvasPageStore,
  CanvasDocumentHead,
  CanvasDraftSession,
  canvasPageResolver,
  provideCanvas,
} from './application';
export type {
  CanvasSession,
  CanvasPageData,
  CanvasRequestContext,
  CanvasEntityOptions,
} from '@drupal-canvas/headless-angular/contracts';

/** Standalone Angular components, keyed by unchanged component.yml machine names. */
export type CanvasComponentRegistry = Record<string, Type<unknown>>;
type Child = string | CanvasComponentTreeElement;
interface SlotContext {
  node: () => CanvasComponentTreeElement;
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

@Component({
  selector: 'canvas-children',
  host: contents,
  imports: [CanvasMarkup, forwardRef(() => CanvasElement)],
  template: `
    @for (child of children(); track key(child, $index)) {
      @if (isMarkup(child)) {
        <canvas-markup [html]="child" />
      } @else {
        <canvas-element
          [node]="child"
          [components]="components()"
          [editor]="editor()"
          [path]="path() + ':' + $index"
        />
      }
    }
  `,
})
export class CanvasChildren {
  readonly children = input.required<Child[]>();
  readonly components = input.required<CanvasComponentRegistry>();
  readonly editor = input(false);
  readonly path = input('tree');
  protected isMarkup(child: Child): child is string {
    return typeof child === 'string';
  }
  protected key(child: Child, index: number): string | number {
    return typeof child === 'string'
      ? index
      : (getCanvasComponentRenderData(child)?.componentUuid ?? index);
  }
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
    () => this.context.node().slots?.[this.name()],
  );
  protected readonly empty = computed(() =>
    isCanvasComponentTreeSlotEmpty(this.slot()),
  );
  protected readonly children = computed(() =>
    this.context.editor() && this.empty()
      ? []
      : normalizeCanvasComponentTreeSlot(this.slot()),
  );
  protected readonly id = computed(() => {
    const uuid = getCanvasComponentRenderData(
      this.context.node(),
    )?.componentUuid;
    return this.context.editor() && uuid ? `${uuid}/${this.name()}` : null;
  });
}

@Component({
  selector: 'canvas-element',
  host: contents,
  imports: [NgComponentOutlet, CanvasChildren, CanvasMarkerDirective],
  template: `
    @if (region()) {
      <template
        [canvasMarker]="{ position: 'start', type: 'region', id: 'content' }"
      ></template>
      @if (empty()) {
        <div aria-hidden="true" [class]="emptyClass"></div>
      }
    }
    @if (data(); as data) {
      @if (implementation(); as implementation) {
        @if (componentId(); as id) {
          <template
            [canvasMarker]="{ position: 'start', type: 'component', id }"
          ></template>
        }
        <ng-container
          *ngComponentOutlet="
            implementation;
            inputs: data.props;
            injector: slotInjector
          "
        />
        @if (componentId(); as id) {
          <template
            [canvasMarker]="{ position: 'end', type: 'component', id }"
          ></template>
        }
      }
    } @else {
      <canvas-children
        [children]="children()"
        [components]="components()"
        [editor]="editor()"
        [path]="path()"
      />
    }
    @if (region()) {
      <template
        [canvasMarker]="{ position: 'end', type: 'region', id: 'content' }"
      ></template>
    }
  `,
})
export class CanvasElement {
  readonly node = input.required<CanvasComponentTreeElement>();
  readonly components = input.required<CanvasComponentRegistry>();
  readonly editor = input(false);
  readonly path = input('tree');
  protected readonly data = computed(() =>
    getCanvasComponentRenderData(this.node()),
  );
  protected readonly componentId = computed(() =>
    this.editor() ? this.data()?.componentUuid : undefined,
  );
  protected readonly region = computed(
    () =>
      this.editor() &&
      this.node().element === CANVAS_PREVIEW_CONTENT_REGION_ELEMENT,
  );
  protected readonly empty = computed(() =>
    isCanvasComponentTreeEmpty(this.node()),
  );
  protected readonly emptyClass = CANVAS_EMPTY_REGION_PLACEHOLDER_CLASS;
  protected readonly children = computed(() =>
    Object.values(this.node().slots ?? {}).flatMap(
      normalizeCanvasComponentTreeSlot,
    ),
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

/** Render only trusted Canvas output. Draft mode comes exclusively from the wire tree. */
@Component({
  selector: 'canvas-component-tree',
  host: contents,
  imports: [CanvasChildren, CanvasMarkerDirective],
  template: `
    @if (region()) {
      <template
        [canvasMarker]="{ position: 'start', type: 'region', id: 'content' }"
      ></template>
      @if (empty()) {
        <div aria-hidden="true" [class]="emptyClass"></div>
      }
    }
    <canvas-children
      [children]="children()"
      [components]="components()"
      [editor]="editor()"
    />
    @if (region()) {
      <template
        [canvasMarker]="{ position: 'end', type: 'region', id: 'content' }"
      ></template>
    }
  `,
})
export class CanvasComponentTree {
  readonly tree = input.required<CanvasComponentTreeElement | null>();
  readonly components = input.required<CanvasComponentRegistry>();
  protected readonly editor = computed(() =>
    isCanvasComponentTreeDraft(this.tree()),
  );
  protected readonly region = computed(
    () => this.editor() && !hasCanvasPreviewContentRegion(this.tree()),
  );
  protected readonly empty = computed(() =>
    isCanvasComponentTreeEmpty(this.tree()),
  );
  protected readonly emptyClass = CANVAS_EMPTY_REGION_PLACEHOLDER_CLASS;
  protected readonly children = computed(() => {
    const tree = this.tree();
    return tree ? [tree] : [];
  });
}
