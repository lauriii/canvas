/**
 * @file
 * The pull codemod that migrates `getPageData()` and `getSiteData()` calls to
 * the `usePageContext()` and `useSiteContext()` hooks from `drupal-canvas/react`.
 *
 * The migration is all-or-nothing per file and deliberately conservative: a
 * file is rewritten only when every getter usage in it is safe. A safe usage
 * is a resolved imported binding, called with no arguments, at an
 * unconditional valid hook position inside a function component or custom
 * hook, with no possible return before it, and no identifier conflict with
 * the hook names. Anything uncertain leaves the file unchanged and produces a
 * warning. Client construction (`new JsonApiClient()`) and helper modules
 * only get diagnostics.
 *
 * Source text is rewritten by range so formatting outside the touched spans
 * is preserved. No null guards, fallbacks, or non-null assertions are
 * generated: the rendering integrations supply page and site context.
 *
 * @see docs/adr/0021-code-component-runtime-compatibility-across-frontend-modes.md
 */

import { parse } from '@babel/parser';

import type {
  CallExpression,
  File,
  Identifier,
  ImportDeclaration,
  ImportSpecifier,
  Node,
} from '@babel/types';

/** The getter → hook mapping. */
export const GETTER_HOOKS = {
  getPageData: 'usePageContext',
  getSiteData: 'useSiteContext',
} as const;

export type GetterName = keyof typeof GETTER_HOOKS;

export type HookName = (typeof GETTER_HOOKS)[GetterName];

/** Module specifiers that provide the getters. */
const GETTER_SOURCES = new Set([
  'drupal-canvas',
  'drupal-canvas/drupal-utils',
  '@/lib/drupal-utils',
]);

/** Module specifiers that provide the legacy JSON:API client constructor. */
const CLIENT_SOURCES = new Set([
  'drupal-canvas',
  'drupal-canvas/jsonapi-client',
  '@drupal-api-client/json-api-client',
]);

/** The source the hooks are imported from. */
const HOOK_SOURCE = 'drupal-canvas/react';

export interface GetterConversion {
  from: GetterName;
  to: HookName;
  /** Number of call sites converted. */
  calls: number;
}

export interface GetterMigrationResult {
  /** Whether the source was rewritten. */
  changed: boolean;
  /** The (possibly rewritten) source. */
  source: string;
  /** Conversions applied when `changed` is true. */
  conversions: GetterConversion[];
  /**
   * Reasons the file was left unchanged although it uses the getters, or
   * notes about usages left as they are (client construction).
   */
  warnings: string[];
  /** Whether the file uses any getter at all. */
  usesGetters: boolean;
  /** Whether the file constructs the legacy JSON:API client. */
  constructsClient: boolean;
}

interface GetterBinding {
  getter: GetterName;
  local: string;
  specifier: ImportSpecifier;
  declaration: ImportDeclaration;
}

interface NamespaceBinding {
  local: string;
  declaration: ImportDeclaration;
}

/** React wrappers whose first argument is the component itself. */
const COMPONENT_WRAPPERS = new Set(['memo', 'forwardRef']);

/** The React wrapper bindings of a file, resolved from its `react` import. */
interface WrapperBindings {
  /** Local names bound to `memo`/`forwardRef` named imports from `react`. */
  named: Set<string>;
  /** Local names bound to React's default or namespace import. */
  react: Set<string>;
}

interface Edit {
  start: number;
  end: number;
  text: string;
}

const FUNCTION_TYPES = new Set([
  'FunctionDeclaration',
  'FunctionExpression',
  'ArrowFunctionExpression',
  'ObjectMethod',
  'ClassMethod',
  'ClassPrivateMethod',
]);

/** Node types that make a position conditional or repeated. */
const CONDITIONAL_TYPES = new Set([
  'IfStatement',
  'ConditionalExpression',
  'LogicalExpression',
  'SwitchStatement',
  'ForStatement',
  'ForInStatement',
  'ForOfStatement',
  'WhileStatement',
  'DoWhileStatement',
  'TryStatement',
  'CatchClause',
  'OptionalMemberExpression',
  'OptionalCallExpression',
  'ClassDeclaration',
  'ClassExpression',
]);

