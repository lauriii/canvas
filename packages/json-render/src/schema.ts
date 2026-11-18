/**
 * @file
 * Drupal Canvas json-render schema, inferred types, validation, and catalog helpers.
 */

import { z } from 'zod';
import { defineSchema } from '@json-render/core';

import {
  CANVAS_MARKER_ELEMENT,
  CANVAS_PREVIEW_CONTENT_REGION_ELEMENT,
  CANVAS_SLOT_PLACEHOLDER_ELEMENT,
  DRUPAL_MARKUP_ELEMENT,
  RENDERLESS_CONTAINER_ELEMENT,
} from './constants';

/** Any JSON value accepted in component props. */
export const JsonValueSchema = z.json();

/** An object containing component props. */
export const CanvasJsonRenderPropsSchema = z.record(
  z.string(),
  JsonValueSchema,
);

/** Ordered element keys in one named slot. */
export const CanvasComponentTreeSlotSchema = z.array(z.string());

/** One element in a Canvas json-render document. */
export const CanvasComponentTreeElementSchema = z.strictObject({
  type: z.string(),
  props: CanvasJsonRenderPropsSchema,
  children: CanvasComponentTreeSlotSchema,
  slots: z.record(z.string(), CanvasComponentTreeSlotSchema).optional(),
});

const CanvasComponentTreeStructureSchema = z.strictObject({
  root: z.string(),
  elements: z.record(z.string(), CanvasComponentTreeElementSchema),
});

/**
 * A Canvas json-render document with valid root and element references.
 *
 * An empty document uses an empty root and elements map. Every non-empty root,
 * child, and slot reference must resolve to an element. Element references must
 * not form a cycle.
 */
export const CanvasComponentTreeSchema =
  CanvasComponentTreeStructureSchema.superRefine((tree, context) => {
    if (tree.root === '') {
      if (Object.keys(tree.elements).length > 0) {
        context.addIssue({
          code: 'custom',
          message: 'An empty root requires an empty elements map.',
          path: ['root'],
        });
      }
    } else if (!Object.hasOwn(tree.elements, tree.root)) {
      context.addIssue({
        code: 'custom',
        message: `Root element "${tree.root}" does not exist.`,
        path: ['root'],
      });
    }

    for (const [key, element] of Object.entries(tree.elements)) {
      addMissingReferenceIssues(
        tree.elements,
        element.children,
        ['elements', key, 'children'],
        context,
      );
      for (const [slotName, childKeys] of Object.entries(element.slots ?? {})) {
        addMissingReferenceIssues(
          tree.elements,
          childKeys,
          ['elements', key, 'slots', slotName],
          context,
        );
      }
    }

    addCycleIssues(tree.elements, context);
  });

/** A value that can be represented in a json-render document. */
export type JsonValue = z.infer<typeof JsonValueSchema>;

/** Ordered element keys in one named slot. */
export type CanvasComponentTreeSlot = z.infer<
  typeof CanvasComponentTreeSlotSchema
>;

/** One element in a Canvas json-render document. */
export type CanvasComponentTreeElement = z.infer<
  typeof CanvasComponentTreeElementSchema
>;

/** A flat json-render document returned by the Canvas content API. */
export type CanvasComponentTree = z.infer<typeof CanvasComponentTreeSchema>;

type CanvasComponentTreeElements = CanvasComponentTree['elements'];
type ValidationContext = Parameters<
  Parameters<typeof CanvasComponentTreeStructureSchema.superRefine>[0]
>[1];

/** Adds validation issues for element keys that do not exist. */
function addMissingReferenceIssues(
  elements: CanvasComponentTreeElements,
  references: string[],
  path: PropertyKey[],
  context: ValidationContext,
): void {
  references.forEach((reference, index) => {
    if (!Object.hasOwn(elements, reference)) {
      context.addIssue({
        code: 'custom',
        message: `Referenced element "${reference}" does not exist.`,
        path: [...path, index],
      });
    }
  });
}

/** Adds one validation issue for each element-reference cycle. */
function addCycleIssues(
  elements: CanvasComponentTreeElements,
  context: ValidationContext,
): void {
  const state = new Map<string, 'visiting' | 'visited'>();

  const visit = (key: string): void => {
    state.set(key, 'visiting');
    const element = elements[key];
    if (!element) {
      state.set(key, 'visited');
      return;
    }

    const references: Array<{ key: string; path: PropertyKey[] }> = [
      ...element.children.map((childKey, index) => ({
        key: childKey,
        path: ['elements', key, 'children', index],
      })),
      ...Object.entries(element.slots ?? {}).flatMap(([slotName, childKeys]) =>
        childKeys.map((childKey, index) => ({
          key: childKey,
          path: ['elements', key, 'slots', slotName, index],
        })),
      ),
    ];

    for (const reference of references) {
      if (!Object.hasOwn(elements, reference.key)) {
        continue;
      }
      if (state.get(reference.key) === 'visiting') {
        context.addIssue({
          code: 'custom',
          message: `Element reference to "${reference.key}" creates a cycle.`,
          path: reference.path,
        });
        continue;
      }
      if (state.get(reference.key) !== 'visited') {
        visit(reference.key);
      }
    }

    state.set(key, 'visited');
  };

  for (const key of Object.keys(elements)) {
    if (!state.has(key)) {
      visit(key);
    }
  }
}

