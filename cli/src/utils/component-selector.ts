import path from 'path';
import * as p from '@clack/prompts';

import { getConfig } from '../config';
import { ALL_COMPONENTS_SELECTOR } from './command-helpers';
import { findComponentDirectories } from './find-component-directories';

import type { Component } from '../types/Component';

export interface ComponentSelectorOptions {
  // Selection criteria
  all?: boolean;
  components?: string; // comma-separated
  skipConfirmation?: boolean; // --yes flag

  // Customization
  selectMessage?: string;
  confirmMessage?: string;
  notFoundMessage?: string;

  // Context
  componentDir?: string; // for local selection
}

export interface LocalSelectorResult {
  directories: string[];
}

export interface RemoteSelectorResult {
  components: Record<string, Component>;
}

/**
 * Unified component selection for LOCAL components (build, upload)
 */
export async function selectLocalComponents(
  options: ComponentSelectorOptions,
): Promise<LocalSelectorResult> {
  const config = getConfig();
  const componentDir = options.componentDir || config.componentDir;

  // Find all local component directories
  const allLocalDirs = await findComponentDirectories(componentDir);

  if (allLocalDirs.length === 0) {
    throw new Error(`No local components were found in ${componentDir}`);
  }

  // Mode 1: --components flag with specific names
  if (options.components) {
    return selectSpecificLocalComponents(
      options.components,
      allLocalDirs,
      options,
    );
  }

  // Mode 2: --all flag
  if (options.all) {
    if (!options.skipConfirmation) {
      const confirmed = await confirmSelection(
        allLocalDirs.length,
        options.confirmMessage,
      );
      if (!confirmed) {
        throw new Error('Operation cancelled by user');
      }
    }
    p.log.info(`Selected all components`);
    return { directories: allLocalDirs };
  }

  // Mode 3: Interactive selection
  return selectLocalComponentsInteractive(allLocalDirs, options);
}

/**
 * Handle --components flag for local components
 */
async function selectSpecificLocalComponents(
  componentsInput: string,
  allLocalDirs: string[],
  options: ComponentSelectorOptions,
): Promise<LocalSelectorResult> {
  // Parse comma-separated names
  const requestedNames = componentsInput
    .split(',')
    .map((name) => name.trim())
    .filter((name) => name.length > 0);

  const notFound: string[] = [];
  const foundDirs: string[] = [];

  for (const requestedName of requestedNames) {
    const dir = allLocalDirs.find((d) => path.basename(d) === requestedName);
    if (dir) {
      foundDirs.push(dir);
    } else {
      notFound.push(requestedName);
    }
  }

  // Report not found components
  if (notFound.length > 0) {
    const message =
      options.notFoundMessage ||
      `The following component(s) were not found locally: ${notFound.join(', ')}`;
    throw new Error(message);
  }

  // Skip confirmation if --yes flag is set
  if (!options.skipConfirmation) {
    const confirmed = await confirmSelection(
      foundDirs.length,
      options.confirmMessage,
    );
    if (!confirmed) {
      throw new Error('Operation cancelled by user');
    }
  }

  return { directories: foundDirs };
}

/**
 * Interactive component selection (no flags)
 */
async function selectLocalComponentsInteractive(
  allLocalDirs: string[],
  options: ComponentSelectorOptions,
): Promise<LocalSelectorResult> {
  const selectedDirs = await p.multiselect({
    message: options.selectMessage || 'Select components',
    options: [
      {
        value: ALL_COMPONENTS_SELECTOR,
        label: 'All components',
      },
      ...allLocalDirs.map((dir) => ({
        value: dir,
        label: path.basename(dir),
      })),
    ],
    required: true,
  });

  if (p.isCancel(selectedDirs)) {
    throw new Error('Operation cancelled by user');
  }

  // Determine final selection
  const finalDirs = selectedDirs.includes(ALL_COMPONENTS_SELECTOR)
    ? allLocalDirs
    : (selectedDirs as string[]);

  // Confirm selection
  if (!options.skipConfirmation) {
    const confirmed = await confirmSelection(
      finalDirs.length,
      options.confirmMessage,
    );
    if (!confirmed) {
      throw new Error('Operation cancelled by user');
    }
  }

  return { directories: finalDirs };
}