function importedName(specifier: ImportSpecifier): string {
  return specifier.imported.type === 'Identifier'
    ? specifier.imported.name
    : specifier.imported.value;
}

function isNode(value: unknown): value is Node {
  return (
    typeof value === 'object' &&
    value !== null &&
    typeof (value as Node).type === 'string'
  );
}

/** Depth-first walk with parent chain. */
function walk(
  node: Node,
  visitor: (node: Node, parents: Node[]) => void,
  parents: Node[] = [],
): void {
  visitor(node, parents);
  const nextParents = [...parents, node];
  for (const [key, value] of Object.entries(node)) {
    if (
      key === 'loc' ||
      key === 'leadingComments' ||
      key === 'trailingComments' ||
      key === 'innerComments'
    ) {
      continue;
    }
    if (Array.isArray(value)) {
      for (const item of value) {
        if (isNode(item)) {
          walk(item, visitor, nextParents);
        }
      }
    } else if (isNode(value)) {
      walk(value, visitor, nextParents);
    }
  }
}

/** Whether an identifier occurrence is a value reference (not a key/label). */
function isReference(node: Identifier, parent: Node | undefined): boolean {
  if (!parent) {
    return true;
  }
  switch (parent.type) {
    case 'ImportSpecifier':
    case 'ImportDefaultSpecifier':
    case 'ImportNamespaceSpecifier':
    case 'ExportSpecifier':
    case 'LabeledStatement':
    case 'BreakStatement':
    case 'ContinueStatement':
      return false;
    case 'MemberExpression':
    case 'OptionalMemberExpression':
      return parent.object === node || parent.computed;
    case 'ObjectProperty':
      return parent.value === node || parent.computed;
    case 'ObjectMethod':
    case 'ClassMethod':
    case 'ClassProperty':
      return parent.key !== node || parent.computed;
    default:
      return true;
  }
}

/** Whether an identifier occurrence declares a binding rather than uses one. */
function isBindingDeclaration(node: Identifier, parents: Node[]): boolean {
  const parent = parents[parents.length - 1];
  if (!parent) {
    return false;
  }
  switch (parent.type) {
    case 'VariableDeclarator':
      return parent.id === node;
    case 'FunctionDeclaration':
    case 'FunctionExpression':
    case 'ArrowFunctionExpression':
    case 'ObjectMethod':
    case 'ClassMethod':
    case 'ClassPrivateMethod':
      return (parent.params as Node[]).includes(node);
    case 'ObjectPattern':
    case 'ArrayPattern':
    case 'RestElement':
      return true;
    case 'AssignmentPattern':
      return parent.left === node;
    case 'ObjectProperty':
      return (
        parent.value === node &&
        parents[parents.length - 2]?.type === 'ObjectPattern'
      );
    case 'CatchClause':
      return parent.param === node;
    default:
      return false;
  }
}

/**
 * Whether a function node is a component or custom hook: first by its
 * syntactic position (a declaration, a variable's initializer, a default
 * export, or the component argument of a React wrapper in one of those
 * positions), then by the name that position gives it. A function anywhere
 * else — another call's argument, a comparator callback, a property value —
 * is not a component, whatever its own name says.
 */
function isComponentOrHook(
  fn: Node,
  parents: Node[],
  wrappers: WrapperBindings,
): boolean {
  const parent = parents[parents.length - 1];
  let name: string | null = null;
  if (fn.type === 'FunctionDeclaration') {
    name = fn.id?.name ?? null;
    if (name === null && parent?.type === 'ExportDefaultDeclaration') {
      return true;
    }
  } else if (parent?.type === 'VariableDeclarator' && parent.init === fn) {
    name = parent.id.type === 'Identifier' ? parent.id.name : null;
  } else if (parent?.type === 'ExportDefaultDeclaration') {
    // The default export is the component of a Code Component file.
    return true;
  } else if (
    parent?.type === 'CallExpression' &&
    parent.arguments[0] === fn &&
    isComponentWrapper(parent.callee, wrappers)
  ) {
    // `const Comp = memo(() => ...)` / `export default React.forwardRef(...)`:
    // only React's own wrappers make their first argument a component, and
    // only where the wrapped result is itself in a component position.
    const grandparent = parents[parents.length - 2];
    if (
      grandparent?.type === 'VariableDeclarator' &&
      grandparent.init === parent
    ) {
      name = grandparent.id.type === 'Identifier' ? grandparent.id.name : null;
    } else if (grandparent?.type === 'ExportDefaultDeclaration') {
      return true;
    } else {
      return false;
    }
  } else {
    return false;
  }
  if (name === null) {
    return false;
  }
  return /^[A-Z]/.test(name) || /^use[A-Z0-9]/.test(name);
}

