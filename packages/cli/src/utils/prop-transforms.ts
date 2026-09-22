import {
  CANVAS_COLOR_REF_PREFIX,
  COLOR_PROP_SCHEMA_REF,
  serializeColorValue,
  transformColorRef,
  UUID_PATTERN,
} from '@drupal-canvas/discovery';

import { isRecord } from './utils';

import type {
  BrandKitColorEntry,
  ColorTokenValue,
  ComponentMetadata,
} from '@drupal-canvas/discovery';
import type { CodeComponentPropSerialized } from '@drupal-canvas/ui/types/CodeComponent';
import type { AuthoredSpecElementMap } from 'drupal-canvas/json-render-utils';

export interface UnreconciledMediaProp {
  elementId: string;
  propName: string;
  src: string;
  mediaType: string;
}

export interface UnreconciledColorProp {
  elementId: string;
  propName: string;
  key: string;
}

/**
 * Describes how to identify and extract external URLs from a specific media
 * prop type. Add new entries to `mediaDescriptors` to support additional
 * media types (video, audio, etc.).
 */
interface MediaPropDescriptor {
  /** The Drupal media bundle used for uploads (e.g. 'image'). */
  mediaType: string;
  matchesSchema(schema: CodeComponentPropSerialized): boolean;
  getUrl(value: unknown): string | null;
}

const imageDescriptor: MediaPropDescriptor = {
  mediaType: 'image',
  matchesSchema: (schema) =>
    schema.$ref === 'json-schema-definitions://canvas.module/image',
  getUrl: (value) => {
    if (!isRecord(value) || typeof value.src !== 'string') return null;
    return value.src;
  },
};

const documentDescriptor: MediaPropDescriptor = {
  mediaType: 'document',
  matchesSchema: (schema) =>
    schema.$ref === 'json-schema-definitions://canvas.module/document',
  getUrl: (value) => {
    if (!isRecord(value) || typeof value.src !== 'string') return null;
    return value.src;
  },
};

// Media type descriptors. The first matching descriptor is used.
const mediaDescriptors: MediaPropDescriptor[] = [
  imageDescriptor,
  documentDescriptor,
];

export interface UnreconciledMediaMatch {
  url: string;
  mediaType: string;
}

export function getUnreconciledMedia(
  value: unknown,
  schema?: CodeComponentPropSerialized,
): UnreconciledMediaMatch | null {
  const descriptor = schema
    ? mediaDescriptors.find((d) => d.matchesSchema(schema))
    : mediaDescriptors.find((d) => d.getUrl(value) !== null);
  if (!descriptor) return null;
  const url = descriptor.getUrl(value);
  if (!url || !/^(https?:\/\/|data:)/i.test(url)) return null;
  return { url, mediaType: descriptor.mediaType };
}

/**
 * A prop transformer that converts individual prop values between
 * local (authored) and server (Drupal) formats.
 */
interface PropTransformer {
  /** Returns true if the transformer handles the given prop schema. */
  matches(schema: CodeComponentPropSerialized): boolean;
  /** Converts a local/authored value to the server format (push direction). */
  serialize(
    value: unknown,
    context: {
      schema: CodeComponentPropSerialized;
      provenance?: unknown;
      colorsByCssVariable?: Map<string, BrandKitColorEntry>;
    },
  ): unknown;
}

/**
 * Formatted text props (`contentMediaType: text/html`).
 * Authored: plain string. Server: `{ value, format }`.
 */
const formattedTextTransformer: PropTransformer = {
  matches(schema) {
    return schema.contentMediaType === 'text/html';
  },

  serialize(value, { schema }) {
    if (typeof value !== 'string') {
      return value;
    }

    const format =
      schema['x-formatting-context'] === 'inline'
        ? 'canvas_html_inline'
        : 'canvas_html_block';

    return { value, format };
  },
};

/**
 * Link props (`format: uri | uri-reference | iri | iri-reference`).
 * Authored: plain string (URL or path). Server: `{ uri, options }`.
 *
 * Root-relative paths (no scheme, leading `/`) are prefixed with `internal:`,
 * matching what the server stores when the same value is authored in the
 * Canvas UI. Other scheme-less references (`foo`, `?x=1`, `#frag`) are sent
 * as-is: `internal:` requires a leading slash, so prefixing them would produce
 * a URI the server rejects.
 *
 * @see \Drupal\canvas\TypedData\LinkUrl::getValue()
 */
const linkTransformer: PropTransformer = {
  matches(schema) {
    return (
      schema.type === 'string' &&
      ['uri', 'uri-reference', 'iri', 'iri-reference'].includes(
        schema.format ?? '',
      )
    );
  },

  serialize(value, { schema }) {
    if (typeof value !== 'string') {
      return value;
    }

    // Only uri-reference and iri-reference allow relative paths;
    // uri and iri require a scheme.
    const isReference =
      schema.format === 'uri-reference' || schema.format === 'iri-reference';
    const hasScheme = /^[a-z][a-z0-9+.-]*:/i.test(value);
    const uri =
      isReference && !hasScheme && value.startsWith('/')
        ? `internal:${value}`
        : value;
    return { uri, options: [] };
  },
};

