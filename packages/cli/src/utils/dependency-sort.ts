/**
 * Sorts items by dependencies into waves that can be processed in parallel.
 * Items in the same wave have no dependencies on each other.
 *
 * Uses Kahn's algorithm with level tracking.
 */
export function sortByDependencies<T extends { machineName: string }>(
  items: T[],
  getDependencies: (item: T) => string[],
): T[][] {
  const itemMap = new Map(items.map((item) => [item.machineName, item]));
  const inDegree = new Map(items.map((item) => [item.machineName, 0]));
  const dependents = new Map(
    items.map((item) => [item.machineName, [] as string[]]),
  );

  // Build dependency graph (only for deps that exist in our item set)
  for (const item of items) {
    for (const dep of getDependencies(item)) {
      if (itemMap.has(dep)) {
        dependents.get(dep)!.push(item.machineName);
        inDegree.set(item.machineName, inDegree.get(item.machineName)! + 1);
      }
    }
  }

  // Start with items that have no dependencies
  let currentWave = items.filter(
    (item) => inDegree.get(item.machineName) === 0,
  );
  const waves: T[][] = [];
  const processed = new Set<string>();

  while (currentWave.length > 0) {
    waves.push(currentWave);
    const nextWave: T[] = [];

    for (const item of currentWave) {
      processed.add(item.machineName);
      for (const dependentName of dependents.get(item.machineName)!) {
        const newDegree = inDegree.get(dependentName)! - 1;
        inDegree.set(dependentName, newDegree);
        if (newDegree === 0) {
          nextWave.push(itemMap.get(dependentName)!);
        }
      }
    }

    currentWave = nextWave;
  }

  // Add any remaining items (circular dependencies) as final wave
  const remaining = items.filter((item) => !processed.has(item.machineName));
  if (remaining.length > 0) {
    waves.push(remaining);
  }

  return waves;
}
