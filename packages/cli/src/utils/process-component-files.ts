import { promises as fs } from 'fs';
import path from 'path';
import * as yaml from 'js-yaml';
import { parse } from '@babel/parser';
import { getImportsFromAst } from '@drupal-canvas/ui/features/code-editor/utils/ast-utils';

import type { Component, DataDependencies } from '../types/Component';
import type { Metadata } from '../types/Metadata';

const PROJECTED_CONTENT_ENTITY_REFERENCE_PROP_KEYS = [
  'x-allowed-entity-type-id',
  'x-allowed-bundle',
];

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

export function stripProjectedContentEntityReferencePropKeys(
  props: Component['props'],
): Component['props'] {
  const sanitizedProps: Component['props'] = {};
  for (const [propName, prop] of Object.entries(props ?? {})) {
    if (!isRecord(prop)) {
      sanitizedProps[propName] = prop;
      continue;
    }
    const sanitizedProp = { ...prop };
    for (const key of PROJECTED_CONTENT_ENTITY_REFERENCE_PROP_KEYS) {
      delete sanitizedProp[key];
    }
    sanitizedProps[propName] = sanitizedProp;
  }
  return sanitizedProps;
}

/**
 * Reads and validates component metadata from a YAML file
 * @param filePath Path to the YAML file
 * @returns Properly structured component metadata
 */
export async function readComponentMetadata(
  filePath: string,
): Promise<Metadata | undefined> {
  try {
    const content = await fs.readFile(filePath, 'utf-8');
    // Make sure we return an object even if the file is empty
    const rawMetadata = yaml.load(content) || {};

    if (typeof rawMetadata !== 'object') {
      console.error(
        `Invalid metadata format in ${filePath}. Expected an object, got ${typeof rawMetadata}`,
      );
      return undefined;
    }

    // Basic validation and normalization
    const metadata = rawMetadata as Metadata;

    // Ensure other required fields
    if (!metadata.name) {
      metadata.name = path.basename(path.dirname(filePath));
    }
    if (!metadata.machineName) {
      metadata.machineName = path.basename(path.dirname(filePath));
    }

    if (!metadata.slots || typeof metadata.slots !== 'object') {
      metadata.slots = {};
    }

    return metadata;
  } catch (error) {
    console.error(`Error reading component metadata from ${filePath}:`, error);
    return undefined;
  }
}

/**
 * Creates a standardized component payload for API requests
 * @param params Component payload parameters
 * @returns Component payload for API
 */
export function createComponentPayload(params: {
  metadata: Metadata;
  machineName: string;
  componentName: string;
  sourceCodeJs: string;
  compiledJs: string;
  sourceCodeCss: string;
  compiledCss: string;
  importedJsComponents: string[];
  dataDependencies: DataDependencies;
}): Component {
  const {
    metadata,
    machineName,
    componentName,
    sourceCodeJs,
    compiledJs,
    sourceCodeCss,
    compiledCss,
    importedJsComponents,
    dataDependencies,
  } = params;

  // Ensure props is correctly structured
  const propsData = stripProjectedContentEntityReferencePropKeys(
    metadata.props.properties,
  );

  // Ensure slots has correct format
  let slotsData = metadata.slots || {};
  if (typeof slotsData === 'string' || Array.isArray(slotsData)) {
    slotsData = {};
  }

  const payloadDataDependencies: DataDependencies = { ...dataDependencies };
  if (metadata.dataDependencies?.entityFields) {
    payloadDataDependencies.entityFields =
      metadata.dataDependencies.entityFields;
  }

  return {
    machineName,
    name: metadata.name || componentName,
    status: metadata.status,
    required: Array.isArray(metadata.required) ? metadata.required : [],
    props: propsData,
    slots: slotsData,
    sourceCodeJs: sourceCodeJs,
    compiledJs: compiledJs,
    sourceCodeCss: sourceCodeCss,
    compiledCss: compiledCss,
    importedJsComponents: importedJsComponents || [],
    dataDependencies: payloadDataDependencies,
  };
}

/**
 * Recovers a component's Canvas imports from its source.
 *
 * The HTTP API never returns `importedJsComponents`, but requires it on write
 * whenever source is sent, so any payload read back from a site has to have it
 * derived again before it can be written anywhere.
 */
export function parseImportedJsComponents(
  sourceCodeJs: string | undefined,
  onError: (message: string) => void = () => {},
): string[] {
  try {
    const ast = parse(sourceCodeJs ?? '', {
      sourceType: 'module',
      plugins: ['jsx', 'typescript'],
    });
    return getImportsFromAst(ast, '@/components/');
  } catch (error) {
    onError(`Error: ${String(error)}`);
    return [];
  }
}
