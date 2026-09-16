import path from 'node:path';

import {
  ComponentMetadataValidationError,
  normalizeComponentMetadata,
  parseComponentMetadata,
  validateComponentMetadataEnvelope,
} from './metadata-validation';

import type {
  ComponentMetadata,
  DiscoveredComponent,
  DiscoveryResult,
  DiscoveryWarning,
} from './types';

function getAuthoredComponentName(value: unknown): string | null {
  if (typeof value !== 'object' || value === null || Array.isArray(value)) {
    return null;
  }
  const name = (value as Record<string, unknown>).name;
  return typeof name === 'string' && name.length > 0 ? name : null;
}

/**
 * Loads, validates, and normalizes one discovered component's metadata.
 *
 * @param component - The discovered component whose metadata should be loaded.
 * @param projectRoot - Root used to make validation error paths readable.
 * @returns Parsed and normalized component metadata.
 */
export async function loadComponentMetadata(
  component: DiscoveredComponent,
  projectRoot?: string,
): Promise<ComponentMetadata> {
  const displayPath = projectRoot
    ? path.relative(projectRoot, component.metadataPath)
    : component.metadataPath;

  let parsed: Awaited<ReturnType<typeof parseComponentMetadata>>;
  try {
    parsed = await parseComponentMetadata(component.metadataPath);
  } catch (error) {
    if (error instanceof ComponentMetadataValidationError) {
      throw new ComponentMetadataValidationError(
        displayPath,
        error.diagnostics,
        error.authoredName,
      );
    }
    throw error;
  }

  const diagnostics = validateComponentMetadataEnvelope(parsed);
  if (diagnostics.length > 0) {
    throw new ComponentMetadataValidationError(
      displayPath,
      diagnostics,
      getAuthoredComponentName(parsed.value),
    );
  }
  return normalizeComponentMetadata(parsed.value);
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
    discoveryResult.components.map((component) =>
      loadComponentMetadata(component, discoveryResult.projectRoot),
    ),
  );
}

/**
 * Detects duplicate machineName values across discovered components.
 *
 * @param components - Array of components with valid metadata.
 * @param metadata - Parallel array of component metadata.
 * @returns Array of warnings for any machineName appearing more than once.
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

  // The two arrays are positionally parallel: metadata[i] describes
  // components[i]. The guard below can therefore never skip anything; it
  // exists because consuming apps may compile this source under stricter
  // compiler settings, where indexing into an array is possibly undefined.
  components.forEach((component, i) => {
    const meta = metadata[i];
    if (!meta) {
      return;
    }
    const machineName = meta.machineName;

    const existing = byMachineName.get(machineName);
    if (existing) {
      existing.push({ component, metadata: meta });
    } else {
      byMachineName.set(machineName, [{ component, metadata: meta }]);
    }
  });

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
