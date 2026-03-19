import { promises as fs } from 'node:fs';
import { load as parseYaml } from 'js-yaml';

import type {
  ComponentMetadata,
  DiscoveredComponent,
  DiscoveryResult,
  DiscoveryWarning,
} from './types';

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function validateRawMetadata(
  raw: unknown,
  metadataPath: string,
): asserts raw is Record<string, unknown> {
  if (!isRecord(raw)) {
    throw new Error(
      `Invalid component metadata in ${metadataPath}: expected an object, got ${typeof raw}.`,
    );
  }

  if (raw.machineName !== undefined && typeof raw.machineName !== 'string') {
    throw new Error(
      `Invalid "machineName" in ${metadataPath}: expected a string, got ${typeof raw.machineName}.`,
    );
  }

  const isEmptyPropsArray = Array.isArray(raw.props) && raw.props.length === 0;
  if (raw.props !== undefined && !isRecord(raw.props) && !isEmptyPropsArray) {
    throw new Error(
      `Invalid "props" in ${metadataPath}: expected an object or empty array, got ${typeof raw.props}.`,
    );
  }

  if (raw.required !== undefined && !Array.isArray(raw.required)) {
    throw new Error(
      `Invalid "required" in ${metadataPath}: expected an array, got ${typeof raw.required}.`,
    );
  }

  if (raw.status !== undefined && typeof raw.status !== 'boolean') {
    throw new Error(
      `Invalid "status" in ${metadataPath}: expected a boolean, got ${typeof raw.status}.`,
    );
  }

  // Allow empty array as equivalent to no slots.
  const isEmptyArray = Array.isArray(raw.slots) && raw.slots.length === 0;
  if (raw.slots !== undefined && !isRecord(raw.slots) && !isEmptyArray) {
    throw new Error(
      `Invalid "slots" in ${metadataPath}: expected an object, got ${typeof raw.slots}.`,
    );
  }

  if (isRecord(raw.slots)) {
    for (const [slotName, slotValue] of Object.entries(raw.slots)) {
      if (!isRecord(slotValue)) {
        throw new Error(
          `Invalid slot "${slotName}" in ${metadataPath}: expected an object, got ${typeof slotValue}.`,
        );
      }

      if (typeof slotValue.title !== 'string') {
        throw new Error(
          `Missing or invalid "title" in slot "${slotName}" in ${metadataPath}: expected a string, got ${typeof slotValue.title}.`,
        );
      }

      if (
        slotValue.description !== undefined &&
        typeof slotValue.description !== 'string'
      ) {
        throw new Error(
          `Invalid "description" in slot "${slotName}" in ${metadataPath}: expected a string, got ${typeof slotValue.description}.`,
        );
      }

      if (
        slotValue.examples !== undefined &&
        !Array.isArray(slotValue.examples)
      ) {
        throw new Error(
          `Invalid "examples" in slot "${slotName}" in ${metadataPath}: expected an array, got ${typeof slotValue.examples}.`,
        );
      }
    }
  }
}

/**
 * Loads and parses component metadata from YAML files for all discovered
 * components.
 *
 * @param discoveryResult - Discovery result from `discoverCanvasProject()`
 * @returns Array of parsed component metadata
 */
export async function loadComponentsMetadata(
  discoveryResult: DiscoveryResult,
): Promise<ComponentMetadata[]> {
  return Promise.all(
    discoveryResult.components.map(async (component) => {
      const yamlContent = await fs.readFile(component.metadataPath, 'utf-8');
      const raw = parseYaml(yamlContent);

      validateRawMetadata(raw, component.metadataPath);

      const rawProps =
        isRecord(raw.props) && isRecord(raw.props.properties)
          ? (raw.props.properties as ComponentMetadata['props']['properties'])
          : {};

      const metadata: ComponentMetadata = {
        name: component.name,
        machineName: (raw.machineName as string) ?? component.name,
        status: (raw.status as boolean) ?? true,
        props: { properties: rawProps },
        required: (raw.required as string[]) ?? [],
        slots: (raw.slots as ComponentMetadata['slots']) ?? {},
      };

      return metadata;
    }),
  );
}

/**
 * Detects duplicate machineName values across discovered components.
 *
 * @param components - Array of discovered components (from discovery result)
 * @param metadata - Parallel array of component metadata (from loadComponentsMetadata)
 * @returns Array of warnings for any machineName appearing more than once
 */
export function findDuplicateMachineNames(
  components: DiscoveredComponent[],
  metadata: ComponentMetadata[],
): DiscoveryWarning[] {
  // Group components by machineName
  const byMachineName = new Map<
    string,
    Array<{ component: DiscoveredComponent; metadata: ComponentMetadata }>
  >();

  for (let i = 0; i < components.length; i++) {
    const component = components[i];
    const meta = metadata[i];
    const machineName = meta.machineName;

    const existing = byMachineName.get(machineName);
    if (existing) {
      existing.push({ component, metadata: meta });
    } else {
      byMachineName.set(machineName, [{ component, metadata: meta }]);
    }
  }

  // Generate warnings for duplicates
  const warnings: DiscoveryWarning[] = [];

  for (const [machineName, entries] of byMachineName) {
    if (entries.length > 1) {
      const paths = entries
        .map((e) => e.component.relativeDirectory)
        .join(', ');
      warnings.push({
        code: 'duplicate_machine_name',
        message: `Duplicate machineName "${machineName}" found in ${entries.length} components: ${paths}`,
      });
    }
  }

  return warnings;
}
