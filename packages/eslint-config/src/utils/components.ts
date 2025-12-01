import { existsSync, readdirSync } from 'fs';
import { basename, dirname } from 'path';

import type { Rule as EslintRule } from 'eslint';

export function isInComponentDir(context: EslintRule.RuleContext): boolean {
  try {
    const componentDir = dirname(context.filename);
    const files = getFilesInDirectory(componentDir);
    return files.includes('component.yml');
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
    return fileName === 'component.yml';
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
