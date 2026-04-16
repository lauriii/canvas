import { cloneElement, createElement, isValidElement } from 'react';
import { fromJSONSchema, z } from 'zod';
import { defineCatalog, resolveElementProps } from '@json-render/core';
import { schema } from '@json-render/react/schema';

import canvasSchema from '../../../schema.json';

import type { ComponentType, ReactNode } from 'react';
import type { ComponentMetadata } from '@drupal-canvas/discovery';
import type { PropResolutionContext, Spec, UIElement } from '@json-render/core';

/**
 * Drupal Canvas component tree node.
 * @see canvas.component_tree_node in config/schema/canvas.schema.yml
 */
export interface CanvasComponentTreeNode {
  parent_uuid: string | null;
  slot: string | null;
  uuid: string;
  component_id: string;
  component_version: string | null;
  inputs: Record<string, unknown>;
  inputs_resolved?: Record<string, unknown> | null;
  label: string | null;
}

/**
 * Drupal Canvas component tree. A flat sequence of component tree nodes linked by parent_uuid.
 * @see canvas.component_tree in config/schema/canvas.schema.yml
 */
export type CanvasComponentTree = CanvasComponentTreeNode[];

/**
 * Authored page spec element. The local file representation of a component
 * instance, as opposed to CanvasComponentTreeNode which is the API representation.
 */
export interface AuthoredSpecElement {
  type: string;
  props?: unknown;
  slots?: AuthoredSpecSlots;
  _provenance?: Record<string, unknown>;
}

export type AuthoredSpecElementMap = Record<string, AuthoredSpecElement>;
export type AuthoredSpecSlots = Record<string, string[]>;

/**
 * Converts an array of Drupal Canvas components to json-render spec format.
 *
 * @param components - Flat array of Canvas component tree nodes
 * @returns json-render spec
 */
export function canvasTreeToSpec(components: CanvasComponentTree): Spec {
  const elements: Record<string, UIElement> = {};
  const rootUuids: string[] = [];

  for (const component of components) {
    // Parse inputs if the API returned it as a JSON string rather than an object.
    let inputs: Record<string, unknown>;
    if (typeof component.inputs === 'string') {
      try {
        inputs = JSON.parse(component.inputs) as Record<string, unknown>;
      } catch {
        throw new Error(
          `Component "${component.uuid}" has malformed JSON inputs: ${component.inputs}`,
        );
      }
    } else {
      inputs = component.inputs;
    }

    // @todo: Convert Canvas content template prop expressions to json-render data bindings and reverse in specToCanvasTree.

    if (component.parent_uuid === null) {
      elements[component.uuid] = {
        type: component.component_id,
        props: inputs,
      };
      rootUuids.push(component.uuid);
    } else {
      if (component.slot === null) {
        throw new Error(
          `Component "${component.uuid}" has a parent_uuid but no slot.`,
        );
      }
      // Canvas component tree should always have parents precede their children.
      const parent = elements[component.parent_uuid];
      if (!parent) {
        throw new Error(
          `Component "${component.uuid}" references unknown or out-of-order parent "${component.parent_uuid}".`,
        );
      }

      elements[component.uuid] = {
        type: component.component_id,
        props: inputs,
      };

      if (component.slot === 'children') {
        // In React, children is a special prop that acts as a default slot — json-render keeps it separately from named slots.
        if (!parent.children) {
          parent.children = [];
        }
        parent.children.push(component.uuid);
      } else {
        if (!parent.slots) {
          parent.slots = {};
        }
        if (!parent.slots[component.slot]) {
          parent.slots[component.slot] = [];
        }
        parent.slots[component.slot].push(component.uuid);
      }
    }
  }

  if (rootUuids.length === 0) {
    throw new Error(
      'Canvas component tree has no root component (no component with null parent_uuid).',
    );
  }

  // A canvas component tree may have multiple top-level components.
  // Wrap them in a synthetic wrapper element so the spec has a single root.
  // @see renderSpec
  if (rootUuids.length > 1) {
    elements['canvas:component-tree'] = {
      type: 'canvas:component-tree',
      props: {},
      children: rootUuids,
    };
    return { root: 'canvas:component-tree', elements };
  }

  return {
    root: rootUuids[0],
    elements,
  };
}

