import { basename, dirname, isAbsolute, relative, resolve } from 'node:path';
import { resolveCanvasConfig } from '@drupal-canvas/discovery';

import { isComponentYmlFile } from '../utils/components.js';

import type { Rule as EslintRule } from 'eslint';

function toPosixPath(value: string): string {
  return value.split('\\').join('/');
}

function isNestedInsideComponentRoot(
  componentDir: string,
  componentRoot: string,
): boolean {
  const relativeDir = relative(componentRoot, componentDir);
  if (
    relativeDir === '' ||
    relativeDir.startsWith('..') ||
    isAbsolute(relativeDir)
  ) {
    return false;
  }

  return toPosixPath(relativeDir).split('/').length > 1;
}

const rule: EslintRule.RuleModule = {
  meta: {
    type: 'problem',
    docs: {
      description:
        'Validates that component directories are direct children of the configured componentDir',
    },
  },
  create(context: EslintRule.RuleContext): EslintRule.RuleListener {
    if (!isComponentYmlFile(context.filename)) {
      return {};
    }

    const canvasConfig = resolveCanvasConfig({ hostRoot: context.cwd });
    const componentRoot = resolve(context.cwd, canvasConfig.componentDir);

    return {
      Program: function (node) {
        const currentComponentDir = dirname(context.filename);
        if (isNestedInsideComponentRoot(currentComponentDir, componentRoot)) {
          const nestedComponent = basename(currentComponentDir);
          const parentComponentPath = toPosixPath(
            relative(context.cwd, dirname(currentComponentDir)),
          );

          context.report({
            node,
            message:
              `Component directories must be direct children of configured componentDir "${canvasConfig.componentDir}". ` +
              `Found "${nestedComponent}" inside "${parentComponentPath}".`,
          });
        }
      },
    };
  },
};

export default rule;
