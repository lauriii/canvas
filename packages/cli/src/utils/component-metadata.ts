import {
  componentMetadataDiagnosticFromError,
  componentMetadataDiagnosticFromParts,
  formatComponentMetadataDiagnostics,
  normalizeComponentMetadata,
  parseComponentMetadata,
  validateComponentMetadataEnvelope,
} from '@drupal-canvas/discovery';
import {
  createCanvasAjv,
  MissingRefError,
} from '@drupal-canvas/json-schema-validation';

import canvasJsonSchemaDefinitions from '../../../../schema.json';

import type {
  ComponentMetadataDiagnostic,
  ParsedComponentMetadata,
} from '@drupal-canvas/discovery';
import type {
  AnySchema,
  ValidateFunction,
} from '@drupal-canvas/json-schema-validation';
import type { Metadata } from '../types/Metadata';

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function createPropSchemaValidator() {
  // Target extensions may add JSON Schema vocabulary unknown to the CLI.
  const ajv = createCanvasAjv({
    allErrors: true,
    logger: false,
    strict: false,
  });
  const definitions = canvasJsonSchemaDefinitions.$defs as Record<
    string,
    AnySchema
  >;
  for (const [name, definition] of Object.entries(definitions)) {
    const uri = `json-schema-definitions://canvas.module/${name}`;
    // Some Canvas references are self-referential named-type sentinels. PHP
    // handles those specially; offline validation uses their underlying shape.
    const localDefinition =
      isRecord(definition) && definition.$ref === uri
        ? Object.fromEntries(
            Object.entries(definition).filter(([key]) => key !== '$ref'),
          )
        : definition;
    ajv.addSchema(localDefinition, uri);
  }
  return ajv;
}

function validatePropDefinitions(
  parsed: ParsedComponentMetadata,
): ComponentMetadataDiagnostic[] {
  if (!isRecord(parsed.value) || !isRecord(parsed.value.props)) return [];
  const properties = parsed.value.props.properties;
  if (!isRecord(properties)) return [];

  const diagnostics: ComponentMetadataDiagnostic[] = [];
  const ajv = createPropSchemaValidator();
  for (const [propName, propDefinition] of Object.entries(properties)) {
    if (!isRecord(propDefinition)) continue;

    let validate: ValidateFunction;
    try {
      validate = ajv.compile(propDefinition);
    } catch (error) {
      // Target and extension JSON Schema references cannot necessarily be
      // resolved offline. Drupal validates them authoritatively.
      if (error instanceof MissingRefError) continue;
      diagnostics.push(
        componentMetadataDiagnosticFromParts(
          parsed,
          ['props', 'properties', propName],
          error instanceof Error ? error.message : String(error),
        ),
      );
      continue;
    }

    if (!Array.isArray(propDefinition.examples)) continue;
    const example = propDefinition.examples[0];
    if (example === undefined || validate(example)) continue;
    const prefix = ['props', 'properties', propName, 'examples', '0'];
    diagnostics.push(
      ...(validate.errors ?? []).map((error) =>
        componentMetadataDiagnosticFromError(parsed, error, prefix),
      ),
    );
  }
  return diagnostics;
}

export function validateParsedComponentMetadata(
  parsed: ParsedComponentMetadata,
): ComponentMetadataDiagnostic[] {
  const envelopeDiagnostics = validateComponentMetadataEnvelope(parsed);
  if (envelopeDiagnostics.length > 0) {
    return envelopeDiagnostics;
  }
  return validatePropDefinitions(parsed);
}

export async function readValidatedComponentMetadata(
  filePath: string,
): Promise<Metadata> {
  const parsed = await parseComponentMetadata(filePath);
  const diagnostics = validateParsedComponentMetadata(parsed);
  if (diagnostics.length > 0) {
    throw new Error(formatComponentMetadataDiagnostics(diagnostics));
  }
  return normalizeComponentMetadata(parsed.value);
}

export { normalizeComponentMetadata, parseComponentMetadata };