/**
 * Converts a json-render spec subtree rooted at key to flat Canvas nodes,
 * appending each node to result.
 */
function convertSpecElement(
  key: string,
  elements: Record<string, UIElement>,
  result: CanvasComponentTree,
  parentUuid: string | null,
  slot: string | null,
  ctx: PropResolutionContext,
): void {
  const element = elements[key];
  if (!element) {
    throw new Error(`Element key "${key}" not found in elements map.`);
  }

  // Use the spec element key as the UUID if it is already a valid UUID
  // (e.g. when the spec was produced by canvasTreeToSpec). Fall back to
  // a fresh UUID otherwise.
  const UUID_PATTERN =
    /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;
  const uuid = UUID_PATTERN.test(key) ? key : crypto.randomUUID();

  result.push({
    uuid,
    parent_uuid: parentUuid,
    slot,
    component_id: element.type,
    // Component version has no equivalent in the json-render spec - set to null.
    component_version: null,
    inputs: resolveElementProps(element.props, ctx),
    label: null,
  });

  // json-render's children array has no Canvas equivalent — map to 'children' slot.
  for (const childKey of element.children ?? []) {
    convertSpecElement(childKey, elements, result, uuid, 'children', ctx);
  }

  for (const [slotName, childKeys] of Object.entries(element.slots ?? {})) {
    for (const childKey of childKeys) {
      convertSpecElement(childKey, elements, result, uuid, slotName, ctx);
    }
  }
}

/**
 * Converts a json-render spec to Drupal Canvas component tree format.
 *
 * @param jsonRenderSpec - json-render spec
 * @returns Flat array of Canvas component tree nodes
 */
export function specToCanvasTree(jsonRenderSpec: Spec): CanvasComponentTree {
  const result: CanvasComponentTree = [];
  const rootElement = jsonRenderSpec.elements[jsonRenderSpec.root];

  if (!rootElement) {
    throw new Error(
      `Root element "${jsonRenderSpec.root}" not found in elements map.`,
    );
  }

  const ctx: PropResolutionContext = {
    stateModel: jsonRenderSpec.state ?? {},
  };

  // Unwrap the synthetic canvas:component-tree wrapper added by canvasTreeToSpec
  // for multi-root trees — it has no Canvas equivalent and must not appear in output.
  if (rootElement.type === 'canvas:component-tree') {
    for (const childKey of rootElement.children ?? []) {
      convertSpecElement(
        childKey,
        jsonRenderSpec.elements,
        result,
        null,
        null,
        ctx,
      );
    }
    return result;
  }

  convertSpecElement(
    jsonRenderSpec.root,
    jsonRenderSpec.elements,
    result,
    null,
    null,
    ctx,
  );
  return result;
}

/**
 * Registry of components keyed by component_id.
 */
export type ComponentRegistry = Record<
  string,
  ComponentType<Record<string, unknown>>
>;

/**
 * Normalizes Canvas prop payloads into component-friendly values.
 *
 * Current special cases for prop values:
 * - Rich text wrappers like `{ value, format }` are reduced to `value` string.
 * - Image wrappers with metadata (for example `sourceType`) are unwrapped from
 *   `{ sourceType, value: { src, ... } }` to the inner image object.
 */
