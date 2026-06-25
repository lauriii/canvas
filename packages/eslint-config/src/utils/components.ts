import { existsSync, readdirSync } from 'fs';
import { basename, dirname } from 'path';

import type { Rule as EslintRule } from 'eslint';

const JS_EXTENSIONS = ['ts', 'tsx', 'js', 'jsx'] as const;
const NAMED_SUFFIX = '.component.yml';

function getNamedComponentBaseNames(files: string[]): string[] {
  return files
    .filter((file) => file !== 'component.yml' && file.endsWith(NAMED_SUFFIX))
    .map((file) => file.slice(0, -NAMED_SUFFIX.length));
}

function getComponentEntrypointBaseNames(files: string[]): string[] {
  const namedBaseNames = getNamedComponentBaseNames(files);
  if (namedBaseNames.length > 0) {
    return namedBaseNames;
  }

  return files.includes('component.yml') ? ['index'] : [];
}

function stripJsExtension(fileName: string): string {
  for (const ext of JS_EXTENSIONS) {
    const suffix = `.${ext}`;
    if (fileName.endsWith(suffix)) {
      return fileName.slice(0, -suffix.length);
    }
  }

  return fileName;
}

function isEntrypointFileName(
  fileName: string,
  entrypointBaseNames: string[],
  allowExtensionless: boolean,
): boolean {
  return entrypointBaseNames.some((baseName) => {
    if (allowExtensionless && fileName === baseName) {
      return true;
    }

    return JS_EXTENSIONS.some((ext) => fileName === `${baseName}.${ext}`);
  });
}

export function isComponentEntrypoint(
  context: EslintRule.RuleContext,
): boolean {
  const componentDir = dirname(context.filename);
  if (!isComponentDir(componentDir)) {
    return false;
  }
  const files = getFilesInDirectory(componentDir);
  return isEntrypointFileName(
    basename(context.filename),
    getComponentEntrypointBaseNames(files),
    false,
  );
}

/**
 * Checks if a directory contains a component.yml or *.component.yml file.
 */
export function isComponentDir(dirPath: string): boolean {
  try {
    const files = getFilesInDirectory(dirPath);
    return files.some((file) => isComponentYmlFile(file));
  } catch {
    return false;
  }
}

/**
 * Checks if a file name is a component definition file
 * (component.yml or *.component.yml).
 */
export function isComponentYmlFile(filePath: string): boolean {
  const fileName = basename(filePath);
  return fileName === 'component.yml' || fileName.endsWith(NAMED_SUFFIX);
}

/**
 * Checks if a resolved import path targets an internal file within
 * a component directory (not the component's entry point) or
 * subdirectories nested inside component dirs.
 */
export function isNonComponentImportFromComponentDir(
  resolvedPath: string,
  aliasBaseDir: string,
): boolean {
  try {
    const dir = dirname(resolvedPath);

    // Check immediate parent first — this handles direct imports from a
    // component dir (e.g. @/components/card/utils).
    if (isComponentDir(dir)) {
      const files = getFilesInDirectory(dir);
      if (
        isEntrypointFileName(
          basename(resolvedPath),
          getComponentEntrypointBaseNames(files),
          true,
        )
      ) {
        return false;
      }

      return true;
    }

    // Walk up ancestor directories to catch imports from subdirectories
    // nested inside component dirs (e.g. @/components/card/utils/helper).
    let current = dir;
    let parent = dirname(current);
    while (parent !== current && parent.startsWith(aliasBaseDir)) {
      if (isComponentDir(parent)) {
        return true;
      }
      current = parent;
      parent = dirname(current);
    }

    return false;
  } catch {
    return false;
  }
}

/**
 * Checks if a resolved import path targets a named component entry point in a
 * flat component directory.
 */
export function isNamedComponentEntrypointInDirectory(
  resolvedPath: string,
): boolean {
  try {
    const dir = dirname(resolvedPath);
    const files = getFilesInDirectory(dir);
    const importFileName = basename(resolvedPath);
    const importBaseName = stripJsExtension(importFileName);
    const hasNamedMetadata = files.includes(`${importBaseName}${NAMED_SUFFIX}`);

    if (!hasNamedMetadata) {
      return false;
    }

    if (importFileName !== importBaseName) {
      return files.includes(importFileName);
    }

    return JS_EXTENSIONS.some((ext) =>
      files.includes(`${importBaseName}.${ext}`),
    );
  } catch {
    return false;
  }
}

export function getFilesInDirectory(dirPath: string): string[] {
  if (!existsSync(dirPath)) {
    return [];
  }

  try {
    return readdirSync(dirPath);
  } catch {
    return [];
  }
}
