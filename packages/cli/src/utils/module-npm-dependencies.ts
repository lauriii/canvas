import { promises as fs } from 'node:fs';
import { createRequire } from 'node:module';
import path from 'node:path';

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
 * The merge only ever adds. It never removes a dependency, never reorders the
 * file, and never changes a value it did not write itself: those are the
 * developer's, and `npm` is the tool for them.
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
  /** Packages added to `dependencies`, or updated from a version pull wrote. */
  added: string[];
  /**
   * Packages whose `package.json` value differs from what the site declares
   * and was not written by an earlier pull, so it was left alone.
   */
  conflicts: { name: string; declared: string; current: string }[];
  /**
   * Packages an earlier pull added that the developer has since removed. They
   * are not re-added, and no longer recorded.
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
 * Whether a developer's version spec is a range built on the declared version
 * (`^1.2.0`, `~1.2.0`, `>=1.2.0`). Not reported as a disagreement: the
 * developer chose a range on purpose, and the build compares the installed
 * version anyway.
 */
function isRangeOn(current: string, declared: string): boolean {
  return current !== declared && current.includes(declared);
}

/**
 * Merges the site's declared packages into a `package.json` document.
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
  const record: NpmDependencies = {};

  for (const [name, version] of Object.entries(declared)) {
    const section = findSection(parsed, name);
    if (section === null) {
      if (Object.hasOwn(previousRecord, name)) {
        // Pull added it once and the developer took it out again. Their call.
        result.removedByDeveloper.push(name);
        continue;
      }
      parsed.dependencies = { ...(parsed.dependencies ?? {}), [name]: version };
      result.added.push(name);
      record[name] = version;
      continue;
    }
    const entries = parsed[section] as Record<string, string>;
    const current = entries[name];
    if (current === version) {
      record[name] = version;
    } else if (previousRecord[name] === current) {
      // Pull wrote this value and nobody changed it: follow the site's new
      // declaration, in whichever section the developer keeps it.
      entries[name] = version;
      result.added.push(name);
      record[name] = version;
    } else if (!isRangeOn(current, version)) {
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
 * `./package.json` is located through its entry point instead.
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
    // `exports` does not expose `./package.json`: resolve the entry point and
    // walk up to the nearest manifest that carries this package's name.
    let entry: string;
    try {
      entry = requireFromProject.resolve(name);
    } catch {
      return null;
    }
    let dir = path.dirname(entry);
    while (manifestPath === undefined) {
      const candidate = path.join(dir, 'package.json');
      try {
        const manifest = JSON.parse(await fs.readFile(candidate, 'utf-8')) as {
          name?: string;
        };
        if (manifest.name === name) {
          manifestPath = candidate;
          break;
        }
      } catch {
        // Keep walking.
      }
      const parent = path.dirname(dir);
      if (parent === dir) {
        return null;
      }
      dir = parent;
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
