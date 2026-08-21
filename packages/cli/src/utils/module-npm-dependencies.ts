import { existsSync, promises as fs } from 'node:fs';
import { createRequire } from 'node:module';
import path from 'node:path';
import semver from 'semver';

/**
 * npm packages the site's modules and themes declare.
 *
 * A Drupal module whose code component JavaScript is published on npm
 * declares the package and the exact version it ships under `canvas.npm` in
 * its info file. The site exposes the union on the global asset library as
 * `npmDependencies`, and `canvas pull` adds the ones missing from the
 * project's `package.json`, so the copy the CLI bundles and Workbench previews
 * is the copy the site's extensions were written for.
 *
 * What pull wrote is recorded under `canvas.npmDependencies` in the same file.
 * The record is what lets a later pull tell its own writes from the
 * developer's, and what lets a build compare installed versions against what
 * the site declared without reaching the site.
 *
 * The merge only ever adds a missing declared package, once. It never removes
 * a dependency, never reorders the file, and never changes a value: those are
 * the developer's, and `npm` is the tool for them. A pull of what was pushed
 * gives the pushed file back, byte for byte.
 */
export type NpmDependencies = Record<string, string>;

export const PACKAGE_JSON_RECORD_KEY = 'npmDependencies';

const DEPENDENCY_SECTIONS = [
  'dependencies',
  'devDependencies',
  'peerDependencies',
  'optionalDependencies',
] as const;

export interface MergeNpmDependenciesResult {
  /** The new file contents, or `null` when nothing changed. */
  text: string | null;
  /** Packages added to `dependencies` because they were missing. */
  added: string[];
  /**
   * Packages whose `package.json` value is not the declared version and not a
   * range that allows it. Left alone and reported, whoever wrote the value.
   */
  conflicts: { name: string; declared: string; current: string }[];
  /**
   * Packages an earlier pull added that the developer has since removed. They
   * are not re-added, now or on any later pull.
   */
  removedByDeveloper: string[];
  /**
   * Packages an earlier pull added that the site no longer declares. They stay
   * in `package.json`; only the record forgets them.
   */
  noLongerDeclared: string[];
}

interface PackageJsonShape {
  dependencies?: Record<string, string>;
  devDependencies?: Record<string, string>;
  peerDependencies?: Record<string, string>;
  optionalDependencies?: Record<string, string>;
  canvas?: { [PACKAGE_JSON_RECORD_KEY]?: NpmDependencies } & Record<
    string,
    unknown
  >;
  [key: string]: unknown;
}

/**
 * Detects the indentation of a JSON document so a rewrite preserves it.
 */
function detectIndent(text: string): string {
  const match = /^( +|\t+)"/m.exec(text);
  return match ? match[1] : '  ';
}

/**
 * Finds which dependency section lists a package, if any.
 */
function findSection(
  parsed: PackageJsonShape,
  name: string,
): (typeof DEPENDENCY_SECTIONS)[number] | null {
  for (const section of DEPENDENCY_SECTIONS) {
    const entries = parsed[section];
    if (entries && Object.hasOwn(entries, name)) {
      return section;
    }
  }
  return null;
}

/**
 * Whether a developer's version spec is a semver range that the declared
 * version satisfies (`^1.2.0`, `~1.2.0`, `>=1.2.0`, `1.x`).
 *
 * Such a spec is not reported as a disagreement: the developer chose a range
 * on purpose, the declared version is inside it, and the build compares the
 * installed version anyway. A spec that is not a range (`file:`, `git+`, a
 * tag, a URL) or that excludes the declared version is a disagreement.
 */
function rangeAllowsDeclared(current: string, declared: string): boolean {
  return (
    semver.validRange(current) !== null && semver.satisfies(declared, current)
  );
}

/**
 * Merges the site's declared packages into a `package.json` document.
 *
 * Push and pull are a mirror: what a developer pushed is what a pull gives
 * back. So this never rewrites a value, never removes a dependency, never
 * reorders the file, and writes nothing at all when the file already has
 * every declared package. The one write it makes is to add a declared
 * package that is missing, once: the record remembers that it did, so a
 * developer who later removes that package is not overruled on the next pull.
 * A declaration that moved on from what the file has is reported, with the
 * `npm install` that would follow it, and left to the developer.
 */