function isComponentWrapper(callee: Node, wrappers: WrapperBindings): boolean {
  if (callee.type === 'Identifier') {
    return wrappers.named.has(callee.name);
  }
  return (
    callee.type === 'MemberExpression' &&
    !callee.computed &&
    callee.object.type === 'Identifier' &&
    wrappers.react.has(callee.object.name) &&
    callee.property.type === 'Identifier' &&
    COMPONENT_WRAPPERS.has(callee.property.name)
  );
}

/**
 * Resolves which local names are React's wrappers: named imports of
 * `memo`/`forwardRef` from `react` and the React default or namespace import,
 * each declared exactly once in the file (a shadowed name is not a wrapper).
 */
function resolveWrapperBindings(
  ast: File,
  declarations: Map<string, number>,
): WrapperBindings {
  const wrappers: WrapperBindings = { named: new Set(), react: new Set() };
  for (const statement of ast.program.body) {
    if (
      statement.type !== 'ImportDeclaration' ||
      statement.importKind === 'type' ||
      statement.source.value !== 'react'
    ) {
      continue;
    }
    for (const specifier of statement.specifiers) {
      const local = specifier.local.name;
      if ((declarations.get(local) ?? 0) !== 1) {
        continue;
      }
      if (
        specifier.type === 'ImportSpecifier' &&
        COMPONENT_WRAPPERS.has(importedName(specifier))
      ) {
        wrappers.named.add(local);
      } else if (
        specifier.type === 'ImportDefaultSpecifier' ||
        specifier.type === 'ImportNamespaceSpecifier'
      ) {
        wrappers.react.add(local);
      }
    }
  }
  return wrappers;
}

function functionBody(fn: Node): Node | null {
  return 'body' in fn && isNode(fn.body) ? fn.body : null;
}

/** Whether `node` contains a return or throw statement. */
function containsExit(node: Node): boolean {
  let found = false;
  walk(node, (child, parents) => {
    if (found) {
      return;
    }
    if (child === node) {
      return;
    }
    // Exits inside nested functions do not leave the enclosing function.
    if (parents.slice(1).some((parent) => FUNCTION_TYPES.has(parent.type))) {
      return;
    }
    if (child.type === 'ReturnStatement' || child.type === 'ThrowStatement') {
      found = true;
    }
  });
  return found;
}

/**
 * Checks that a call sits at an unconditional hook position: inside a
 * component or custom hook, not nested in another function or a conditional
 * branch, loop or try, and with no possible return before its statement.
 */
