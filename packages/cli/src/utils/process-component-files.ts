import { promises as fs } from 'fs';
import path from 'path';
import * as yaml from 'js-yaml';

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
type ComponentPayloadCode = {
  sourceCodeJs: string;
  compiledJs: string;
  sourceCodeCss: string;
  compiledCss: string;
  importedJsComponents: string[];
};

type CreateComponentPayloadParams =
  | {
      metadata: Metadata;
      machineName: string;
      componentName: string;
      dataDependencies: DataDependencies;
      type: 'external';
    }
  | {
      metadata: Metadata;
      machineName: string;
      componentName: string;
      dataDependencies: DataDependencies;
      type?: Exclude<Component['type'], 'external'>;
      code: ComponentPayloadCode;
    };

export function createComponentPayload(
  params: CreateComponentPayloadParams,
): Component {
  const { metadata, machineName, componentName, dataDependencies, type } =
    params;

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

  const payload: Component = {
    machineName,
    name: metadata.name || componentName,
    status: metadata.status,
    required: Array.isArray(metadata.required) ? metadata.required : [],
    props: propsData,
    slots: slotsData,
    dataDependencies: payloadDataDependencies,
  };

  if (type !== undefined) {
    payload.type = type;
  }

  if ('code' in params) {
    const {
      sourceCodeJs,
      compiledJs,
      sourceCodeCss,
      compiledCss,
      importedJsComponents,
    } = params.code;
    payload.sourceCodeJs = sourceCodeJs;
    payload.compiledJs = compiledJs;
    payload.sourceCodeCss = sourceCodeCss;
    payload.compiledCss = compiledCss;
    payload.importedJsComponents = importedJsComponents;
  }

  return payload;
}
