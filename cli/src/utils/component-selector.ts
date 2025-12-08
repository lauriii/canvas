import path from 'path';
import * as p from '@clack/prompts';

import { getConfig } from '../config';
import { ALL_COMPONENTS_SELECTOR } from './command-helpers';
import { findComponentDirectories } from './find-component-directories';

import type { Component } from '../types/Component';

// Constants for special selectors
export const GLOBAL_CSS_SELECTOR = '__GLOBAL_CSS__';

/**
 * Helper to determine global CSS selection based on flags
 */
function determineGlobalCssSelection(
  options: ComponentSelectorOptions,
): boolean | undefined {
  // If --css-only is specified, only global CSS should be included
  if (options.cssOnly) {
    return true;
  }

  // If --skip-css is specified, global CSS should be excluded
  if (options.skipCss) {
    return false;
  }

  // If includeGlobalCss is not set, return undefined (no global CSS handling)
  if (!options.includeGlobalCss) {
    return undefined;
  }

  // Use default value or true if not specified
  return options.globalCssDefault !== false;
}

export interface ComponentSelectorOptions {
  // Selection criteria
  all?: boolean;
  components?: string; // comma-separated
  skipConfirmation?: boolean; // --yes flag
  skipCss?: boolean; // Skip global CSS
  cssOnly?: boolean; // Only global CSS

  // Customization
  selectMessage?: string;
  confirmMessage?: string;
  notFoundMessage?: string;
  includeGlobalCss?: boolean; // Include global CSS in selection
  globalCssDefault?: boolean; // Default global CSS selection state

  // Context
  componentDir?: string; // for local selection
}

export interface LocalSelectorResult {
  directories: string[];
  includeGlobalCss?: boolean;
}

export interface RemoteSelectorResult {
  components: Record<string, Component>;
  includeGlobalCss?: boolean;
}

/**
 * Unified component selection for LOCAL components (build, upload)
 */
export async function selectLocalComponents(
  options: ComponentSelectorOptions,
): Promise<LocalSelectorResult> {
  const config = getConfig();
  const componentDir = options.componentDir || config.componentDir;

  // Determine global CSS selection
  const globalCssSelection = determineGlobalCssSelection(options);

  // Handle --css-only flag (skip component discovery)
  if (options.cssOnly) {
    return {
      directories: [],
      includeGlobalCss: true,
    };
  }

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
      globalCssSelection,
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
    return {
      directories: allLocalDirs,
      includeGlobalCss: globalCssSelection,
    };
  }

  // Mode 3: Interactive selection
  return selectLocalComponentsInteractive(
    allLocalDirs,
    options,
    globalCssSelection,
  );
}

/**
 * Handle --components flag for local components
 */
async function selectSpecificLocalComponents(
  componentsInput: string,
  allLocalDirs: string[],
  options: ComponentSelectorOptions,
  globalCssSelection?: boolean,
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

  return {
    directories: foundDirs,
    includeGlobalCss: globalCssSelection,
  };
}

/**
 * Interactive component selection (no flags)
 */
async function selectLocalComponentsInteractive(
  allLocalDirs: string[],
  options: ComponentSelectorOptions,
  globalCssSelection?: boolean,
): Promise<LocalSelectorResult> {
  const multiSelectOptions = [
    {
      value: ALL_COMPONENTS_SELECTOR,
      label: 'All components',
    },
  ];

  // Add global CSS option right after "All components" if enabled
  if (options.includeGlobalCss) {
    multiSelectOptions.push({
      value: GLOBAL_CSS_SELECTOR,
      label: 'Global CSS',
    });
  }

  // Add individual components
  multiSelectOptions.push(
    ...allLocalDirs.map((dir) => ({
      value: dir,
      label: path.basename(dir),
    })),
  );

  const selectedItems = await p.multiselect({
    message: options.selectMessage || 'Select items',
    options: multiSelectOptions,
    initialValues:
      options.includeGlobalCss && options.globalCssDefault !== false
        ? [GLOBAL_CSS_SELECTOR]
        : [],
    required: true,
  });

  if (p.isCancel(selectedItems)) {
    throw new Error('Operation cancelled by user');
  }

  // Determine final selections
  const includesAllComponents = (selectedItems as string[]).includes(
    ALL_COMPONENTS_SELECTOR,
  );
  const includesGlobalCss = (selectedItems as string[]).includes(
    GLOBAL_CSS_SELECTOR,
  );

  const finalDirs = includesAllComponents
    ? allLocalDirs
    : (selectedItems as string[]).filter(
        (item) =>
          item !== ALL_COMPONENTS_SELECTOR && item !== GLOBAL_CSS_SELECTOR,
      );

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

  // Use interactive selection result or fallback to parameter
  const finalGlobalCss = options.includeGlobalCss
    ? includesGlobalCss
    : globalCssSelection;

  return {
    directories: finalDirs,
    includeGlobalCss: finalGlobalCss,
  };
}

