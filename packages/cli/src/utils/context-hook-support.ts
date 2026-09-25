/**
 * @file
 * The capability gates for the pull codemod: the connected Drupal site must
 * advertise context-hook support, and the project's installed `drupal-canvas`
 * package must export both hooks as runtime values from `drupal-canvas/react`.
 * The package's code is never executed: the React entry is resolved under the
 * `import` export conditions and parsed statically, following relative
 * re-exports and local aliases inside the package. When either gate cannot be
 * verified, getter calls stay unchanged and the limitation is reported.
 */

import fs from 'node:fs/promises';
import { createRequire } from 'node:module';
import path from 'node:path';
import axios from 'axios';
import { parse } from '@babel/parser';

import type { File, Node, Statement } from '@babel/types';

export interface ContextHookSupport {
  /** Whether both gates passed. */
  supported: boolean;
  /** Why conversion is disabled, one entry per failed gate. */
  reasons: string[];
}

export interface ContextHookSupportOptions {
  siteUrl: string;
  projectRoot: string;
  /** Injectable for tests. */
  fetchSiteData?: (siteUrl: string) => Promise<unknown>;
}

async function fetchSiteData(siteUrl: string): Promise<unknown> {
  const response = await axios.get(
    `${siteUrl.replace(/\/+$/, '')}/canvas/api/v0/site-data`,
    {
      headers: { Accept: 'application/json', 'X-Canvas-CLI': '1' },
      timeout: 10000,
    },
  );
  return response.data;
}

async function readManifestName(candidate: string): Promise<string | null> {
  try {
    const manifest = JSON.parse(await fs.readFile(candidate, 'utf-8')) as {
      name?: string;
    };
    return manifest.name ?? null;
  } catch {
    return null;
  }
}

/**
 * Locates the `package.json` of the `drupal-canvas` package a project
 * resolves: through Node's resolution first (the exports map hides
 * `package.json`, so the entry is resolved and the package root found from
 * it), then by walking the project's `node_modules` ancestors.
 */
async function locatePackageJson(projectRoot: string): Promise<string | null> {
  try {
    const require = createRequire(path.join(projectRoot, 'package.json'));
    let directory = path.dirname(require.resolve('drupal-canvas'));
    while (directory !== path.dirname(directory)) {
      const candidate = path.join(directory, 'package.json');
      if ((await readManifestName(candidate)) === 'drupal-canvas') {
        return candidate;
      }
      directory = path.dirname(directory);
    }
  } catch {
    // Fall through to the directory walk (e.g. an exports map without a
    // condition Node's CommonJS resolver accepts).
  }
  let directory = path.resolve(projectRoot);
  while (true) {
    const candidate = path.join(
      directory,
      'node_modules',
      'drupal-canvas',
      'package.json',
    );
    if ((await readManifestName(candidate)) === 'drupal-canvas') {
      return candidate;
    }
    const parent = path.dirname(directory);
    if (parent === directory) {
      return null;
    }
    directory = parent;
  }
}

type ExportConditions =
  | string
  | null
  | { [condition: string]: ExportConditions };

/** The export conditions an `import` of the package matches, by priority. */
const IMPORT_CONDITIONS = new Set(['import', 'default']);

type ConditionMatch =
  | { kind: 'resolved'; target: string }
  | { kind: 'denied' }
  | { kind: 'none' };

/**
 * Matches export conditions the way Node does for an `import`: the keys are
 * tried in the object's own order, the first supported condition decides,
 * and a `null` target is a terminal denial (the subpath is not exported),
 * never skipped in favour of a later condition.
 */
function matchConditions(value: ExportConditions | undefined): ConditionMatch {
  if (value === null) {
    return { kind: 'denied' };
  }
  if (typeof value === 'string') {
    return { kind: 'resolved', target: value };
  }
  if (!value || typeof value !== 'object') {
    return { kind: 'none' };
  }
  for (const [condition, target] of Object.entries(value)) {
    if (!IMPORT_CONDITIONS.has(condition)) {
      continue;
    }
    const match = matchConditions(target);
    if (match.kind !== 'none') {
      return match;
    }
  }
  return { kind: 'none' };
}