function hookPositionProblem(
  call: CallExpression,
  parents: Node[],
  wrappers: WrapperBindings,
): string | null {
  // Find the innermost enclosing function.
  let fnIndex = -1;
  for (let index = parents.length - 1; index >= 0; index -= 1) {
    if (FUNCTION_TYPES.has(parents[index].type)) {
      fnIndex = index;
      break;
    }
  }
  if (fnIndex === -1) {
    return 'called at module level, outside a function component or custom hook';
  }
  const fn = parents[fnIndex];
  if (!isComponentOrHook(fn, parents.slice(0, fnIndex), wrappers)) {
    return 'called inside a function that is not a component or custom hook';
  }
  const between = parents.slice(fnIndex + 1);
  const conditional = between.find((node, index) => {
    const child = between[index + 1] ?? call;
    // The left operand of ?? always runs. Keep checking outer ancestors:
    // condition && (getter() ?? {}) is still a conditional hook call.
    if (
      node.type === 'LogicalExpression' &&
      node.operator === '??' &&
      node.left === child
    ) {
      return false;
    }
    // Destructuring defaults run only when the supplied value is undefined.
    return (
      CONDITIONAL_TYPES.has(node.type) ||
      (node.type === 'AssignmentPattern' && node.right === child)
    );
  });
  if (conditional) {
    return `called conditionally (inside ${conditional.type})`;
  }
  // JSX expressions inside conditionals are covered above; a call inside
  // an event handler or callback is a nested function (already rejected).
  const body = functionBody(fn);
  if (body && body.type === 'BlockStatement') {
    const statementIndex = between.findIndex((node) => node === body);
    const statement = between[statementIndex + 1];
    const statements = body.body as Node[];
    const position = statements.indexOf(statement);
    if (position === -1) {
      return 'called outside the component body';
    }
    for (const earlier of statements.slice(0, position)) {
      if (
        earlier.type === 'ReturnStatement' ||
        earlier.type === 'ThrowStatement' ||
        containsExit(earlier)
      ) {
        return 'called after a possible early return';
      }
    }
    // A return inside the same statement before the call, e.g. inside an
    // arrow body, is already a nested function or conditional.
  }
  return null;
}

/** Reject direct destructuring of the nullable replacement hook result. */
function nullableDestructuringProblem(
  call: CallExpression,
  parents: Node[],
  getter: GetterName,
): string | null {
  let value: Node = call;
  for (let index = parents.length - 1; index >= 0; index -= 1) {
    const parent = parents[index];
    // Type assertions/non-null assertions do not guard null at runtime.
    if (
      parent.type === 'TSAsExpression' ||
      parent.type === 'TSTypeAssertion' ||
      parent.type === 'TSNonNullExpression' ||
      parent.type === 'TSSatisfiesExpression' ||
      parent.type === 'ParenthesizedExpression'
    ) {
      value = parent;
      continue;
    }
    const pattern =
      parent.type === 'VariableDeclarator' && parent.init === value
        ? parent.id
        : parent.type === 'AssignmentExpression' && parent.right === value
          ? parent.left
          : null;
    if (pattern?.type === 'ObjectPattern' || pattern?.type === 'ArrayPattern') {
      return `destructured without a null guard; ${GETTER_HOOKS[getter]}() can return null. Convert manually using \`${GETTER_HOOKS[getter]}() ?? {}\` and review the destructuring pattern and nested defaults`;
    }
    // An explicit fallback is left as authored; do not invent one or infer
    // data flow through other expressions or subsequent variable uses.
    return null;
  }
  return null;
}

/**
 * Counts every declaration of each identifier name in the file, in any scope
 * and binding form (variables, functions, classes, parameters, destructuring
 * patterns, catch parameters, imports).
 */
function declarationCounts(ast: File): Map<string, number> {
  const counts = new Map<string, number>();
  const declare = (name: string) => {
    counts.set(name, (counts.get(name) ?? 0) + 1);
  };
  walk(ast.program, (node, parents) => {
    const parent = parents[parents.length - 1];
    if (node.type !== 'Identifier') {
      return;
    }
    if (!parent) {
      return;
    }
    switch (parent.type) {
      case 'VariableDeclarator':
        if (parent.id === node) declare(node.name);
        break;
      case 'FunctionDeclaration':
      case 'FunctionExpression':
      case 'ClassDeclaration':
      case 'ClassExpression':
        if ((parent as { id?: Node }).id === node) declare(node.name);
        if ('params' in parent && (parent.params as Node[]).includes(node)) {
          declare(node.name);
        }
        break;
      case 'ArrowFunctionExpression':
      case 'ObjectMethod':
      case 'ClassMethod':
      case 'ClassPrivateMethod':
        if ((parent.params as Node[]).includes(node)) declare(node.name);
        break;
      case 'ImportSpecifier':
      case 'ImportDefaultSpecifier':
      case 'ImportNamespaceSpecifier':
        if (parent.local === node) declare(node.name);
        break;
      case 'ObjectPattern':
      case 'ArrayPattern':
      case 'RestElement':
        declare(node.name);
        break;
      case 'AssignmentPattern':
        if (parent.left === node) declare(node.name);
        break;
      case 'ObjectProperty':
        if (
          parent.value === node &&
          parents[parents.length - 2]?.type === 'ObjectPattern'
        ) {
          declare(node.name);
        }
        break;
      case 'CatchClause':
        if (parent.param === node) declare(node.name);
        break;
      default:
        break;
    }
  });
  return counts;
}

