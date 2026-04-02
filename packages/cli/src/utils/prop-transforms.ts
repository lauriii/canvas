import type { ComponentMetadata } from '@drupal-canvas/discovery';
import type { CodeComponentPropSerialized } from '@drupal-canvas/ui/types/CodeComponent';
import type { AuthoredSpecElementMap } from 'drupal-canvas/json-render-utils';

/**
 * A prop transformer that converts individual prop values between
 * local (authored) and server (Drupal) formats.
 */
interface PropTransformer {
  /** Returns true if the transformer handles the given prop schema. */
  matches(schema: CodeComponentPropSerialized): boolean;
  /** Converts a local/authored value to the server format (push direction). */
  serialize(value: unknown, schema: CodeComponentPropSerialized): unknown;
}

/**
 * Formatted text props (`contentMediaType: text/html`).
 * Authored: plain string. Server: `{ value, format }`.
 */
const formattedTextTransformer: PropTransformer = {
  matches(schema) {
    return schema.contentMediaType === 'text/html';
  },

  serialize(value, schema) {
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

  serialize(value, schema) {
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

// All transformers. Order matters: the first match wins.
const transformers: PropTransformer[] = [
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
      result[key] = transformer.serialize(value, schema);
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
      ),
    };
  }

  return result;
}
