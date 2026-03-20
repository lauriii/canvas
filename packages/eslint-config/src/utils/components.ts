import { existsSync, readdirSync } from 'fs';
import { basename, dirname } from 'path';

import type { Rule as EslintRule } from 'eslint';

const JS_EXTENSIONS = ['ts', 'tsx', 'js', 'jsx'] as const;
const NAMED_SUFFIX = '.component.yml';

export function isComponentEntrypoint(
  context: EslintRule.RuleContext,
): boolean {
  const componentDir = dirname(context.filename);
  if (!isComponentDir(componentDir)) {
    return false;
  }
  const files = getFilesInDirectory(componentDir);
  const namedMetadataFile = files.find((file) => file.endsWith(NAMED_SUFFIX));
  const componentBaseName = namedMetadataFile
    ? namedMetadataFile.slice(0, -NAMED_SUFFIX.length)
    : 'index';
  return JS_EXTENSIONS.some(
    (ext) => basename(context.filename) === componentBaseName + '.' + ext,
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
      // Determine the component entry point basename.
      const files = getFilesInDirectory(dir);
      const namedMetadataFile = files.find(
        (file) => file !== 'component.yml' && file.endsWith(NAMED_SUFFIX),
      );
      const entryBaseName = namedMetadataFile
        ? namedMetadataFile.slice(0, -NAMED_SUFFIX.length)
        : 'index';

      const importBaseName = basename(resolvedPath);

      if (importBaseName === entryBaseName) {
        return false;
      }

      if (
        JS_EXTENSIONS.some(
          (ext) => importBaseName === entryBaseName + '.' + ext,
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
