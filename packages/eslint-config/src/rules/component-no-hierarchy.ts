import { basename, dirname } from 'node:path';

import {
  getFilesInDirectory,
  isComponentYmlFile,
} from '../utils/components.js';

import type { Rule as EslintRule } from 'eslint';

function findTopmostComponentsParentDir(
  currentParentDir: string,
  rootDir: string,
): string {
  if (currentParentDir === rootDir) {
    return currentParentDir;
  }

  const parentDir = dirname(currentParentDir);
  if (hasComponentSubdirectories(parentDir)) {
    return findTopmostComponentsParentDir(parentDir, rootDir);
  }

  return currentParentDir;
}

function hasComponentSubdirectories(dirPath: string): boolean {
  const files = getFilesInDirectory(dirPath);
  for (const file of files) {
    const subdirFiles = getFilesInDirectory(dirPath + '/' + file);
    if (subdirFiles.includes('component.yml')) {
      return true;
    }
  }
  return false;
}

const rule: EslintRule.RuleModule = {
  meta: {
    type: 'problem',
    docs: {
      description:
        'Validates that all component directories are at the same level with no nesting hierarchy',
    },
  },
  create(context: EslintRule.RuleContext): EslintRule.RuleListener {
    if (!isComponentYmlFile(context)) {
      return {};
    }

    return {
      Program: function (node) {
        const currentComponentDir = dirname(context.filename);
        const componentsDir = dirname(currentComponentDir);

        const topmostComponentsDir = findTopmostComponentsParentDir(
          componentsDir,
          context.cwd,
        );

        if (topmostComponentsDir !== componentsDir) {
          const nestedComponent = basename(currentComponentDir);
          let parentComponentPath = componentsDir.replace(context.cwd, '');
          if (!parentComponentPath.startsWith('/')) {
            parentComponentPath = '/' + parentComponentPath;
          }

          context.report({
            node,
            message:
              `All component directories must be at the same level with no nesting hierarchy. ` +
              `Found "${nestedComponent}" component inside the "${parentComponentPath}" directory.`,
          });
        }
      },
    };
  },
};

export default rule;