export function mergeNpmDependencies(
  packageJsonText: string,
  declared: NpmDependencies,
): MergeNpmDependenciesResult {
  const parsed = JSON.parse(packageJsonText) as PackageJsonShape;
  const previousRecord = {
    ...(parsed.canvas?.[PACKAGE_JSON_RECORD_KEY] ?? {}),
  };
  const result: MergeNpmDependenciesResult = {
    text: null,
    added: [],
    conflicts: [],
    removedByDeveloper: [],
    noLongerDeclared: [],
  };
  // Packages this or an earlier pull added, with the version the site declares
  // now. An entry outlives the dependency: once pull has added a package, a
  // developer's decision to remove it again is final.
  const record: NpmDependencies = {};

  for (const [name, version] of Object.entries(declared)) {
    const section = findSection(parsed, name);
    const addedByPull = Object.hasOwn(previousRecord, name);
    if (section === null) {
      if (addedByPull) {
        result.removedByDeveloper.push(name);
        record[name] = version;
        continue;
      }
      parsed.dependencies = { ...(parsed.dependencies ?? {}), [name]: version };
      result.added.push(name);
      record[name] = version;
      continue;
    }
    if (addedByPull) {
      record[name] = version;
    }
    const current = (parsed[section] as Record<string, string>)[name];
    if (current !== version && !rangeAllowsDeclared(current, version)) {
      result.conflicts.push({ name, declared: version, current });
    }
  }

  for (const name of Object.keys(previousRecord)) {
    if (!Object.hasOwn(declared, name)) {
      result.noLongerDeclared.push(name);
    }
  }

  const recordChanged =
    JSON.stringify(sortKeys(record)) !==
    JSON.stringify(sortKeys(previousRecord));
  if (result.added.length === 0 && !recordChanged) {
    return result;
  }

  const canvas = { ...(parsed.canvas ?? {}) };
  if (Object.keys(record).length > 0) {
    canvas[PACKAGE_JSON_RECORD_KEY] = sortKeys(record);
  } else {
    delete canvas[PACKAGE_JSON_RECORD_KEY];
  }
  if (Object.keys(canvas).length > 0) {
    parsed.canvas = canvas;
  } else {
    delete parsed.canvas;
  }

  const trailingNewline = packageJsonText.endsWith('\n') ? '\n' : '';
  result.text =
    JSON.stringify(parsed, null, detectIndent(packageJsonText)) +
    trailingNewline;
  return result;
}

function sortKeys(record: Record<string, string>): Record<string, string> {
  return Object.fromEntries(
    Object.entries(record).sort(([a], [b]) => a.localeCompare(b)),
  );
}

export interface InstalledNpmDependenciesCheck {
  /** Declared by the site, imported by a component, but not installed. */
  missing: { name: string; declared: string }[];
  /** Imported and installed, at a version other than the one declared. */
  mismatched: { name: string; declared: string; installed: string }[];
}

/**
 * Compares what the site's extensions declared against what is installed, for
 * the packages the components actually import.
 *
 * Keyed on imports rather than on the record alone, so a stale record (a
 * package the project stopped using) can never fail a build. Reads the record
 * `canvas pull` left in `package.json`, so it needs no network and behaves the
 * same in CI. A missing package would fail the vendor build anyway, but with
 * a far less useful error; a mismatched version builds fine and is the case
 * worth naming, because the component is bundled with one version while the
 * site's extension was written for another.
 *
 * @param importedPackages
 *   The bare specifiers components import, as collected for the vendor build.
 */
export async function checkInstalledNpmDependencies(
  projectRoot: string,
  importedPackages: Iterable<string>,
): Promise<InstalledNpmDependenciesCheck> {
  const result: InstalledNpmDependenciesCheck = { missing: [], mismatched: [] };
  let parsed: PackageJsonShape;
  try {
    parsed = JSON.parse(
      await fs.readFile(path.join(projectRoot, 'package.json'), 'utf-8'),
    ) as PackageJsonShape;
  } catch {
    return result;
  }
  const record = parsed.canvas?.[PACKAGE_JSON_RECORD_KEY] ?? {};
  const imported = [...importedPackages];
  for (const [name, declared] of Object.entries(record)) {
    const isImported = imported.some(
      (specifier) => specifier === name || specifier.startsWith(`${name}/`),
    );
    if (!isImported) {
      continue;
    }
    const installed = await readInstalledVersion(name, projectRoot);
    if (installed === null) {
      result.missing.push({ name, declared });
    } else if (installed !== declared) {
      result.mismatched.push({ name, declared, installed });
    }
  }
  return result;
}

/**
 * Reads the version of an installed package, or null when it is not installed.
 *
 * Uses Node's own resolution from the project root, so a package hoisted to a
 * parent `node_modules` (workspaces) or linked by a package manager is found
 * the same way the bundler will find it. A package whose `exports` map hides
 * `./package.json`, or exposes only an `import` condition that CommonJS
 * resolution cannot see, is installed all the same: for those the manifest is
 * located by walking the `node_modules` directories from the project root up,
 * which is where Node's own algorithm would have looked.
 */
async function readInstalledVersion(
  name: string,
  projectRoot: string,
): Promise<string | null> {
  const requireFromProject = createRequire(path.join(projectRoot, 'noop.cjs'));
  let manifestPath: string | undefined;
  try {
    manifestPath = requireFromProject.resolve(`${name}/package.json`);
  } catch (error) {
    if ((error as NodeJS.ErrnoException).code === 'MODULE_NOT_FOUND') {
      return null;
    }
    manifestPath = findManifestInNodeModules(name, projectRoot);
    if (manifestPath === undefined) {
      return null;
    }
  }
  try {
    const manifest = JSON.parse(await fs.readFile(manifestPath, 'utf-8')) as {
      version?: string;
    };
    return manifest.version ?? null;
  } catch {
    return null;
  }
}

function findManifestInNodeModules(
  name: string,
  projectRoot: string,
): string | undefined {
  let dir = path.resolve(projectRoot);
  for (;;) {
    const candidate = path.join(
      dir,
      'node_modules',
      ...name.split('/'),
      'package.json',
    );
    if (existsSync(candidate)) {
      return candidate;
    }
    const parent = path.dirname(dir);
    if (parent === dir) {
      return undefined;
    }
    dir = parent;
  }
}
