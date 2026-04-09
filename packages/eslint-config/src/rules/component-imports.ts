import { dirname, resolve } from 'path';
import {
  ASSET_EXTENSIONS,
  resolveCanvasConfig,
} from '@drupal-canvas/discovery';

import {
  isComponentDir,
  isNonComponentImportFromComponentDir,
} from '../utils/components.js';

import type { Rule as EslintRule } from 'eslint';
import type { ImportDeclaration, ImportExpression } from 'estree';

function checkImportSource(
  context: EslintRule.RuleContext,
  node: ImportDeclaration | ImportExpression,
  source: string,
): void {
  // Font package imports are not supported.
  if (source.startsWith('@fontsource')) {
    context.report({
      node,
      message: `Importing font packages ("${source}") is not supported in components.`,
    });
    return;
  }

  // Asset imports (images, video, SVG, fonts, etc.) are not supported in components.
  if (ASSET_EXTENSIONS.some((ext) => source.endsWith(ext))) {
    context.report({
      node,
      message: `Importing asset files ("${source}") is not supported in components.`,
    });
    return;
  }

  // CSS side-effect imports are not supported in components.
  if (source.endsWith('.css') || /\/css(\/|$)/.test(source)) {
    context.report({
      node,
      message: `CSS side-effect imports are not supported in components. Remove "${source}" and use the component's CSS file instead.`,
    });
    return;
  }

  // Relative imports are not supported in Drupal Canvas.
  if (source.startsWith('./') || source.startsWith('../')) {
    context.report({
      node,
      message: `Relative imports are not supported. Use '@/...' alias instead of '${source}' to import other components or helpers/utilities from shared locations outside component dir.`,
    });
    return;
  }

  if (source === 'next-image-standalone') {
    context.report({
      node,
      message:
        'Using `next-image-standalone` directly is deprecated. Use the `Image` component from the `drupal-canvas` package instead.',
      fix(fixer) {
        if (
          node.type === 'ImportDeclaration' &&
          node.specifiers.length === 1 &&
          node.specifiers[0].local.name === 'Image'
        ) {
          return fixer.replaceText(
            node,
            "import { Image } from 'drupal-canvas';",
          );
        }
        return null;
      },
    });
    return;
  }

  if (source === '@drupal-api-client/json-api-client') {
    if (node.type === 'ImportDeclaration') {
      for (const specifier of node.specifiers) {
        if (specifier.local.name === 'JsonApiClient') {
          context.report({
            node: specifier.local,
            message:
              'The preconfigured `JsonApiClient` was moved into the `drupal-canvas` package.',
            fix(fixer) {
              return fixer.replaceText(node.source, "'drupal-canvas'");
            },
          });
          return;
        }
      }
    }
    return;
  }

  if (source === '@/lib/FormattedText') {
    context.report({
      node,
      message:
        'The `FormattedText` component was moved into the `drupal-canvas` package. The `@/lib/FormattedText` path is provided by Canvas and cannot be used for local files.',
      fix(fixer) {
        if (
          node.type === 'ImportDeclaration' &&
          node.specifiers.length === 1 &&
          node.specifiers[0].local.name === 'FormattedText'
        ) {
          return fixer.replaceText(
            node,
            "import { FormattedText } from 'drupal-canvas';",
          );
        }
        return null;
      },
    });
    return;
  }

  if (source === '@/lib/utils') {
    context.report({
      node,
      message:
        'Utilities were moved into the `drupal-canvas` package. The `@/lib/utils` path is provided by Canvas and cannot be used for local files.',
      fix(fixer) {
        return fixer.replaceText(node.source, "'drupal-canvas'");
      },
    });
    return;
  }

  if (source === '@/lib/jsonapi-utils') {
    context.report({
      node,
      message:
        'JSON:API utilities were moved into the `drupal-canvas` package. The `@/lib/jsonapi-utils` path is provided by Canvas and cannot be used for local files.',
      fix(fixer) {
        return fixer.replaceText(node.source, "'drupal-canvas'");
      },
    });
    return;
  }

  if (source === '@/lib/drupal-utils') {
    context.report({
      node,
      message:
        'Drupal utilities were moved into the `drupal-canvas` package. The `@/lib/drupal-utils` path is provided by Canvas and cannot be used for local files.',
      fix(fixer) {
        // 'sortMenu' exported from drupal-canvas is named 'sortLinksetMenu'
        // to avoid conflict with sortMenu from jsonapi-utils,
        // so automatic fix should not apply if sortMenu is imported.
        const importsSortMenu =
          node.type === 'ImportDeclaration' &&
          node.specifiers.some(
            (specifier) =>
              specifier.local.name === 'sortMenu' ||
              (specifier.type === 'ImportSpecifier' &&
                specifier.imported.type === 'Identifier' &&
                specifier.imported.name === 'sortMenu'),
          );
        if (!importsSortMenu) {
          return fixer.replaceText(node.source, "'drupal-canvas'");
        }
        return null;
      },
    });
    return;
  }

  // @/ imports for utils/helpers are allowed from shared locations,
  // but not from component directories.
  if (source.startsWith('@/')) {
    const suffix = source.slice(2);
    const config = resolveCanvasConfig({ hostRoot: context.cwd });
    const aliasBase = resolve(context.cwd, config.aliasBaseDir);
    const resolvedPath = resolve(aliasBase, suffix);

    if (isNonComponentImportFromComponentDir(resolvedPath, aliasBase)) {
      context.report({
        node,
        message:
          `Importing "${source}" from a component directory is not supported. ` +
          'Use "@/" alias to import other components or helpers/utilities from shared locations outside component directories.',
      });
      return;
    }

    return;
  }
}

const rule: EslintRule.RuleModule = {
  meta: {
    type: 'problem',
    docs: {
      description:
        'Validates that component imports only from supported import sources and patterns',
    },
    fixable: 'code',
  },
  create(context: EslintRule.RuleContext): EslintRule.RuleListener {
    if (!isComponentDir(dirname(context.filename))) {
      return {};
    }

    return {
      ImportDeclaration(node: ImportDeclaration) {
        if (node.source && typeof node.source.value === 'string') {
          checkImportSource(context, node, node.source.value);
        }
      },

      ImportExpression(node: ImportExpression) {
        if (
          node.source.type === 'Literal' &&
          typeof node.source.value === 'string'
        ) {
          checkImportSource(context, node, node.source.value);
        }
      },
    };
  },
};

export default rule;
