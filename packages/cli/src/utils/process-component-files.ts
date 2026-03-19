import { promises as fs, readdirSync } from 'fs';
import path from 'path';
import * as yaml from 'js-yaml';
import { JS_EXTENSIONS } from '@drupal-canvas/discovery';

import { getConfig } from '../config';

import type { Component, DataDependencies } from '../types/Component';
import type { Metadata } from '../types/Metadata';

const NAMED_SUFFIX = '.component.yml';

/**
 * Get files in a directory
 * @param dirPath Directory path
 * @returns Array of file names in the directory
 */
function getFilesInDirectory(dirPath: string): string[] {
  try {
    return readdirSync(dirPath);
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    throw new Error(
      `Failed to read component directory "${dirPath}": ${message}`,
    );
  }
}

/**
 * Find the JS/TS entry point file for a component
 * Supports both named components ([name].component.yml -> [name].{jsx,tsx,js,ts})
 * and index-based components (component.yml -> index.{jsx,tsx,js,ts})
 * @param componentDir Component directory path
 * @returns Path to the JS/TS entry point file, or null if not found
 */
export function findJsEntryPoint(componentDir: string): string | null {
  const files = getFilesInDirectory(componentDir);

  // Check for named metadata file (e.g., MyComponent.component.yml)
  const namedMetadataFile = files.find((file) => file.endsWith(NAMED_SUFFIX));
  const componentBaseName = namedMetadataFile
    ? namedMetadataFile.slice(0, -NAMED_SUFFIX.length)
    : 'index';

  // Find the first matching JS/TS file
  for (const ext of JS_EXTENSIONS) {
    const fileName = `${componentBaseName}${ext}`;
    if (files.includes(fileName)) {
      return path.join(componentDir, fileName);
    }
  }

  return null;
}

/**
 * Find the CSS entry point file for a component
 * Supports both named components and index-based components
 * @param componentDir Component directory path
 * @returns Path to the CSS entry point file, or null if not found
 */
export function findCssEntryPoint(componentDir: string): string | null {
  const files = getFilesInDirectory(componentDir);

  // Check for named metadata file (e.g., MyComponent.component.yml)
  const namedMetadataFile = files.find((file) => file.endsWith(NAMED_SUFFIX));
  const componentBaseName = namedMetadataFile
    ? namedMetadataFile.slice(0, -NAMED_SUFFIX.length)
    : 'index';

  const cssFileName = `${componentBaseName}.css`;
  if (files.includes(cssFileName)) {
    return path.join(componentDir, cssFileName);
  }

  return null;
}

/**
 * Process and read component files
 * @param componentDir Component directory path
 * @param componentName Optional component name for locating build output
 * @param componentKind Optional component kind ('index' or 'named')
 * @returns Processed component files and paths
 */
export async function processComponentFiles(
  componentDir: string,
  componentName?: string,
  componentKind: 'index' | 'named' = 'index',
): Promise<{
  sourceCodeJs: string;
  compiledJs: string;
  sourceCodeCss: string;
  compiledCss: string;
  metadata: Metadata | undefined;
}> {
  const config = getConfig();
  const metadataPath = findMetadataPath(componentDir);
  const metadata = await readComponentMetadata(metadataPath);

  // Find and read the JS/TS entry point
  const jsEntryPath = findJsEntryPoint(componentDir);
  if (!jsEntryPath) {
    throw new Error(
      `No JS/TS entry point found in ${componentDir}. Expected [name].{jsx,tsx,js,ts} or index.{jsx,tsx,js,ts}`,
    );
  }
  const sourceCodeJs = await fs.readFile(jsEntryPath, 'utf-8');

  // Determine the build output directory and filename
  // buildComponent outputs to: outputDir/components/[name]/[outputBaseName].js
  const name = componentName || path.basename(componentDir);
  const outputBaseName = componentKind === 'index' ? 'index' : name;
  const distDir = path.join(config.outputDir, 'components', name);

  const compiledJs = await fs.readFile(
    path.join(distDir, `${outputBaseName}.js`),
    'utf-8',
  );

  let sourceCodeCss = '';
  let compiledCss = '';

  // Find and read the CSS entry point if it exists
  const cssEntryPath = findCssEntryPoint(componentDir);
  if (cssEntryPath) {
    try {
      sourceCodeCss = await fs.readFile(cssEntryPath, 'utf-8');
      // If source CSS exists, compiled CSS should also exist
      compiledCss = await fs.readFile(
        path.join(distDir, `${outputBaseName}.css`),
        'utf-8',
      );
    } catch {
      // CSS files don't exist or compilation failed, use empty strings
    }
  }

  return {
    sourceCodeJs,
    compiledJs,
    sourceCodeCss,
    compiledCss,
    metadata,
  };
}

/**
 * Find the component metadata file
 * Supports both component.yml and [name].component.yml
 * @param componentDir Component directory path
 * @returns Path to the found metadata file, or empty string if not found
 */
export function findMetadataPath(componentDir: string): string {
  const files = getFilesInDirectory(componentDir);

  // First check for named metadata file (e.g., my-component.component.yml)
  const namedMetadataFile = files.find((file) => file.endsWith(NAMED_SUFFIX));
  if (namedMetadataFile) {
    return path.join(componentDir, namedMetadataFile);
  }

  // Fall back to component.yml
  if (files.includes('component.yml')) {
    return path.join(componentDir, 'component.yml');
  }

  return '';
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
  const propsData = metadata.props.properties;

  // Ensure slots has correct format
  let slotsData = metadata.slots || {};
  if (typeof slotsData === 'string' || Array.isArray(slotsData)) {
    slotsData = {};
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
    dataDependencies: dataDependencies || {},
  };
}