function normalizeCanvasProps(value: unknown): unknown {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    return value;
  }

  const record = value as Record<string, unknown>;
  return Object.fromEntries(
    Object.entries(record).map(([key, item]) => {
      // Normalize top-level props only. Primitive values pass through untouched.
      if (!item || typeof item !== 'object' || Array.isArray(item)) {
        return [key, item];
      }

      const nestedRecord = item as Record<string, unknown>;
      const nestedKeys = Object.keys(nestedRecord);

      // Rich text values are provided as `{ value, format }` wrappers.
      if (
        nestedKeys.length > 0 &&
        nestedKeys.every(
          (nestedKey) => nestedKey === 'value' || nestedKey === 'format',
        ) &&
        typeof nestedRecord.value === 'string' &&
        typeof nestedRecord.format === 'string'
      ) {
        return [key, nestedRecord.value];
      }

      // Image values may include source metadata and the actual image under `value`.
      if (
        typeof nestedRecord.sourceType === 'string' &&
        nestedRecord.value &&
        typeof nestedRecord.value === 'object' &&
        typeof (nestedRecord.value as Record<string, unknown>).src === 'string'
      ) {
        return [key, nestedRecord.value];
      }

      // Leave unknown object-shaped props unchanged.
      return [key, item];
    }),
  );
}

/**
 * Renders a single element from a json-render spec, recursively resolving
 * children and named slots.
 */
function renderSpecElement(
  key: string,
  elements: Spec['elements'],
  registry: ComponentRegistry,
  ctx: PropResolutionContext,
): React.ReactNode {
  const element = elements[key];
  if (!element) {
    throw new Error(`Element key "${key}" not found in elements map.`);
  }

  // Transparent passthrough for the synthetic multi-root wrapper.
  // @see renderSpec
  if (element.type === 'canvas:component-tree') {
    const children = (element.children ?? []).map((childKey) =>
      renderSpecChild(childKey, elements, registry, ctx),
    );
    return <>{children}</>;
  }

  const component = registry[element.type];
  if (!component) {
    return null;
  }
  const resolvedProps = resolveElementProps(element.props, ctx);
  const normalizedProps = normalizeCanvasProps(resolvedProps) as Record<
    string,
    unknown
  >;

  const children = (element.children ?? []).map((childKey) =>
    renderSpecChild(childKey, elements, registry, ctx),
  );

  const slots: Record<string, ReactNode[]> = {};
  for (const [slotName, childKeys] of Object.entries(element.slots ?? {})) {
    slots[slotName] = childKeys.map((childKey) =>
      renderSpecChild(childKey, elements, registry, ctx),
    );
  }

  return createElement(component, {
    ...normalizedProps,
    ...slots,
    ...(children.length > 0 ? { children } : {}),
  });
}

function renderSpecChild(
  key: string,
  elements: Spec['elements'],
  registry: ComponentRegistry,
  ctx: PropResolutionContext,
): ReactNode {
  const child = renderSpecElement(key, elements, registry, ctx);

  if (isValidElement(child)) {
    return cloneElement(child, { key });
  }

  return child;
}

/**
 * Renders a json-render spec using the given component registry.
 *
 * @param spec - json-render spec to render
 * @param registry - Component registry to use for rendering.
 * @see {@link defineComponentRegistry}
 */
export function renderSpec(
  spec: Spec,
  registry: ComponentRegistry,
): React.ReactNode {
  const ctx: PropResolutionContext = {
    stateModel: spec.state ?? {},
  };
  return renderSpecElement(spec.root, spec.elements, registry, ctx);
}

/**
 * Renders a Drupal Canvas component tree.
 *
 * @param components - Canvas component tree to render
 * @param registry - Component registry to use for rendering.
 * @see {@link defineComponentRegistry}
 */
export function renderCanvasTree(
  components: CanvasComponentTree,
  registry: ComponentRegistry,
): React.ReactNode {
  const spec = canvasTreeToSpec(components);
  return renderSpec(spec, registry);
}

/**
 * Canvas JSON Schema `$ref` prefix used in component metadata.
 */
const CANVAS_REF_PREFIX = 'json-schema-definitions://canvas.module/';

/**
 * Rewrites Canvas-specific `$ref` URIs to local JSON Pointer refs (`#/$defs/...`)
 * that Zod's `fromJSONSchema` can resolve natively.
 */
