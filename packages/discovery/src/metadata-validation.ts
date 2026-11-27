import { promises as fs } from 'node:fs';
import { LineCounter, parseDocument } from 'yaml';
import { createCanvasAjv } from '@drupal-canvas/json-schema-validation';

import componentMetadataSchema from '../../../component-metadata.schema.json';

import type { ErrorObject } from '@drupal-canvas/json-schema-validation';
import type { Document, Node } from 'yaml';
import type { ComponentMetadata } from './types';

export interface ParsedComponentMetadata {
  document: Document.Parsed;
  lineCounter: LineCounter;
  source: string;
  value: unknown;
}

export interface ComponentMetadataDiagnostic {
  column?: number;
  line?: number;
  message: string;
  path: string;
}

export class ComponentMetadataValidationError extends Error {
  constructor(
    public readonly sourcePath: string,
    public readonly diagnostics: ComponentMetadataDiagnostic[],
    public readonly authoredName: string | null = null,
  ) {
    super(
      `Invalid component metadata in ${sourcePath}:\n${formatComponentMetadataDiagnostics(diagnostics)}`,
    );
    this.name = 'ComponentMetadataValidationError';
  }
}

const STYLE_ID_SCHEMA = { type: 'string', pattern: '^[a-z0-9_]+$' };
const LIMIT_LIST_SCHEMA = {
  type: 'array',
  items: { type: 'string', minLength: 1 },
};

/**
 * Named parts of a code component, each with a label and the styles it
 * accepts: a list of style IDs, or a map of style ID to a limit (or null).
 *
 * @see \Drupal\canvas\Style\StyleDeclaration
 */
export const STYLE_PARTS_SCHEMA = {
  description:
    "Named parts that take styles of their own, keyed by part ID. The component receives each part's choices in its styleParts prop.",
  type: 'object',
  propertyNames: { type: 'string', pattern: '^[a-z][a-z0-9_]*$' },
  additionalProperties: {
    type: 'object',
    additionalProperties: false,
    required: ['label', 'styles'],
    properties: {
      label: { type: 'string', pattern: '\\S' },
      styles: {
        oneOf: [
          { type: 'array', uniqueItems: true, items: STYLE_ID_SCHEMA },
          {
            type: 'object',
            propertyNames: STYLE_ID_SCHEMA,
            additionalProperties: {
              oneOf: [
                { type: 'null' },
                {
                  type: 'object',
                  additionalProperties: false,
                  properties: {
                    folders: LIMIT_LIST_SCHEMA,
                    colors: LIMIT_LIST_SCHEMA,
                    options: LIMIT_LIST_SCHEMA,
                    custom: { type: 'boolean' },
                  },
                },
              ],
            },
          },
        ],
      },
    },
  },
};

// Ponytail: `styleParts` is added here until component-metadata.schema.json
// at the repository root declares it.
const validateEnvelope = createCanvasAjv({ allErrors: true }).compile({
  ...componentMetadataSchema,
  properties: {
    ...componentMetadataSchema.properties,
    styleParts: STYLE_PARTS_SCHEMA,
  },
});

function decodeJsonPointer(instancePath: string): string[] {
  if (!instancePath) return [];
  return instancePath
    .slice(1)
    .split('/')
    .map((part) => part.replaceAll('~1', '/').replaceAll('~0', '~'));
}

function componentMetadataDiagnosticPath(parts: string[]): string {
  return parts.length === 0
    ? '$'
    : `$${parts
        .map((part) =>
          /^\d+$/.test(part) ? `[${part}]` : `.${part.replaceAll('.', '\\.')}`,
        )
        .join('')}`;
}

function getErrorPath(error: ErrorObject): string[] {
  const parts = decodeJsonPointer(error.instancePath);
  if (error.keyword === 'additionalProperties') {
    const property = error.params.additionalProperty;
    if (typeof property === 'string') parts.push(property);
  }
  return parts;
}

