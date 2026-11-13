/**
 * @file
 *
 * Extracts the strings a module translates with `useTranslation()`.
 *
 * Shared by the code editor, the Canvas CLI, the Headless SDK and Canvas
 * Workbench, so this module must run in browsers and Node.js alike.
 */

import { parse } from '@babel/parser';
import _traverse from '@babel/traverse';

import type { Binding, NodePath } from '@babel/traverse';
import type {
  CallExpression,
  Expression,
  File,
  Node,
  ObjectExpression,
  StringLiteral,
} from '@babel/types';

// `@babel/traverse` is CommonJS: Node.js provides the exports object as the
// default export, bundlers provide its `default` property.
const traverse =
  (_traverse as unknown as { default?: typeof _traverse }).default ?? _traverse;

export interface TranslatableString {
  source: string;
  // Only for `formatPlural()`.
  plural?: string;
  // Omitted when there is no context.
  context?: string;
}

export interface TranslatableStringError {
  message: string;
  line: number;
  // 1-based.
  column: number;
}

const HOOK_SOURCE = 'drupal-canvas/react';
const HOOK_NAME = 'useTranslation';
const FUNCTION_NAMES = ['t', 'formatPlural'] as const;
type FunctionName = (typeof FUNCTION_NAMES)[number];

// Locale joins the singular and plural forms with this character.
// @see \Drupal\Component\Gettext\PoItem::DELIMITER
const PLURAL_DELIMITER = '\x03';
// Locale stores contexts as ASCII text of at most 255 characters.
const MAX_CONTEXT_LENGTH = 255;

const isFunctionName = (name: string): name is FunctionName =>
  (FUNCTION_NAMES as readonly string[]).includes(name);

const getKeyName = (node: Node): string | null => {
  if (node.type === 'Identifier') {
    return node.name;
  }
  if (node.type === 'StringLiteral') {
    return node.value;
  }
  return null;
};

/**
 * The calls of `t()` or `formatPlural()` through a binding.
 */
const getCallsOfBinding = (
  binding: Binding | undefined,
  name: FunctionName,
): [FunctionName, NodePath<CallExpression>][] => {
  if (!binding?.constant) {
    return [];
  }
  return binding.referencePaths.flatMap((reference) => {
    const call = reference.parentPath;
    return call?.isCallExpression() && call.node.callee === reference.node
      ? [[name, call] as [FunctionName, NodePath<CallExpression>]]
      : [];
  });
};

/**
 * The calls of `object.t()` or `object.formatPlural()`.
 */
const getMemberCall = (
  object: NodePath,
): [FunctionName, NodePath<CallExpression>] | null => {
  const member = object.parentPath;
  if (
    !member?.isMemberExpression() ||
    member.node.object !== object.node ||
    member.node.computed ||
    member.node.property.type !== 'Identifier' ||
    !isFunctionName(member.node.property.name)
  ) {
    return null;
  }
  const call = member.parentPath;
  return call?.isCallExpression() && call.node.callee === member.node
    ? [member.node.property.name, call]
    : null;
};

/**
 * The `t()` and `formatPlural()` calls of the value a hook call returns.
 */
const getTranslationCalls = (
  hookCall: NodePath<CallExpression>,
): [FunctionName, NodePath<CallExpression>][] => {
  // `useTranslation().t()`
  const memberCall = getMemberCall(hookCall);
  if (memberCall) {
    return [memberCall];
  }
  const declarator = hookCall.parentPath;
  if (
    !declarator.isVariableDeclarator() ||
    declarator.node.init !== hookCall.node
  ) {
    return [];
  }
  const id = declarator.get('id');
  // `const i18n = useTranslation(); i18n.t()`
  if (id.isIdentifier()) {
    const binding = declarator.scope.getBinding(id.node.name);
    if (!binding?.constant) {
      return [];
    }
    return binding.referencePaths.flatMap((reference) => {
      const call = getMemberCall(reference);
      return call ? [call] : [];
    });
  }
  // `const { t, formatPlural: plural } = useTranslation()`
  if (id.isObjectPattern()) {
    return id.get('properties').flatMap((property) => {
      if (!property.isObjectProperty() || property.node.computed) {
        return [];
      }
      const name = getKeyName(property.node.key);
      const value = property.get('value');
      if (!name || !isFunctionName(name) || !value.isIdentifier()) {
        return [];
      }
      return getCallsOfBinding(
        declarator.scope.getBinding(value.node.name),
        name,
      );
    });
  }
  return [];
};

/**
 * The references to `useTranslation()` imported from `drupal-canvas/react`.
 *
 * @returns
 *   The identifiers that refer to the hook, and the `namespace.useTranslation`
 *   member expressions of namespace imports.
 */