/**
 * Resolves the React subpath under import/default conditions in manifest
 * order. A root export or module/main entry cannot establish that this
 * subpath exists; absent, denied or unverifiable React entries fail closed.
 */
export function resolveRuntimeEntry(manifest: {
  exports?: ExportConditions | { [key: string]: ExportConditions };
  module?: string;
  main?: string;
}): string | null {
  const exports = manifest.exports;
  if (exports === null || typeof exports !== 'object') {
    return null;
  }
  const match = matchConditions(exports['./react']);
  return match.kind === 'resolved' ? match.target : null;
}

/** The runtime value exports of a module, statically determined. */
export interface RuntimeExports {
  /**
   * Names exported as runtime values (not types), each with the identity of
   * the binding it resolves to (`<file>::<local>`), so re-exports of one
   * binding through several paths are recognized as the same export.
   */
  names: Map<string, string>;
  /**
   * Names that several `export *` sources provide from different bindings:
   * not exported at all, as in ECMAScript module semantics.
   */
  ambiguous: Set<string>;
  /** Why some exports could not be determined; empty when fully verified. */
  unverifiable: string[];
}

function isNode(value: unknown): value is Node {
  return (
    typeof value === 'object' &&
    value !== null &&
    typeof (value as Node).type === 'string'
  );
}

/** Identifier names bound by a declaration pattern. */
function patternNames(node: Node, names: string[] = []): string[] {
  switch (node.type) {
    case 'Identifier':
      names.push(node.name);
      break;
    case 'ObjectPattern':
      for (const property of node.properties) {
        patternNames(
          property.type === 'RestElement' ? property.argument : property.value,
          names,
        );
      }
      break;
    case 'ArrayPattern':
      for (const element of node.elements) {
        if (element) {
          patternNames(element, names);
        }
      }
      break;
    case 'RestElement':
      patternNames(node.argument, names);
      break;
    case 'AssignmentPattern':
      patternNames(node.left, names);
      break;
    default:
      break;
  }
  return names;
}

/** Whether a module-level statement contains CommonJS-style export writes. */
function hasDynamicExports(statement: Statement): boolean {
  let found = false;
  const visit = (node: Node) => {
    if (found) {
      return;
    }
    if (
      node.type === 'MemberExpression' &&
      !node.computed &&
      node.object.type === 'Identifier' &&
      ((node.object.name === 'module' &&
        node.property.type === 'Identifier' &&
        node.property.name === 'exports') ||
        node.object.name === 'exports')
    ) {
      found = true;
      return;
    }
    for (const [key, value] of Object.entries(node)) {
      if (key === 'loc' || key.endsWith('Comments')) {
        continue;
      }
      if (Array.isArray(value)) {
        value.forEach((item) => isNode(item) && visit(item));
      } else if (isNode(value)) {
        visit(value);
      }
    }
  };
  visit(statement);
  return found;
}

async function resolveRelativeModule(
  from: string,
  specifier: string,
  packageRoot: string,
): Promise<string | null> {
  const base = path.resolve(path.dirname(from), specifier);
  for (const candidate of [
    base,
    `${base}.js`,
    `${base}.mjs`,
    `${base}.ts`,
    path.join(base, 'index.js'),
    path.join(base, 'index.ts'),
  ]) {
    const relative = path.relative(packageRoot, candidate);
    if (relative.startsWith('..') || path.isAbsolute(relative)) {
      return null;
    }
    try {
      if ((await fs.stat(candidate)).isFile()) {
        return candidate;
      }
    } catch {
      // Try the next candidate.
    }
  }
  return null;
}

/** The state shared by one export graph traversal. */
interface Traversal {
  /** Completed results per file. */
  done: Map<string, RuntimeExports>;
  /** Files whose traversal is in progress: reaching one again is a cycle. */
  active: Set<string>;
}