/**
 * Canvas's catalog-aware json-render schema.
 *
 * The document intentionally excludes json-render state, expressions,
 * visibility, repetition, and actions. Canvas content contains component props
 * and default or named slot references only.
 */
export const canvasJsonRenderSchema = defineSchema((s) => ({
  spec: s.object({
    root: s.string(),
    elements: s.record(
      s.object({
        type: s.ref('catalog.components'),
        props: s.propsOf('catalog.components'),
        children: s.array(s.string()),
        slots: { ...s.record(s.array(s.string())), ...s.optional() },
      }),
    ),
  }),
  catalog: s.object({
    components: s.map({
      props: s.zod(),
      slots: s.array(s.string()),
      description: s.string(),
      example: s.any(),
    }),
  }),
}));

/** One component definition accepted by a Canvas json-render catalog. */
export interface CanvasJsonRenderCatalogComponent {
  props: z.ZodType;
  slots?: string[];
  description?: string;
  example?: unknown;
}

const EmptyStructuralPropsSchema = z.strictObject({});

/** Structural elements understood by each Canvas framework renderer. */
export const canvasStructuralCatalogComponents = {
  [RENDERLESS_CONTAINER_ELEMENT]: {
    props: EmptyStructuralPropsSchema,
    slots: ['default'],
    description: 'Groups an ordered list of child elements without a wrapper.',
  },
  [DRUPAL_MARKUP_ELEMENT]: {
    props: z.strictObject({ markup: z.string() }),
    slots: [],
    description: 'Renders markup supplied by Drupal.',
  },
  [CANVAS_PREVIEW_CONTENT_REGION_ELEMENT]: {
    props: EmptyStructuralPropsSchema,
    slots: ['default'],
    description: 'Locates routed page content inside preview page chrome.',
  },
  [CANVAS_MARKER_ELEMENT]: {
    props: z.strictObject({
      markerId: z.string().min(1),
    }),
    slots: ['default'],
    description: 'Represents an intrinsic Canvas marker component.',
  },
  [CANVAS_SLOT_PLACEHOLDER_ELEMENT]: {
    props: EmptyStructuralPropsSchema,
    slots: [],
    description: 'Retains an empty declared component slot in preview output.',
  },
} satisfies Record<string, CanvasJsonRenderCatalogComponent>;

/** A structural element name handled without an application component. */
export type CanvasStructuralElementType =
  keyof typeof canvasStructuralCatalogComponents;

/**
 * Defines a catalog containing Canvas structural elements and application
 * component types. Application components must use their json-render wire type
 * as the key, such as `js.hero` or `sdc.article-card`.
 */
export function defineCatalog<
  const TComponents extends Record<string, CanvasJsonRenderCatalogComponent>,
>(components: TComponents) {
  const catalogComponents = {
    ...components,
    ...canvasStructuralCatalogComponents,
  };
  const catalog = canvasJsonRenderSchema.createCatalog({
    components: catalogComponents,
  });

  // The core validator falls back to unknown props when a catalog contains
  // multiple component types. Select the matching props schema by element type
  // so application and structural components retain their own validation.
  const elementVariants = Object.entries(catalogComponents).map(
    ([type, component]) =>
      z.strictObject({
        type: z.literal(type),
        props: component.props,
        children: CanvasComponentTreeSlotSchema,
        slots: z.record(z.string(), CanvasComponentTreeSlotSchema).optional(),
      }),
  );
  const elementSchema = z.discriminatedUnion(
    'type',
    elementVariants as [
      (typeof elementVariants)[number],
      (typeof elementVariants)[number],
      ...(typeof elementVariants)[number][],
    ],
  );
  const catalogDocumentSchema = z.strictObject({
    root: z.string(),
    elements: z.record(z.string(), elementSchema),
  });

  return Object.assign(catalog, {
    validate(spec: unknown) {
      const documentResult = CanvasComponentTreeSchema.safeParse(spec);
      if (!documentResult.success) {
        return { success: false as const, error: documentResult.error };
      }

      const catalogResult = catalogDocumentSchema.safeParse(
        documentResult.data,
      );
      if (!catalogResult.success) {
        return { success: false as const, error: catalogResult.error };
      }
      return { success: true as const, data: catalogResult.data };
    },
  });
}