/**
 * Media props (images, and future media types).
 * Authored: resolved media object. Server: stored provenance when available.
 *
 * Provenance may carry local-only metadata (e.g. `source_url`) that must not
 * be sent to Drupal. Only the entity reference (`target_id` / `target_uuid`)
 * is forwarded.
 */
const mediaTransformer: PropTransformer = {
  matches(schema) {
    return mediaDescriptors.some((d) => d.matchesSchema(schema));
  },

  serialize(value, { provenance }) {
    if (isRecord(provenance)) {
      if ('target_id' in provenance) {
        return { target_id: provenance.target_id };
      }
      if ('target_uuid' in provenance) {
        return { target_uuid: provenance.target_uuid };
      }
    }
    return value;
  },
};

/**
 * Color props (`$ref: json-schema-definitions://canvas.module/color`).
 * Authored: CSS string or canvas-color:<cssVarKey>. Server: CSS string or
 * canvas-color:<uuid>.
 */
const colorTransformer: PropTransformer = {
  matches(schema) {
    return schema.$ref === COLOR_PROP_SCHEMA_REF;
  },

  serialize(value, { colorsByCssVariable }) {
    return transformColorRef(value, colorsByCssVariable ?? new Map(), 'toUuid');
  },
};

// All transformers. Order matters: the first match wins.
const transformers: PropTransformer[] = [
  mediaTransformer,
  colorTransformer,
  formattedTextTransformer,
  linkTransformer,
];

/**
 * Serializes authored prop values for the server (push direction).
 *
 * Iterates registered transformers and applies the first one that matches
 * each prop's schema. Props without a matching schema or transformer are
 * passed through unchanged.
 */
export function serializePropsForServer(
  props: Record<string, unknown>,
  propSchemas: Record<string, CodeComponentPropSerialized>,
  provenance: Record<string, unknown> = {},
  colorsByCssVariable?: Map<string, BrandKitColorEntry>,
): Record<string, unknown> {
  const result: Record<string, unknown> = {};

  for (const [key, value] of Object.entries(props)) {
    const schema = propSchemas[key];

    if (!schema) {
      result[key] = value;
      continue;
    }

    const transformer = transformers.find((t) => t.matches(schema));

    if (transformer) {
      result[key] = transformer.serialize(value, {
        schema,
        provenance: provenance[key],
        colorsByCssVariable,
      });
    } else {
      result[key] = value;
    }
  }

  return result;
}

/**
 * Serializes elements from an authored spec map to format expected by the server.
 */
export function serializeElementMapForServer(
  elements: AuthoredSpecElementMap,
  metadata: ComponentMetadata[],
  remoteBrandKitColors: BrandKitColorEntry[] = [],
): AuthoredSpecElementMap {
  const schemaMap = new Map(
    metadata.map((m) => [`js.${m.machineName}`, m.props.properties ?? {}]),
  );
  const result: AuthoredSpecElementMap = {};
  const colorsByCssVariable = new Map(
    remoteBrandKitColors.map((color) => [color.cssVariable, color]),
  );

  for (const [id, element] of Object.entries(elements)) {
    const propSchemas = schemaMap.get(element.type);
    if (!propSchemas || !element.props) {
      result[id] = element;
      continue;
    }

    result[id] = {
      ...element,
      props: serializePropsForServer(
        element.props as Record<string, unknown>,
        propSchemas,
        element._provenance,
        colorsByCssVariable,
      ),
    };
  }

  return result;
}

export function collectUnreconciledMediaProps(
  elements: AuthoredSpecElementMap,
  metadata: ComponentMetadata[],
): UnreconciledMediaProp[] {
  const schemaMap = new Map(
    metadata.map((m) => [`js.${m.machineName}`, m.props.properties ?? {}]),
  );
  const unreconciled: UnreconciledMediaProp[] = [];

  for (const [elementId, element] of Object.entries(elements)) {
    const propSchemas = schemaMap.get(element.type);
    if (!propSchemas || !isRecord(element.props)) {
      continue;
    }

    for (const [propName, value] of Object.entries(element.props)) {
      const schema = propSchemas[propName];
      if (!schema) continue;

      const match = getUnreconciledMedia(value, schema);
      if (match) {
        unreconciled.push({
          elementId,
          propName,
          src: match.url,
          mediaType: match.mediaType,
        });
      }
    }
  }

  return unreconciled;
}

export function collectUnreconciledColorProps(
  elements: AuthoredSpecElementMap,
  metadata: ComponentMetadata[],
  remoteBrandKitColors: BrandKitColorEntry[],
): UnreconciledColorProp[] {
  const schemaMap = new Map(
    metadata.map((m) => [`js.${m.machineName}`, m.props.properties ?? {}]),
  );
  const knownCssVariables = new Set(
    remoteBrandKitColors.map((color) => color.cssVariable),
  );
  const unreconciled: UnreconciledColorProp[] = [];

  for (const [elementId, element] of Object.entries(elements)) {
    const propSchemas = schemaMap.get(element.type);
    if (!propSchemas || !isRecord(element.props)) {
      continue;
    }

    for (const [propName, value] of Object.entries(element.props)) {
      const schema = propSchemas[propName];
      if (!schema || schema.$ref !== COLOR_PROP_SCHEMA_REF) {
        continue;
      }
      if (typeof value !== 'string') {
        continue;
      }
      if (!value.startsWith(CANVAS_COLOR_REF_PREFIX)) {
        continue;
      }

      const key = value.slice(CANVAS_COLOR_REF_PREFIX.length);
      if (UUID_PATTERN.test(key)) {
        continue;
      }
      if (!knownCssVariables.has(`--${key}`)) {
        unreconciled.push({ elementId, propName, key });
      }
    }
  }

  return unreconciled;
}

