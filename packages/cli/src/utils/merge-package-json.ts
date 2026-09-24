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
