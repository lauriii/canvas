import { existsSync, readdirSync } from 'fs';
import { basename, dirname } from 'path';

import type { Rule as EslintRule } from 'eslint';

const JS_EXTENSIONS = ['ts', 'tsx', 'js', 'jsx'] as const;
const NAMED_SUFFIX = '.component.yml';

export function isComponentEntrypoint(
  context: EslintRule.RuleContext,
): boolean {
  if (!isInComponentDir(context)) {
    return false;
  }
  const componentDir = dirname(context.filename);
  const files = getFilesInDirectory(componentDir);
  const namedMetadataFile = files.find((file) => file.endsWith(NAMED_SUFFIX));
  const componentBaseName = namedMetadataFile
    ? namedMetadataFile.slice(0, -NAMED_SUFFIX.length)
    : 'index';
  return JS_EXTENSIONS.some(
    (ext) => basename(context.filename) === componentBaseName + '.' + ext,
  );
}

export function isInComponentDir(context: EslintRule.RuleContext): boolean {
  try {
    const componentDir = dirname(context.filename);
    const files = getFilesInDirectory(componentDir);
    return (
      files.filter(
        (file) =>
          basename(file) === 'component.yml' || file.endsWith(NAMED_SUFFIX),
      ).length > 0
    );
  } catch {
    return false;
  }
}

/**
 * Checks if the current file in the rule context is a component definition file.
 */
export function isComponentYmlFile(context: EslintRule.RuleContext): boolean {
  try {
    const fileName = basename(context.filename);
    return fileName === 'component.yml' || fileName.endsWith(NAMED_SUFFIX);
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