function parseSource(source: string, filename: string): File {
  return parse(source, {
    sourceType: 'module',
    sourceFilename: filename,
    plugins: /\.tsx?$/.test(filename) ? ['jsx', 'typescript'] : ['jsx'],
    ranges: true,
    errorRecovery: false,
  });
}

function applyEdits(source: string, edits: Edit[]): string {
  const ordered = [...edits].sort((a, b) => b.start - a.start);
  let result = source;
  for (const edit of ordered) {
    result = result.slice(0, edit.start) + edit.text + result.slice(edit.end);
  }
  return result;
}

/**
 * Plans and applies the getter migration for one component source file.
 */
export function migrateGetterCalls(
  source: string,
  filename: string,
): GetterMigrationResult {
  const unchanged = (
    warnings: string[] = [],
    extra: Partial<GetterMigrationResult> = {},
  ) => ({
    changed: false,
    source,
    conversions: [],
    warnings,
    usesGetters: false,
    constructsClient: false,
    ...extra,
  });

  let ast: File;
  try {
    ast = parseSource(source, filename);
  } catch (error) {
    return unchanged([
      `could not parse the source: ${error instanceof Error ? error.message : String(error)}`,
    ]);
  }

  const getterBindings: GetterBinding[] = [];
  const namespaceBindings: NamespaceBinding[] = [];
  const clientLocals = new Set<string>();
  const clientNamespaces = new Set<string>();
  let existingHookImport: ImportDeclaration | null = null;
  for (const statement of ast.program.body) {
    if (
      statement.type !== 'ImportDeclaration' ||
      statement.importKind === 'type'
    ) {
      continue;
    }
    const sourceName = statement.source.value;
    if (sourceName === HOOK_SOURCE && !existingHookImport) {
      existingHookImport = statement;
    }
    for (const specifier of statement.specifiers) {
      if (GETTER_SOURCES.has(sourceName)) {
        if (specifier.type === 'ImportSpecifier') {
          const name = importedName(specifier);
          if (name in GETTER_HOOKS) {
            getterBindings.push({
              getter: name as GetterName,
              local: specifier.local.name,
              specifier,
              declaration: statement,
            });
          }
        } else if (specifier.type === 'ImportNamespaceSpecifier') {
          namespaceBindings.push({
            local: specifier.local.name,
            declaration: statement,
          });
        }
      }
      if (CLIENT_SOURCES.has(sourceName)) {
        if (
          specifier.type === 'ImportSpecifier' &&
          importedName(specifier) === 'JsonApiClient'
        ) {
          clientLocals.add(specifier.local.name);
        } else if (specifier.type === 'ImportNamespaceSpecifier') {
          clientNamespaces.add(specifier.local.name);
        }
      }
    }
  }

  const warnings: string[] = [];
  let constructsClient = false;
  const localGetters = new Map(
    getterBindings.map((binding) => [binding.local, binding]),
  );
  const declarations = declarationCounts(ast);
  const wrappers = resolveWrapperBindings(ast, declarations);
  const callsByGetter = new Map<GetterName, CallExpression[]>();
  /** Getters whose hook needs a named import from `drupal-canvas/react`. */
  const namedGetters = new Set<GetterName>();
  const edits: Edit[] = [];
  let unsafe = false;
  let usesGetters = false;
  /** A namespace import used in a way whose members cannot be verified. */
  let unverifiableNamespace: string | null = null;

  const noteUnsafe = (reason: string) => {
    unsafe = true;
    warnings.push(reason);
  };

  walk(ast.program, (node, parents) => {
    const parent = parents[parents.length - 1];
    // Legacy client construction: diagnostics only.
    if (node.type === 'NewExpression') {
      const callee = node.callee;
      if (
        (callee.type === 'Identifier' && clientLocals.has(callee.name)) ||
        (callee.type === 'MemberExpression' &&
          callee.object.type === 'Identifier' &&
          clientNamespaces.has(callee.object.name) &&
          callee.property.type === 'Identifier' &&
          callee.property.name === 'JsonApiClient')
      ) {
        constructsClient = true;
      }
    }
    if (node.type !== 'Identifier') {
      return;
    }
    // Namespace imports: `utils.getPageData()`. Only a plain, non-computed
    // member access can be verified; a computed access (`utils[name]`) or
    // the namespace used as a value could reach a getter without this codemod
    // seeing it, which would break the all-or-nothing guarantee.
    if (
      namespaceBindings.some((binding) => binding.local === node.name) &&
      isReference(node, parent) &&
      !isBindingDeclaration(node, parents)
    ) {
      if (
        parent?.type !== 'MemberExpression' ||
        parent.object !== node ||
        parent.computed ||
        parent.property.type !== 'Identifier'
      ) {
        unverifiableNamespace ??= node.name;
        return;
      }
      if (!(parent.property.name in GETTER_HOOKS)) {
        return;
      }
      usesGetters = true;
      const getter = parent.property.name as GetterName;
      const grandparent = parents[parents.length - 2];
      if (
        !grandparent ||
        grandparent.type !== 'CallExpression' ||
        grandparent.callee !== parent
      ) {
        noteUnsafe(`\`${node.name}.${getter}\` is used without being called`);
        return;
      }
      if (grandparent.arguments.length > 0) {
        noteUnsafe(`\`${node.name}.${getter}()\` is called with arguments`);
        return;
      }
      const callParents = parents.slice(0, -2);
      const problem =
        hookPositionProblem(grandparent, callParents, wrappers) ??
        nullableDestructuringProblem(grandparent, callParents, getter);
      if (problem) {
        noteUnsafe(`\`${node.name}.${getter}()\` is ${problem}`);
        return;
      }
      // The hooks are exported by `drupal-canvas/react`, so the
      // member call becomes a plain hook call with a named import; this also
      // keeps the hook visible to the import-based data dependency detection.
      edits.push({
        start: parent.start!,
        end: parent.end!,
        text: GETTER_HOOKS[getter],
      });
      namedGetters.add(getter);
      const calls = callsByGetter.get(getter) ?? [];
      calls.push(grandparent);
      callsByGetter.set(getter, calls);
      return;
    }
    const binding = localGetters.get(node.name);
    if (!binding) {
      return;
    }
    if (parent?.type === 'ExportSpecifier' && parent.local === node) {
      // `export { getPageData }`: removing the import would leave the export
      // dangling, and the re-export keeps the getter alive elsewhere.
      usesGetters = true;
      noteUnsafe(`\`${binding.local}\` is re-exported`);
      return;
    }
    if (!isReference(node, parent)) {
      return;
    }
    usesGetters = true;
    if (parent?.type !== 'CallExpression' || parent.callee !== node) {
      noteUnsafe(`\`${binding.local}\` is used without being called`);
      return;
    }
    if (parent.arguments.length > 0) {
      noteUnsafe(`\`${binding.local}()\` is called with arguments`);
      return;
    }
    const callParents = parents.slice(0, -1);
    const problem =
      hookPositionProblem(parent, callParents, wrappers) ??
      nullableDestructuringProblem(parent, callParents, binding.getter);
    if (problem) {
      noteUnsafe(`\`${binding.local}()\` is ${problem}`);
      return;
    }
    edits.push({
      start: node.start!,
      end: node.end!,
      text: GETTER_HOOKS[binding.getter],
    });
    namedGetters.add(binding.getter);
    const calls = callsByGetter.get(binding.getter) ?? [];
    calls.push(parent);
    callsByGetter.set(binding.getter, calls);
  });

  if (!usesGetters) {
    return unchanged(
      constructsClient
        ? [
            '`new JsonApiClient()` is not migrated automatically; use `useJsonApiClient()` in the component.',
          ]
        : [],
      { constructsClient },
    );
  }

  // Binding resolution and identifier conflicts.
  if (unverifiableNamespace !== null) {
    noteUnsafe(
      `\`${unverifiableNamespace}\` is used in a way whose members cannot be verified (computed access or use as a value)`,
    );
  }
  for (const binding of namespaceBindings) {
    if ((declarations.get(binding.local) ?? 0) > 1) {
      noteUnsafe(
        `\`${binding.local}\` is declared more than once in the file (a local declaration shadows the import)`,
      );
    }
  }
  for (const binding of getterBindings) {
    if ((declarations.get(binding.local) ?? 0) > 1) {
      noteUnsafe(`\`${binding.local}\` is declared more than once in the file`);
    }
  }
  // The replacement hook name must resolve to the `drupal-canvas/react` import at
  // every call site: any other declaration of that name (a parameter, a
  // local, another import), whether or not the hook is already imported,
  // could shadow it somewhere in the file.
  for (const [getter] of callsByGetter) {
    const hook = GETTER_HOOKS[getter];
    const allowed = isHookImport(existingHookImport, hook) ? 1 : 0;
    if ((declarations.get(hook) ?? 0) > allowed) {
      noteUnsafe(
        `the replacement name \`${hook}\` is already declared in the file`,
      );
    }
  }

  if (unsafe) {
    if (constructsClient) {
      warnings.push(
        '`new JsonApiClient()` is not migrated automatically; use `useJsonApiClient()` in the component.',
      );
    }
    return unchanged([...new Set(warnings)], {
      usesGetters: true,
      constructsClient,
    });
  }

  // Import rewrites. Hooks the file already imports from `drupal-canvas/react`
  // are reused, never imported twice.
  const hooksNeeded = new Set<HookName>();
  for (const getter of namedGetters) {
    const hook = GETTER_HOOKS[getter];
    if (!isHookImport(existingHookImport, hook)) {
      hooksNeeded.add(hook);
    }
  }
  const bindingsByDeclaration = new Map<ImportDeclaration, GetterBinding[]>();
  for (const binding of getterBindings) {
    const list = bindingsByDeclaration.get(binding.declaration) ?? [];
    list.push(binding);
    bindingsByDeclaration.set(binding.declaration, list);
  }
  const hooksToAdd = new Set<HookName>(hooksNeeded);
  for (const [declaration, bindings] of bindingsByDeclaration) {
    const others = declaration.specifiers.filter(
      (specifier) =>
        !bindings.some((binding) => binding.specifier === specifier),
    );
    if (declaration.source.value === HOOK_SOURCE) {
      // Rename in place: `getPageData` (or `getPageData as page`) becomes
      // `usePageContext`.
      for (const binding of bindings) {
        const hook = GETTER_HOOKS[binding.getter];
        if (
          !callsByGetter.has(binding.getter) ||
          isHookImport(existingHookImport, hook) ||
          !hooksToAdd.has(hook)
        ) {
          // Imported but never called: drop the specifier below.
          continue;
        }
        edits.push({
          start: binding.specifier.start!,
          end: binding.specifier.end!,
          text: hook,
        });
        hooksToAdd.delete(hook);
      }
      const dropped = bindings.filter(
        (binding) =>
          !callsByGetter.has(binding.getter) ||
          isHookImport(existingHookImport, GETTER_HOOKS[binding.getter]),
      );
      edits.push(
        ...removeSpecifierEdits(
          source,
          declaration,
          dropped.map((binding) => binding.specifier),
        ),
      );
      continue;
    }
    // Legacy alias source (`@/lib/drupal-utils`): the hooks live in
    // `drupal-canvas`. Remove the getter specifiers, or the whole
    // declaration when nothing else is imported from it.
    if (others.length === 0) {
      const hookList = [...hooksToAdd].sort().join(', ');
      const replacement =
        existingHookImport || hookList === ''
          ? ''
          : `import { ${hookList} } from '${HOOK_SOURCE}';`;
      edits.push({
        start: declaration.start!,
        end: declaration.end!,
        text: replacement,
      });
      if (replacement !== '') {
        hooksToAdd.clear();
      }
    } else {
      edits.push(
        ...removeSpecifierEdits(
          source,
          declaration,
          bindings.map((binding) => binding.specifier),
        ),
      );
    }
  }
  if (hooksToAdd.size > 0) {
    if (
      existingHookImport &&
      existingHookImport.specifiers.some((s) => s.type === 'ImportSpecifier')
    ) {
      const last =
        existingHookImport.specifiers[existingHookImport.specifiers.length - 1];
      edits.push({
        start: last.end!,
        end: last.end!,
        text: `, ${[...hooksToAdd].sort().join(', ')}`,
      });
    } else {
      const anchor =
        namespaceBindings[0]?.declaration ?? getterBindings[0]?.declaration;
      const insertAt = anchor ? anchor.end! : 0;
      edits.push({
        start: insertAt,
        end: insertAt,
        text: `${anchor ? '\n' : ''}import { ${[...hooksToAdd].sort().join(', ')} } from '${HOOK_SOURCE}';${anchor ? '' : '\n'}`,
      });
    }
  }

  const conversions: GetterConversion[] = [...callsByGetter.entries()]
    .map(([from, calls]) => ({
      from,
      to: GETTER_HOOKS[from],
      calls: calls.length,
    }))
    .sort((a, b) => a.from.localeCompare(b.from));
  if (constructsClient) {
    warnings.push(
      '`new JsonApiClient()` is not migrated automatically; use `useJsonApiClient()` in the component.',
    );
  }
  return {
    changed: true,
    source: applyEdits(source, edits),
    conversions,
    warnings,
    usesGetters: true,
    constructsClient,
  };
}