/**
 * Unified component selection for REMOTE components (download)
 */
export async function selectRemoteComponents(
  allComponents: Record<string, Component>,
  options: ComponentSelectorOptions,
): Promise<RemoteSelectorResult> {
  const componentCount = Object.keys(allComponents).length;

  // Determine global CSS selection
  const globalCssSelection = determineGlobalCssSelection(options);

  // Handle --css-only flag (skip component discovery)
  if (options.cssOnly) {
    return {
      components: {},
      includeGlobalCss: true,
    };
  }

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
    return {
      components: allComponents,
      includeGlobalCss: globalCssSelection,
    };
  }

  // Mode 2: --components flag
  if (options.components) {
    return selectSpecificRemoteComponents(
      options.components,
      allComponents,
      options,
      globalCssSelection,
    );
  }

  // Mode 3: Interactive selection
  return selectRemoteComponentsInteractive(
    allComponents,
    options,
    globalCssSelection,
  );
}

/**
 * Handle --components flag for remote components
 */
async function selectSpecificRemoteComponents(
  componentsInput: string,
  allComponents: Record<string, Component>,
  options: ComponentSelectorOptions,
  globalCssSelection?: boolean,
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

  return {
    components: selected,
    includeGlobalCss: globalCssSelection,
  };
}

/**
 * Interactive remote component selection
 */
async function selectRemoteComponentsInteractive(
  allComponents: Record<string, Component>,
  options: ComponentSelectorOptions,
  globalCssSelection?: boolean,
): Promise<RemoteSelectorResult> {
  const multiSelectOptions = [
    {
      value: ALL_COMPONENTS_SELECTOR,
      label: 'All components',
    },
  ];

  // Add global CSS option right after "All components" if enabled
  if (options.includeGlobalCss) {
    multiSelectOptions.push({
      value: GLOBAL_CSS_SELECTOR,
      label: 'Global CSS',
    });
  }

  // Add individual components
  multiSelectOptions.push(
    ...Object.keys(allComponents).map((key) => ({
      value: allComponents[key].machineName,
      label: `${allComponents[key].name} (${allComponents[key].machineName})`,
    })),
  );

  const selectedItems = await p.multiselect({
    message: options.selectMessage || 'Select items to download',
    options: multiSelectOptions,
    initialValues:
      options.includeGlobalCss && options.globalCssDefault !== false
        ? [GLOBAL_CSS_SELECTOR]
        : [],
    required: true,
  });

  if (p.isCancel(selectedItems)) {
    throw new Error('Operation cancelled by user');
  }

  // Determine final selections
  const includesAllComponents = (selectedItems as string[]).includes(
    ALL_COMPONENTS_SELECTOR,
  );
  const includesGlobalCss = (selectedItems as string[]).includes(
    GLOBAL_CSS_SELECTOR,
  );

  const selected = includesAllComponents
    ? allComponents
    : Object.fromEntries(
        Object.entries(allComponents).filter(([, component]) =>
          (selectedItems as string[]).includes(component.machineName),
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

  // Use interactive selection result or fallback to parameter
  const finalGlobalCss = options.includeGlobalCss
    ? includesGlobalCss
    : globalCssSelection;

  return {
    components: selected,
    includeGlobalCss: finalGlobalCss,
  };
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