function rewriteCanvasRefs(node: unknown): unknown {
  if (!node || typeof node !== 'object' || Array.isArray(node)) {
    return node;
  }
  const record = node as Record<string, unknown>;
  const result: Record<string, unknown> = {};
  for (const [key, value] of Object.entries(record)) {
    if (
      key === '$ref' &&
      typeof value === 'string' &&
      value.startsWith(CANVAS_REF_PREFIX)
    ) {
      result.$ref = `#/$defs/${value.slice(CANVAS_REF_PREFIX.length)}`;
    } else {
      result[key] = rewriteCanvasRefs(value);
    }
  }
  return result;
}

/**
 * Pre-rewritten Canvas $defs with local refs.
 */
const canvasDefs = rewriteCanvasRefs(canvasSchema.$defs) as Record<
  string,
  unknown
>;

/**
 * Recursively walks a JSON schema object, stripping `uri-reference` and
 * `iri-reference` format keywords and collecting the property paths where
 * they appeared. Follows `$defs` and nested `properties`/`items`.
 */
function stripUriRefFormats(
  node: unknown,
  currentPath: string[],
  paths: string[][],
  visited = new WeakSet<object>(),
): void {
  if (!node || typeof node !== 'object') return;
  const obj = node as Record<string, unknown>;
  if (visited.has(obj)) return;
  visited.add(obj);

  if (obj.format === 'uri-reference' || obj.format === 'iri-reference') {
    delete obj.format;
    if (currentPath.length > 0) {
      paths.push([...currentPath]);
    }
  }

  if (obj.properties && typeof obj.properties === 'object') {
    for (const [key, prop] of Object.entries(
      obj.properties as Record<string, unknown>,
    )) {
      stripUriRefFormats(prop, [...currentPath, key], paths, visited);
    }
  }

  if (obj.items) {
    stripUriRefFormats(obj.items, currentPath, paths, visited);
  }

  if (obj.$defs && typeof obj.$defs === 'object') {
    for (const def of Object.values(obj.$defs as Record<string, unknown>)) {
      stripUriRefFormats(def, currentPath, paths, visited);
    }
  }
}

function metadataToCatalogEntry(metadata: ComponentMetadata) {
  const jsonSchema: Record<string, unknown> = {
    type: 'object',
    ...metadata.props,
  };
  // Include Canvas $defs so Zod can resolve rewritten local $ref pointers.
  jsonSchema.$defs = canvasDefs;

  if (metadata.required.length > 0) {
    jsonSchema.required = metadata.required;
  }

  const slotNames = Object.keys(metadata.slots);

  // Add slots as additional properties accepting any value.
  if (slotNames.length > 0) {
    const properties = (jsonSchema.properties ?? {}) as Record<string, unknown>;
    for (const [slotName, slot] of Object.entries(metadata.slots)) {
      properties[slotName] = {
        description: slot.title ?? `${slotName} slot`,
      };
    }
    jsonSchema.properties = properties;
  }

  const rewritten = rewriteCanvasRefs(jsonSchema) as Record<string, unknown>;

  // Recursively strip uri-reference/iri-reference formats from the schema
  // (including $defs) so fromJSONSchema doesn't apply z.url() which rejects
  // relative URLs. Collect the property paths so we can re-add validation
  // with a lenient URL check that accepts relative URLs.
  const uriRefPaths: string[][] = [];
  stripUriRefFormats(rewritten, [], uriRefPaths);

  let zodSchema = fromJSONSchema(rewritten);

  // Re-add validation for uri-reference/iri-reference props: resolve against
  // a dummy base so relative URLs like "/path" or "image.png" pass.
  if (uriRefPaths.length > 0) {
    zodSchema = zodSchema.superRefine((val, ctx) => {
      for (const path of uriRefPaths) {
        let current: unknown = val;
        for (const segment of path) {
          if (current && typeof current === 'object') {
            current = (current as Record<string, unknown>)[segment];
          } else {
            current = undefined;
            break;
          }
        }
        if (typeof current !== 'string') continue;
        try {
          new URL(current, 'http://localhost');
        } catch {
          ctx.addIssue({
            code: 'custom',
            message: `Invalid URI reference: ${current}`,
            path,
          });
        }
      }
    });
  }

  return {
    props: zodSchema,
    slots: slotNames,
  };
}

