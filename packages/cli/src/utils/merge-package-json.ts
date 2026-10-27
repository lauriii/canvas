import { isHeadlessSdkPackage } from '@drupal-canvas/discovery';

/**
 * Result of merging pulled dependencies into a local `package.json`.
 *
 * `output` is the serialized file to write, or `null` when nothing changed so
 * the caller leaves the on-disk file untouched. `added` lists the dependency
 * names that were added, for reporting.
 */
export interface MergePackageJsonResult {
  output: string | null;
  added: string[];
}

type DependencyMap = Record<string, string>;

function asDependencyMap(value: unknown): DependencyMap {
  if (value && typeof value === 'object' && !Array.isArray(value)) {
    return value as DependencyMap;
  }
  return {};
}

/**
 * Merges dependencies from a pulled `package.json` into a local one, add-only.
 *
 * A dependency from the pulled `dependencies` is added to the local
 * `dependencies` only when its name is absent from the local `dependencies`,
 * `devDependencies`, and `peerDependencies`. Existing entries keep their
 * version and placement; every other local field is preserved. Pulled
 * `devDependencies` are ignored.
 *
 * @param localRaw
 *   The on-disk `package.json` contents.
 * @param pulledRaw
 *   The `package.json` contents fetched from Drupal.
 *
 * @returns
 *   `output` is the serialized merged file (2-space indent, trailing newline)
 *   when at least one dependency was added, otherwise `null`. `added` lists the
 *   added dependency names.
 *
 * @throws {SyntaxError}
 *   When either input is not valid JSON.
 */
export function mergePackageJsonDependencies(
  localRaw: string,
  pulledRaw: string,
): MergePackageJsonResult {
  const local = JSON.parse(localRaw) as Record<string, unknown>;
  const pulled = JSON.parse(pulledRaw) as Record<string, unknown>;

  const localDependencies = asDependencyMap(local.dependencies);
  const known = new Set<string>([
    ...Object.keys(localDependencies),
    ...Object.keys(asDependencyMap(local.devDependencies)),
    ...Object.keys(asDependencyMap(local.peerDependencies)),
  ]);

  const pulledDependencies = asDependencyMap(pulled.dependencies);
  const added: string[] = [];
  const mergedDependencies: DependencyMap = { ...localDependencies };
  for (const [name, version] of Object.entries(pulledDependencies)) {
    if (known.has(name)) {
      continue;
    }
    mergedDependencies[name] = version;
    added.push(name);
  }

  if (added.length === 0) {
    return { output: null, added };
  }

  const merged = { ...local, dependencies: mergedDependencies };
  return { output: `${JSON.stringify(merged, null, 2)}\n`, added };
}

/**
 * Removes Canvas Headless SDK packages from a pulled `package.json`.
 *
 * Drupal stores the `package.json` of whichever codebase pushed last, which can
 * be a headless app. Writing its `@drupal-canvas/headless*` dependencies into a
 * non-headless project would make the next `canvas push` treat every component
 * as external, so they are dropped from `dependencies` and `devDependencies`.
 * Everything else, including the order of keys, is kept.
 *
 * @param pulledRaw
 *   The `package.json` contents fetched from Drupal.
 *
 * @returns
 *   The contents without Headless SDK dependencies, re-serialized (2-space
 *   indent, trailing newline) only when one was removed. Otherwise, or when
 *   the input is not valid JSON, `pulledRaw` unchanged.
 */
export function removeHeadlessSdkDependencies(pulledRaw: string): string {
  let pulled: Record<string, unknown>;
  try {
    pulled = JSON.parse(pulledRaw) as Record<string, unknown>;
  } catch {
    // Leave invalid input to the caller's existing handling.
    return pulledRaw;
  }
  if (!pulled || typeof pulled !== 'object' || Array.isArray(pulled)) {
    return pulledRaw;
  }

  let removed = false;
  const filtered: Record<string, unknown> = { ...pulled };
  for (const section of ['dependencies', 'devDependencies'] as const) {
    if (!(section in pulled)) {
      continue;
    }
    const dependencies = asDependencyMap(pulled[section]);
    const kept = Object.fromEntries(
      Object.entries(dependencies).filter(
        ([name]) => !isHeadlessSdkPackage(name),
      ),
    );
    if (Object.keys(kept).length !== Object.keys(dependencies).length) {
      filtered[section] = kept;
      removed = true;
    }
  }

  return removed ? `${JSON.stringify(filtered, null, 2)}\n` : pulledRaw;
}