/**
 * Unified component selection for REMOTE components (download)
 */
export async function selectRemoteComponents(
  allComponents: Record<string, Component>,
  options: ComponentSelectorOptions,
): Promise<RemoteSelectorResult> {
  const componentCount = Object.keys(allComponents).length;

  if (componentCount === 0) {
    throw new Error('No components found');
  }

  // Mode 1: --all flag
  if (options.all) {
    if (!options.skipConfirmation) {
      const confirmed = await confirmSelection(
        componentCount,
        options.confirmMessage,
      );
      if (!confirmed) {
        throw new Error('Operation cancelled by user');
      }
    }
    return { components: allComponents };
  }

  // Mode 2: --components flag
  if (options.components) {
    return selectSpecificRemoteComponents(
      options.components,
      allComponents,
      options,
    );
  }

  // Mode 3: Interactive selection
  return selectRemoteComponentsInteractive(allComponents, options);
}

/**
 * Handle --components flag for remote components
 */
async function selectSpecificRemoteComponents(
  componentsInput: string,
  allComponents: Record<string, Component>,
  options: ComponentSelectorOptions,
): Promise<RemoteSelectorResult> {
  // Parse comma-separated names
  const requestedNames = componentsInput
    .split(',')
    .map((name) => name.trim())
    .filter((name) => name.length > 0);

  const notFound: string[] = [];
  const selected: Record<string, Component> = {};

  for (const requestedName of requestedNames) {
    const component = allComponents[requestedName];
    if (component) {
      selected[requestedName] = component;
    } else {
      notFound.push(requestedName);
    }
  }

  // Report not found components
  if (notFound.length > 0) {
    const message =
      options.notFoundMessage ||
      `The following component(s) were not found: ${notFound.join(', ')}`;
    throw new Error(message);
  }

  // Skip confirmation if --yes flag is set
  if (!options.skipConfirmation) {
    const confirmed = await confirmSelection(
      Object.keys(selected).length,
      options.confirmMessage,
    );
    if (!confirmed) {
      throw new Error('Operation cancelled by user');
    }
  }

  return { components: selected };
}

/**
 * Interactive remote component selection
 */
async function selectRemoteComponentsInteractive(
  allComponents: Record<string, Component>,
  options: ComponentSelectorOptions,
): Promise<RemoteSelectorResult> {
  const selectedMachineNames = await p.multiselect({
    message: options.selectMessage || 'Select components to download',
    options: [
      {
        value: ALL_COMPONENTS_SELECTOR,
        label: 'All components',
      },
      ...Object.keys(allComponents).map((key) => ({
        value: allComponents[key].machineName,
        label: `${allComponents[key].name} (${allComponents[key].machineName})`,
      })),
    ],
    required: true,
  });

  if (p.isCancel(selectedMachineNames)) {
    throw new Error('Operation cancelled by user');
  }

  // Determine final selection
  const selected = (selectedMachineNames as string[]).includes(
    ALL_COMPONENTS_SELECTOR,
  )
    ? allComponents
    : Object.fromEntries(
        Object.entries(allComponents).filter(([, component]) =>
          (selectedMachineNames as string[]).includes(component.machineName),
        ),
      );

  // Confirm selection
  if (!options.skipConfirmation) {
    const confirmed = await confirmSelection(
      Object.keys(selected).length,
      options.confirmMessage,
    );
    if (!confirmed) {
      throw new Error('Operation cancelled by user');
    }
  }

  return { components: selected };
}

/**
 * Helper to confirm component selection
 */
async function confirmSelection(
  count: number,
  customMessage?: string,
): Promise<boolean> {
  const componentLabel = count === 1 ? 'component' : 'components';
  const message = customMessage || `Process ${count} ${componentLabel}?`;

  const confirmed = await p.confirm({
    message,
    initialValue: true,
  });

  if (p.isCancel(confirmed) || !confirmed) {
    return false;
  }

  return true;
}