const getHookReferences = (program: NodePath<File['program']>): NodePath[] =>
  program.get('body').flatMap((statement) => {
    if (
      !statement.isImportDeclaration() ||
      statement.node.source.value !== HOOK_SOURCE ||
      statement.node.importKind === 'type'
    ) {
      return [];
    }
    return statement.node.specifiers.flatMap((specifier) => {
      const binding = program.scope.getBinding(specifier.local.name);
      if (!binding) {
        return [];
      }
      // `import { useTranslation as hook } from 'drupal-canvas/react'`
      if (
        specifier.type === 'ImportSpecifier' &&
        specifier.importKind !== 'type' &&
        getKeyName(specifier.imported) === HOOK_NAME
      ) {
        return binding.referencePaths;
      }
      // `import * as canvas from 'drupal-canvas/react'`
      if (specifier.type === 'ImportNamespaceSpecifier') {
        return binding.referencePaths.flatMap((reference) => {
          const member = reference.parentPath;
          return member?.isMemberExpression() &&
            member.node.object === reference.node &&
            !member.node.computed &&
            getKeyName(member.node.property) === HOOK_NAME
            ? [member]
            : [];
        });
      }
      return [];
    });
  });

/**
 * The calls of the hook through its references.
 */
const getHookCalls = (references: NodePath[]): NodePath<CallExpression>[] =>
  references.flatMap((reference) => {
    const call = reference.parentPath;
    return call?.isCallExpression() && call.node.callee === reference.node
      ? [call]
      : [];
  });

/**
 * The static `context` of an options object.
 *
 * @returns
 *   The context literal, `null` for no context, or `undefined` when the
 *   context cannot be determined.
 */
const getContextLiteral = (
  options: ObjectExpression,
): StringLiteral | null | undefined => {
  let context: StringLiteral | null | undefined = null;
  // As in JavaScript, a later property overrides an earlier one.
  for (const property of options.properties) {
    if (property.type === 'SpreadElement' || property.computed) {
      context = undefined;
      continue;
    }
    if (getKeyName(property.key) !== 'context') {
      continue;
    }
    context =
      property.type === 'ObjectProperty' &&
      property.value.type === 'StringLiteral'
        ? property.value
        : undefined;
  }
  return context;
};

const createError = (node: Node, message: string): TranslatableStringError => ({
  message,
  line: node.loc?.start.line ?? 1,
  column: (node.loc?.start.column ?? 0) + 1,
});

const validateSourceLiteral = (
  literal: StringLiteral,
): TranslatableStringError | null => {
  if (literal.value === '') {
    return createError(literal, 'A translatable string must not be empty.');
  }
  if (literal.value.includes(PLURAL_DELIMITER)) {
    return createError(
      literal,
      'A translatable string must not contain the U+0003 character, which separates plural forms.',
    );
  }
  return null;
};

const validateContextLiteral = (
  literal: StringLiteral,
): TranslatableStringError | null => {
  // eslint-disable-next-line no-control-regex
  if (/[^\x00-\x7F]/.test(literal.value)) {
    return createError(
      literal,
      'A translation context must contain only ASCII characters.',
    );
  }
  if (literal.value.length > MAX_CONTEXT_LENGTH) {
    return createError(
      literal,
      `A translation context must not be longer than ${MAX_CONTEXT_LENGTH} characters.`,
    );
  }
  return null;
};

/**
 * The string of a `t()` or `formatPlural()` call.
 *
 * @returns
 *   The string, the errors of its literals, or `null` when the call does not
 *   use only string literals.
 */
const getCallString = (
  name: FunctionName,
  call: CallExpression,
): TranslatableString | TranslatableStringError[] | null => {
  const args = call.arguments;
  // t(source, args, options); formatPlural(count, singular, plural, args, options)
  const sourceIndexes = name === 't' ? [0] : [1, 2];
  const optionsIndex = name === 't' ? 2 : 4;
  if (
    args
      .slice(0, optionsIndex + 1)
      .some((argument) => argument.type === 'SpreadElement')
  ) {
    return null;
  }
  const literals = sourceIndexes.map((index) => args[index]);
  if (!literals.every((literal) => literal?.type === 'StringLiteral')) {
    return null;
  }
  const options = args[optionsIndex] as Expression | undefined;
  let context: StringLiteral | null | undefined = null;
  if (options) {
    if (options.type !== 'ObjectExpression') {
      return null;
    }
    context = getContextLiteral(options);
    if (context === undefined) {
      return null;
    }
  }
  const errors = [
    ...literals.map(validateSourceLiteral),
    context ? validateContextLiteral(context) : null,
  ].filter((error) => error !== null);
  if (errors.length > 0) {
    return errors;
  }
  const [source, plural] = (literals as StringLiteral[]).map(
    (literal) => literal.value,
  );
  return {
    source,
    ...(plural !== undefined && { plural }),
    // An empty context is no context.
    ...(context?.value && { context: context.value }),
  };
};

/**
 * Compares strings by Unicode code point, like PHP's `strcmp()` on UTF-8.
 */
const compareCodePoints = (a: string, b: string): number => {
  const length = Math.min(a.length, b.length);
  for (let index = 0; index < length; index++) {
    const codePointA = a.codePointAt(index)!;
    const codePointB = b.codePointAt(index)!;
    if (codePointA !== codePointB) {
      return codePointA - codePointB;
    }
    if (codePointA > 0xffff) {
      index++;
    }
  }
  return a.length - b.length;
};