/**
 * Determines the runtime value exports of an ES module file without running
 * it: declarations exported in place, local bindings exported by name or
 * alias (including bindings imported from relative modules of the same
 * package), and relative re-exports (`export { a as b } from`, `export *
 * from`). Type-only exports are ignored. Explicit exports take precedence
 * over `export *`, and a name that different star sources provide from
 * different bindings is ambiguous and not exported, as in ECMAScript.
 * Re-exports from other packages, CommonJS export writes, unresolvable
 * modules and circular re-exports are reported as unverifiable rather than
 * guessed.
 */
export async function collectRuntimeExports(
  file: string,
  packageRoot: string,
  traversal: Traversal = { done: new Map(), active: new Set() },
): Promise<RuntimeExports> {
  const done = traversal.done.get(file);
  if (done) {
    return done;
  }
  const relative = path.relative(packageRoot, file);
  const result: RuntimeExports = {
    names: new Map(),
    ambiguous: new Set(),
    unverifiable: [],
  };
  if (traversal.active.has(file)) {
    // A cycle back into a module still being traversed: its exports are not
    // known yet, and waiting for them would wait forever.
    result.unverifiable.push(`${relative} is re-exported circularly`);
    return result;
  }
  traversal.active.add(file);
  try {
    let ast: File;
    try {
      // Published entries are JavaScript; a package exporting TypeScript
      // source is parsed as such so type-only exports are recognized.
      ast = parse(await fs.readFile(file, 'utf-8'), {
        sourceType: 'module',
        sourceFilename: file,
        plugins: /\.[cm]?tsx?$/.test(file) ? ['jsx', 'typescript'] : ['jsx'],
        errorRecovery: false,
      });
    } catch (error) {
      result.unverifiable.push(
        `${relative} could not be parsed as an ES module (${
          error instanceof Error ? error.message : String(error)
        })`,
      );
      return result;
    }
    const identity = (local: string) => `${relative}::${local}`;
    /** Module-level runtime bindings declared in this file. */
    const local = new Set<string>();
    /** Local bindings imported from relative modules: local → [file, name]. */
    const imported = new Map<string, { source: string; name: string }>();
    /** Local bindings imported from other packages (runtime values). */
    const external = new Set<string>();
    for (const statement of ast.program.body) {
      switch (statement.type) {
        case 'FunctionDeclaration':
        case 'ClassDeclaration':
          if (statement.id) {
            local.add(statement.id.name);
          }
          break;
        case 'VariableDeclaration':
          for (const declarator of statement.declarations) {
            patternNames(declarator.id).forEach((name) => local.add(name));
          }
          break;
        case 'ImportDeclaration': {
          if (statement.importKind === 'type') {
            break;
          }
          const source = statement.source.value;
          for (const specifier of statement.specifiers) {
            if (
              specifier.type === 'ImportSpecifier' &&
              specifier.importKind === 'type'
            ) {
              continue;
            }
            if (source.startsWith('.')) {
              const name =
                specifier.type === 'ImportSpecifier'
                  ? specifier.imported.type === 'Identifier'
                    ? specifier.imported.name
                    : specifier.imported.value
                  : specifier.type === 'ImportDefaultSpecifier'
                    ? 'default'
                    : '*';
              imported.set(specifier.local.name, { source, name });
            } else {
              external.add(specifier.local.name);
            }
          }
          break;
        }
        default:
          if (hasDynamicExports(statement)) {
            result.unverifiable.push(
              `${relative} assigns exports dynamically (CommonJS)`,
            );
          }
          break;
      }
    }
    const sourceExports = async (
      specifier: string,
    ): Promise<RuntimeExports | null> => {
      const target = specifier.startsWith('.')
        ? await resolveRelativeModule(file, specifier, packageRoot)
        : null;
      if (target === null) {
        return null;
      }
      const exports = await collectRuntimeExports(
        target,
        packageRoot,
        traversal,
      );
      result.unverifiable.push(...exports.unverifiable);
      return exports;
    };
    /**
     * The identity of `name` as exported by a source module, or null with
     * an unverifiable reason: a name the source does not export (native
     * linking would fail) or exports ambiguously is never silently omitted,
     * since a star export could otherwise fill the gap.
     */
    const linked = (
      exports: RuntimeExports,
      name: string,
      source: string,
      exported: string,
    ): string | null => {
      const binding = exports.names.get(name);
      if (binding !== undefined) {
        return binding;
      }
      result.unverifiable.push(
        exports.ambiguous.has(name)
          ? `${relative} re-exports \`${exported}\` from \`${name}\`, which ${source} exports ambiguously`
          : `${relative} re-exports \`${exported}\` from \`${name}\`, which ${source} does not export`,
      );
      return null;
    };
    /** Resolves a local binding to the identity of what it refers to. */
    const resolveLocal = async (
      name: string,
      exported: string,
    ): Promise<string | null> => {
      if (local.has(name)) {
        return identity(name);
      }
      const source = imported.get(name);
      if (source) {
        if (source.name === '*') {
          return `${relative}::*${source.source}`;
        }
        const exports = await sourceExports(source.source);
        if (exports === null) {
          result.unverifiable.push(
            `${relative} imports \`${name}\` from an unresolvable module (${source.source})`,
          );
          return null;
        }
        return linked(exports, source.name, source.source, exported);
      }
      if (external.has(name)) {
        result.unverifiable.push(
          `${relative} re-exports \`${exported}\` from another package`,
        );
        return null;
      }
      result.unverifiable.push(
        `${relative} exports \`${exported}\` from an unknown binding \`${name}\``,
      );
      return null;
    };
    // Explicit exports first: they take precedence over star exports.
    const stars: RuntimeExports[] = [];
    for (const statement of ast.program.body) {
      if (statement.type === 'ExportDefaultDeclaration') {
        result.names.set('default', identity('default'));
        continue;
      }
      if (statement.type === 'ExportAllDeclaration') {
        if (statement.exportKind === 'type') {
          continue;
        }
        const exports = await sourceExports(statement.source.value);
        if (exports === null) {
          result.unverifiable.push(
            `${relative} re-exports everything from ${statement.source.value}`,
          );
          continue;
        }
        stars.push(exports);
        continue;
      }
      if (
        statement.type !== 'ExportNamedDeclaration' ||
        statement.exportKind === 'type'
      ) {
        continue;
      }
      const declaration = statement.declaration;
      if (declaration) {
        if (
          (declaration.type === 'FunctionDeclaration' ||
            declaration.type === 'ClassDeclaration') &&
          declaration.id
        ) {
          result.names.set(declaration.id.name, identity(declaration.id.name));
        } else if (declaration.type === 'VariableDeclaration') {
          for (const declarator of declaration.declarations) {
            patternNames(declarator.id).forEach((name) =>
              result.names.set(name, identity(name)),
            );
          }
        }
        continue;
      }
      let from: RuntimeExports | null | undefined;
      if (statement.source) {
        from = await sourceExports(statement.source.value);
        if (from === null) {
          result.unverifiable.push(
            `${relative} re-exports from ${statement.source.value}`,
          );
          continue;
        }
      }
      for (const specifier of statement.specifiers) {
        if (specifier.type === 'ExportNamespaceSpecifier') {
          result.names.set(
            specifier.exported.name,
            `${relative}::*ns:${statement.source?.value ?? ''}`,
          );
          continue;
        }
        if (
          specifier.type !== 'ExportSpecifier' ||
          specifier.exportKind === 'type'
        ) {
          continue;
        }
        const exported =
          specifier.exported.type === 'Identifier'
            ? specifier.exported.name
            : specifier.exported.value;
        const resolved = from
          ? linked(
              from,
              specifier.local.name,
              statement.source?.value ?? '',
              exported,
            )
          : await resolveLocal(specifier.local.name, exported);
        if (resolved !== null) {
          result.names.set(exported, resolved);
        }
      }
    }
    // Star exports: every name not exported explicitly, unless two stars
    // provide it from different bindings.
    const starNames = new Map<string, string>();
    for (const exports of stars) {
      for (const name of exports.ambiguous) {
        result.ambiguous.add(name);
      }
      for (const [name, binding] of exports.names) {
        if (name === 'default' || result.names.has(name)) {
          continue;
        }
        const seen = starNames.get(name);
        if (seen !== undefined && seen !== binding) {
          result.ambiguous.add(name);
          continue;
        }
        starNames.set(name, binding);
      }
    }
    for (const [name, binding] of starNames) {
      if (!result.ambiguous.has(name) && !result.names.has(name)) {
        result.names.set(name, binding);
      }
    }
    for (const name of result.ambiguous) {
      result.names.delete(name);
    }
    return result;
  } finally {
    traversal.active.delete(file);
    traversal.done.set(file, result);
  }
}