function isColorTokenValue(value: unknown): value is ColorTokenValue {
  if (!isRecord(value)) {
    return false;
  }
  const components = value.components;
  return (
    (value.colorSpace === 'srgb' || value.colorSpace === 'hsl') &&
    Array.isArray(components) &&
    components.length === 3 &&
    components.every(
      (component) =>
        typeof component === 'number' && Number.isFinite(component),
    )
  );
}

/**
 * Converts a single server-resolved color value to CLI-friendly string form.
 * See {@link collapseColorPropsInElements} for the full conversion rules.
 * Values that are not resolved objects pass through unchanged.
 */
function collapseResolvedColorPropValue(value: unknown): unknown {
  const resolvedColor = isRecord(value) ? value : null;
  const token = resolvedColor?.value;
  if (!isColorTokenValue(token)) {
    return value;
  }

  const cssVariableValue = resolvedColor?.cssVariable;
  const cssVariable =
    typeof cssVariableValue === 'string' ? cssVariableValue : null;
  if (cssVariable && cssVariable.startsWith('--') && cssVariable.length > 2) {
    return `${CANVAS_COLOR_REF_PREFIX}${cssVariable.slice(2)}`;
  }

  if (token.colorSpace === 'srgb' && typeof token.hex === 'string') {
    const normalizedHex = /^#[0-9a-fA-F]{6}$/.test(token.hex)
      ? token.hex
      : null;
    if (normalizedHex) {
      if (token.alpha == null || token.alpha === 1) {
        return normalizedHex;
      }
      const alphaByte = Math.min(
        255,
        Math.max(0, Math.round(token.alpha * 255)),
      );
      const alphaHex = alphaByte.toString(16).padStart(2, '0');
      return `${normalizedHex}${alphaHex}`;
    }
  }

  return serializeColorValue(token);
}

/**
 * Converts server-resolved color prop values to CLI-friendly string form.
 *
 * During a pull the server returns color props as resolved objects
 * ({ value: ColorTokenValue, cssVariable, cssColorValue, colorName }) rather
 * than the plain strings stored in the database. This function walks every
 * element in the map and rewrites those objects to CLI-friendly strings:
 *
 *   - Brand kit colors (cssVariable present) → "canvas-color:<cssVarKey>"
 *     e.g. { cssVariable: "--brand-red", … }  → "canvas-color:brand-red"
 *   - Free-pick sRGB with a hex value         → "#rrggbb" or "#rrggbbaa"
 *   - All other free-pick colors              → CSS function string via
 *                                               serializeColorValue()
 *   - Values already in CLI-friendly form     → unchanged (pass-through)
 *
 * The original map reference is returned when no values change, avoiding
 * spurious database writes.
 */
export function collapseColorPropsInElements(
  elements: AuthoredSpecElementMap,
  componentMetadata: ComponentMetadata[],
): AuthoredSpecElementMap {
  const colorPropNamesByType = new Map<string, Set<string>>();

  for (const entry of componentMetadata) {
    const colorProps = new Set<string>();
    for (const [propName, schema] of Object.entries(
      entry.props.properties ?? {},
    )) {
      if (schema.$ref === COLOR_PROP_SCHEMA_REF) {
        colorProps.add(propName);
      }
    }
    if (colorProps.size > 0) {
      colorPropNamesByType.set(`js.${entry.machineName}`, colorProps);
    }
  }

  if (colorPropNamesByType.size === 0) {
    return elements;
  }

  let hasUpdates = false;
  const nextElements: AuthoredSpecElementMap = {};

  for (const [elementId, element] of Object.entries(elements)) {
    const colorProps = colorPropNamesByType.get(element.type);
    if (!colorProps || !isRecord(element.props)) {
      nextElements[elementId] = element;
      continue;
    }

    let propsChanged = false;
    const nextProps: Record<string, unknown> = {
      ...(element.props as Record<string, unknown>),
    };

    for (const propName of colorProps) {
      if (!(propName in nextProps)) {
        continue;
      }
      const currentValue = nextProps[propName];
      const nextValue = collapseResolvedColorPropValue(currentValue);
      if (nextValue !== currentValue) {
        nextProps[propName] = nextValue;
        propsChanged = true;
      }
    }

    if (propsChanged) {
      hasUpdates = true;
      nextElements[elementId] = {
        ...element,
        props: nextProps,
      };
      continue;
    }

    nextElements[elementId] = element;
  }

  return hasUpdates ? nextElements : elements;
}