/**
 * Derives the Drupal Canvas component_id from a component's machineName.
 *
 * Canvas component_ids for JS components follow the format `js.<machineName>`.
 */
function toComponentId(machineName: string): string {
  return `js.${machineName}`;
}

/**
 * Defines a component registry by dynamically importing each component's
 * JavaScript entry file from the discovery results.
 *
 * Components are keyed by their Drupal Canvas component_id (`js.<machineName>`)
 * to match the `component_id` used in Canvas component trees.
 *
 * @param components - Array of discovered components from `discoverCanvasProject()`
 * @returns A component registry that can be passed to `renderCanvasTree()`
 */
export async function defineComponentRegistry(
  components: { name: string; jsEntryPath: string | null }[],
): Promise<ComponentRegistry> {
  const registry: ComponentRegistry = {};

  const entries = await Promise.all(
    components
      .filter(
        (c): c is { name: string; jsEntryPath: string } =>
          c.jsEntryPath !== null,
      )
      .map(async (c) => {
        const mod: Record<string, unknown> = await import(
          /* @vite-ignore */ c.jsEntryPath
        );
        const renderFn = mod.default;
        if (typeof renderFn !== 'function') {
          return null;
        }
        const names = c.name.startsWith('js.')
          ? [c.name]
          : [c.name, toComponentId(c.name.toLowerCase())];
        return { names, renderFn } as const;
      }),
  );

  for (const entry of entries) {
    if (entry !== null) {
      for (const name of entry.names) {
        registry[name] = entry.renderFn as ComponentRegistry[string];
      }
    }
  }

  return registry;
}

/**
 * Defines a complete json-render catalog from component metadata using the
 * `@json-render/react` schema. Converts props from JSON Schema (as defined
 * in component.yml) to Zod schemas.
 *
 * @param metadata - Array of component metadata from `loadComponentsMetadata()`
 * @returns A json-render `Catalog` instance
 */
export function defineComponentCatalog(metadata: ComponentMetadata[]) {
  const components = Object.fromEntries(
    metadata.map((m) => [
      toComponentId(m.machineName),
      metadataToCatalogEntry(m),
    ]),
  );

  // Add the synthetic canvas:component-tree wrapper used by canvasTreeToSpec
  // for multi-root trees so that catalog validation accepts it.
  components['canvas:component-tree'] = {
    props: z.object({}),
    slots: ['children'],
  };

  const catalog = defineCatalog(schema, { components, actions: {} });

  // Override catalog.validate — the default implementation falls back to
  // z.record(z.string(), z.unknown()) for props when there are 2+ components,
  // which skips per-component prop validation entirely. We build a
  // discriminated union on `type` so each element's props are validated
  // against the correct component schema.
  const elementVariants = Object.entries(components).map(([name, entry]) =>
    z.object({
      type: z.literal(name),
      props: entry.props,
      children: z.array(z.string()),
      slots: z.record(z.string(), z.array(z.string())),
      visible: z.any().optional(),
    }),
  );

  const elementSchema =
    elementVariants.length >= 2
      ? z.discriminatedUnion(
          'type',
          elementVariants as [
            (typeof elementVariants)[number],
            ...typeof elementVariants,
          ],
        )
      : elementVariants[0];

  const specSchema = z.object({
    root: z.string(),
    elements: z.record(z.string(), elementSchema),
  });

  return Object.assign(catalog, {
    validate(spec: unknown) {
      const result = specSchema.safeParse(spec);
      if (result.success) {
        return { success: true as const, data: result.data };
      }
      return { success: false as const, error: result.error };
    },
  });
}