/**
 * Deduplicates and sorts strings by context, source, then plural.
 *
 * Drupal stores them in the same order.
 * @see \Drupal\canvas\Entity\JavaScriptComponent::normalizeTranslatableStrings()
 */
export const normalizeTranslatableStrings = (
  strings: TranslatableString[],
): TranslatableString[] => {
  const unique = new Map(
    strings.map((string) => [
      JSON.stringify([
        string.context ?? '',
        string.source,
        string.plural ?? null,
      ]),
      string,
    ]),
  );
  return [...unique.values()].sort(
    (a, b) =>
      compareCodePoints(a.context ?? '', b.context ?? '') ||
      compareCodePoints(a.source, b.source) ||
      (a.plural === undefined ? -1 : 0) - (b.plural === undefined ? -1 : 0) ||
      compareCodePoints(a.plural ?? '', b.plural ?? ''),
  );
};

/**
 * Extracts the strings of `t()` and `formatPlural()` calls from an AST.
 *
 * Follows the bindings of `useTranslation()` from `drupal-canvas/react` with
 * Babel's scope analysis. Calls that do not pass string literals, or whose
 * context is not a string literal, are skipped. Literals that Drupal cannot
 * store are errors.
 */
export const getTranslatableStringsFromAst = (
  ast: File,
): {
  translatableStrings: TranslatableString[];
  errors: TranslatableStringError[];
  /**
   * Whether the code refers to `useTranslation()`, whatever its calls pass.
   * Shadowed bindings and unused imports do not count.
   */
  usesTranslationHook: boolean;
} => {
  let program: NodePath<File['program']> | undefined;
  traverse(ast, {
    Program(path) {
      program = path;
      path.stop();
    },
  });
  const hookReferences = program ? getHookReferences(program) : [];
  const strings: TranslatableString[] = [];
  const errors: TranslatableStringError[] = [];
  for (const hookCall of getHookCalls(hookReferences)) {
    for (const [name, call] of getTranslationCalls(hookCall)) {
      const result = getCallString(name, call.node);
      if (Array.isArray(result)) {
        errors.push(...result);
      } else if (result) {
        strings.push(result);
      }
    }
  }
  errors.sort((a, b) => a.line - b.line || a.column - b.column);
  return {
    translatableStrings: normalizeTranslatableStrings(strings),
    errors,
    usesTranslationHook: hookReferences.length > 0,
  };
};

/**
 * The analysis of one module's source code.
 */
export type ModuleAnalysis =
  | { syntaxError: TranslatableStringError }
  | (ReturnType<typeof getTranslatableStringsFromAst> & {
      /**
       * The modules that the module imports or re-exports statically, in
       * source order. Type-only imports and dynamic `import()` calls are not
       * included.
       */
      imports: string[];
    });

const getStaticImports = (ast: File): string[] => {
  const imports = new Set<string>();
  for (const statement of ast.program.body) {
    if (
      (statement.type === 'ImportDeclaration' &&
        statement.importKind !== 'type') ||
      ((statement.type === 'ExportNamedDeclaration' ||
        statement.type === 'ExportAllDeclaration') &&
        statement.source &&
        statement.exportKind !== 'type')
    ) {
      imports.add(statement.source!.value);
    }
  }
  return [...imports];
};

// Module analyses by source code, so that each version of a module is parsed
// once, and a changed module is parsed again.
const analyses = new Map<string, ModuleAnalysis>();
const MAX_ANALYSES = 200;

/**
 * Analyzes the source code of a module: its strings, invalid literals, use of
 * `useTranslation()`, and static imports.
 */
export const analyzeModule = (source: string): ModuleAnalysis => {
  const cached = analyses.get(source);
  if (cached) {
    return cached;
  }
  let analysis: ModuleAnalysis;
  try {
    const ast = parse(source, {
      sourceType: 'module',
      plugins: ['jsx', 'typescript'],
    });
    analysis = {
      ...getTranslatableStringsFromAst(ast),
      imports: getStaticImports(ast),
    };
  } catch (error) {
    const loc = (error as { loc?: { line: number; column: number } }).loc;
    analysis = {
      syntaxError: {
        message: error instanceof Error ? error.message : String(error),
        line: loc?.line ?? 1,
        column: (loc?.column ?? 0) + 1,
      },
    };
  }
  if (analyses.size >= MAX_ANALYSES) {
    analyses.delete(analyses.keys().next().value!);
  }
  analyses.set(source, analysis);
  return analysis;
};

/**
 * Extracts the strings of `t()` and `formatPlural()` calls from source code.
 *
 * @returns
 *   The strings, or the errors if the source code has invalid literals or
 *   cannot be parsed. A parse error sets `syntaxError`.
 */
export const getTranslatableStringsFromSource = (
  source: string,
):
  | { translatableStrings: TranslatableString[] }
  | { errors: TranslatableStringError[]; syntaxError?: true } => {
  const analysis = analyzeModule(source);
  if ('syntaxError' in analysis) {
    return { errors: [analysis.syntaxError], syntaxError: true };
  }
  const { translatableStrings, errors } = analysis;
  return errors.length > 0 ? { errors } : { translatableStrings };
};