const REQUIRED_HOOKS = ['usePageContext', 'useSiteContext'] as const;

/**
 * Checks whether the installed `drupal-canvas` package of a project exports
 * both context hooks as runtime values from its React entry, whatever version
 * it reports. Fails closed: an entry that cannot be resolved or parsed, or
 * exports that cannot be determined statically, count as unsupported.
 */
export async function checkInstalledPackage(
  projectRoot: string,
): Promise<{ ok: boolean; reason?: string }> {
  const packageJsonPath = await locatePackageJson(projectRoot);
  if (packageJsonPath === null) {
    return {
      ok: false,
      reason:
        'the `drupal-canvas` package is not installed in this project (run `npm install drupal-canvas@latest`)',
    };
  }
  const packageRoot = path.dirname(packageJsonPath);
  let entry: string | null;
  try {
    entry = resolveRuntimeEntry(
      JSON.parse(await fs.readFile(packageJsonPath, 'utf-8')) as Parameters<
        typeof resolveRuntimeEntry
      >[0],
    );
  } catch {
    return {
      ok: false,
      reason: 'the installed `drupal-canvas` package could not be read',
    };
  }
  if (entry === null) {
    return {
      ok: false,
      reason:
        'the installed `drupal-canvas` package declares no `drupal-canvas/react` entry to import',
    };
  }
  const exports = await collectRuntimeExports(
    path.resolve(packageRoot, entry),
    packageRoot,
  );
  // Any part of the export graph that could not be determined leaves the
  // whole set uncertain, whatever was found elsewhere: fail closed.
  if (exports.unverifiable.length > 0) {
    return {
      ok: false,
      reason: `the installed \`drupal-canvas\` package's exports could not be verified (${[...new Set(exports.unverifiable)].join('; ')}); run \`npm install drupal-canvas@latest\` and pull again`,
    };
  }
  const ambiguous = REQUIRED_HOOKS.filter((hook) =>
    exports.ambiguous.has(hook),
  );
  if (ambiguous.length > 0) {
    return {
      ok: false,
      reason: `the installed \`drupal-canvas\` package exports ${ambiguous
        .map((hook) => `\`${hook}()\``)
        .join(
          ' and ',
        )} ambiguously (conflicting \`export *\` sources); run \`npm install drupal-canvas@latest\``,
    };
  }
  const missing = REQUIRED_HOOKS.filter((hook) => !exports.names.has(hook));
  if (missing.length === 0) {
    return { ok: true };
  }
  return {
    ok: false,
    reason: `the installed \`drupal-canvas\` package does not export ${missing
      .map((hook) => `\`${hook}()\``)
      .join(
        ' and ',
      )} as runtime values (run \`npm install drupal-canvas@latest\`)`,
  };
}

/**
 * Evaluates both capability gates.
 */
export async function evaluateContextHookSupport(
  options: ContextHookSupportOptions,
): Promise<ContextHookSupport> {
  const reasons: string[] = [];
  try {
    const data = (await (options.fetchSiteData ?? fetchSiteData)(
      options.siteUrl,
    )) as { capabilities?: { contextHooks?: unknown } } | null;
    if (data?.capabilities?.contextHooks !== true) {
      reasons.push(
        'the site does not advertise context-hook support at /canvas/api/v0/site-data (update the Drupal Canvas module)',
      );
    }
  } catch (error) {
    reasons.push(
      `context-hook support could not be verified at /canvas/api/v0/site-data (${
        error instanceof Error ? error.message : String(error)
      })`,
    );
  }
  const installed = await checkInstalledPackage(options.projectRoot);
  if (!installed.ok && installed.reason) {
    reasons.push(installed.reason);
  }
  return { supported: reasons.length === 0, reasons };
}