function locateNode(document: Document.Parsed, parts: string[]): Node | null {
  let currentParts = parts;
  while (currentParts.length > 0) {
    const node = document.getIn(currentParts, true);
    if (node && typeof node === 'object' && 'range' in node) {
      return node as Node;
    }
    currentParts = currentParts.slice(0, -1);
  }
  return document.contents;
}

function formatErrorMessage(error: ErrorObject): string {
  if (error.keyword === 'additionalProperties') {
    return `must not contain unknown property '${String(error.params.additionalProperty)}'`;
  }
  if (error.keyword === 'required') {
    return `must contain required property '${String(error.params.missingProperty)}'`;
  }
  return error.message ?? 'is invalid';
}

export function componentMetadataDiagnosticFromParts(
  parsed: ParsedComponentMetadata,
  parts: string[],
  message: string,
): ComponentMetadataDiagnostic {
  const node = locateNode(parsed.document, parts);
  const position = node?.range
    ? parsed.lineCounter.linePos(node.range[0])
    : undefined;
  return {
    path: componentMetadataDiagnosticPath(parts),
    message,
    line: position?.line,
    column: position?.col,
  };
}

export function componentMetadataDiagnosticFromError(
  parsed: ParsedComponentMetadata,
  error: ErrorObject,
  prefix: string[] = [],
): ComponentMetadataDiagnostic {
  const parts = [...prefix, ...getErrorPath(error)];
  const property = parts.at(-1);
  const message =
    error.keyword === 'not' &&
    ['x-allowed-entity-type-id', 'x-allowed-bundle'].includes(property ?? '')
      ? `must not contain projected property '${property}'`
      : formatErrorMessage(error);
  return componentMetadataDiagnosticFromParts(parsed, parts, message);
}

export function validateComponentMetadataEnvelope(
  parsed: ParsedComponentMetadata,
): ComponentMetadataDiagnostic[] {
  if (validateEnvelope(parsed.value)) {
    return [];
  }
  return (validateEnvelope.errors ?? []).map((error) =>
    componentMetadataDiagnosticFromError(parsed, error),
  );
}

export async function parseComponentMetadata(
  filePath: string,
): Promise<ParsedComponentMetadata> {
  const source = await fs.readFile(filePath, 'utf8');
  const lineCounter = new LineCounter();
  const document = parseDocument(source, {
    lineCounter,
    prettyErrors: false,
    uniqueKeys: true,
  });
  if (document.errors.length > 0) {
    throw new ComponentMetadataValidationError(
      filePath,
      document.errors.map((error) => {
        const position =
          error.pos[0] !== undefined
            ? lineCounter.linePos(error.pos[0])
            : undefined;
        return {
          path: '$',
          message: error.message,
          line: position?.line,
          column: position?.col,
        };
      }),
    );
  }
  return {
    document,
    lineCounter,
    source,
    value: document.toJS(),
  };
}

export function normalizeComponentMetadata(value: unknown): ComponentMetadata {
  const raw = value as Partial<ComponentMetadata>;
  return {
    name: raw.name as string,
    machineName: raw.machineName as string,
    status: raw.status ?? true,
    required: raw.required ?? [],
    props: raw.props ?? { properties: {} },
    slots: raw.slots ?? {},
    dataDependencies: raw.dataDependencies ?? {},
    ...(raw.styles !== undefined ? { styles: raw.styles } : {}),
    ...(raw.styleParts !== undefined ? { styleParts: raw.styleParts } : {}),
  };
}

export function formatComponentMetadataDiagnostics(
  diagnostics: ComponentMetadataDiagnostic[],
): string {
  return diagnostics
    .map((diagnostic) => {
      const location = diagnostic.line
        ? `Line ${diagnostic.line}, Column ${diagnostic.column}: `
        : '';
      return `${location}${diagnostic.path} ${diagnostic.message}`;
    })
    .join('\n');
}