function isHookImport(
  declaration: ImportDeclaration | null,
  hook: HookName,
): boolean {
  return (
    declaration !== null &&
    declaration.specifiers.some(
      (specifier) =>
        specifier.type === 'ImportSpecifier' &&
        importedName(specifier) === hook &&
        specifier.local.name === hook,
    )
  );
}

/** Coalesces deletions within one import before applying source offsets. */
function removeSpecifierEdits(
  source: string,
  declaration: ImportDeclaration,
  specifiers: ImportSpecifier[],
): Edit[] {
  const removals = specifiers
    .map((specifier) => removeSpecifierEdit(source, declaration, specifier))
    .sort((a, b) => a.start - b.start);
  const merged: Edit[] = [];
  for (const removal of removals) {
    const previous = merged.at(-1);
    // Adjacent trailing specifiers consume the same separator. Applying
    // both deletions separately would consume retained source as well.
    if (previous && removal.start <= previous.end) {
      previous.end = Math.max(previous.end, removal.end);
    } else {
      merged.push(removal);
    }
  }
  return merged;
}

/** Removes one specifier from an import, including its separating comma. */
function removeSpecifierEdit(
  source: string,
  declaration: ImportDeclaration,
  specifier: ImportSpecifier,
): Edit {
  const index = declaration.specifiers.indexOf(specifier);
  let start = specifier.start!;
  let end = specifier.end!;
  if (index < declaration.specifiers.length - 1) {
    // Remove up to the start of the next specifier.
    end = declaration.specifiers[index + 1].start!;
  } else if (index > 0) {
    // Remove from the end of the previous specifier.
    start = declaration.specifiers[index - 1].end!;
  } else {
    // Only specifier: leave the (now unused) empty braces alone; callers
    // replace the whole declaration in that case.
    return { start, end, text: '' };
  }
  void source;
  return { start, end, text: '' };
}

/**
 * Scans a helper module for legacy API usage and returns diagnostics only:
 * pulled helper modules are never rewritten.
 */
export function diagnoseLegacyApiUsage(
  source: string,
  filename: string,
): string[] {
  const result = migrateGetterCalls(source, filename);
  const diagnostics: string[] = [];
  if (result.usesGetters) {
    diagnostics.push(
      'uses `getPageData()`/`getSiteData()`; helper modules are not migrated automatically. Pass page or site context from a component instead.',
    );
  }
  if (result.constructsClient) {
    diagnostics.push(
      'constructs `new JsonApiClient()`; helper modules are not migrated automatically. Pass a client from `useJsonApiClient()` instead.',
    );
  }
  return diagnostics;
}
