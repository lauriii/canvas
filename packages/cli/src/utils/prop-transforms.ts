import { isRecord } from './utils';

import type { ComponentMetadata } from '@drupal-canvas/discovery';
import type { CodeComponentPropSerialized } from '@drupal-canvas/ui/types/CodeComponent';
import type { AuthoredSpecElementMap } from 'drupal-canvas/json-render-utils';

export interface UnreconciledMediaProp {
  elementId: string;
  propName: string;
  src: string;
  mediaType: string;
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

// Media type descriptors. The first matching descriptor is used.
const mediaDescriptors: MediaPropDescriptor[] = [imageDescriptor];

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
 * Relative paths (not starting with a scheme) are prefixed with `internal:`
 * as expected by Drupal's link field storage.
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
    // uri and iri require a scheme. Add internal: prefix for relative paths.
    const isReference =
      schema.format === 'uri-reference' || schema.format === 'iri-reference';
    const hasScheme = /^[a-z][a-z0-9+.-]*:/.test(value);
    const uri = !hasScheme && isReference ? `internal:${value}` : value;
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

// All transformers. Order matters: the first match wins.
const transformers: PropTransformer[] = [
  mediaTransformer,
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
): AuthoredSpecElementMap {
  const schemaMap = new Map(
    metadata.map((m) => [`js.${m.machineName}`, m.props.properties ?? {}]),
  );
  const result: AuthoredSpecElementMap = {};

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
