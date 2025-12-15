import { isInComponentDir } from '../utils/components.js';

import type { Rule as EslintRule } from 'eslint';
import type { ImportDeclaration, ImportExpression } from 'estree';

function checkImportSource(
  context: EslintRule.RuleContext,
  node: ImportDeclaration | ImportExpression,
  source: string,
): void {
  // Relative imports are not supported in Drupal Canvas.
  if (source.startsWith('./') || source.startsWith('../')) {
    context.report({
      node,
      message: `Relative component imports are not supported. Use '@/components/[component-name]' instead of '${source}'.`,
    });
    return;
  }

  // Currently limited list of bundled packages is supported in Drupal Canvas.
  // @see https://drupal.org/i/3560197
  // @see importMap in ui/src/features/code-editor/Preview.tsx
  if (
    [
      'preact',
      'preact/hooks',
      'react/jsx-runtime',
      'react',
      'react-dom',
      'react-dom/client',
      'clsx',
      'class-variance-authority',
      'tailwind-merge',
      '@/components/',
      'drupal-jsonapi-params',
      'swr',
      'drupal-canvas',
    ].includes(source)
  ) {
    return;
  }

  if (source === '@/lib/drupal-utils') {
    context.report({
      node,
      message: 'Drupal utilities were moved into the `drupal-canvas` package.',
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

  if (source === '@/lib/utils') {
    context.report({
      node,
      message: 'Utilities were moved into the `drupal-canvas` package.',
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
        'JSON:API utilities were moved into the `drupal-canvas` package.',
      fix(fixer) {
        return fixer.replaceText(node.source, "'drupal-canvas'");
      },
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
        'The `FormattedText` component was moved into the `drupal-canvas` package.',
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

  // Components can be imported using the "@/components/" alias.
  if (source.startsWith('@/components/')) {
    // But nested components are not supported.
    if (source.replace('@/components/', '').includes('/')) {
      context.report({
        node,
        message: `Components must be imported from top-level directories under "@/components/". The path "${source}" suggests a nested structure.`,
      });
      return;
    }

    return;
  }

  // Full urls are also supported.
  if (source.startsWith('http://') || source.startsWith('https://')) {
    return;
  }

  // Other imports are currently not allowed.
  context.report({
    node,
    message:
      `Importing "${source}" is not supported. ` +
      'If this is a local import via a path alias, use the "@/components/" alias instead. ' +
      'If you are importing a third-party package, see the list of supported packages at https://project.pages.drupalcode.org/canvas/code-components/packages. ' +
      '(The status of supporting any third-party package can be tracked at https://drupal.org/i/3560197.)',
  });
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
    if (!isInComponentDir(context)) {
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
