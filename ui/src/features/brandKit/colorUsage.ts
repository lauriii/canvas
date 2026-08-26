import type {
  ColorComponentUsage,
  ColorUsageDetailsResponse,
} from '@/services/brandKit';

/**
 * Keep only one usage per component UUID.
 */
export function deduplicateUsagesByComponentUuid<T extends ColorComponentUsage>(
  usages: T[],
): T[] {
  const seen = new Set<string>();
  return usages.filter((usage) => {
    if (seen.has(usage.component_uuid)) {
      return false;
    }
    seen.add(usage.component_uuid);
    return true;
  });
}

/**
 * Count distinct component instances per entity.
 */
export function countUniqueComponentUsages<
  T extends { usages: ColorComponentUsage[] },
>(entities: T[]): number {
  return entities.reduce(
    (sum, entity) =>
      sum + deduplicateUsagesByComponentUuid(entity.usages).length,
    0,
  );
}

/**
 * Count distinct component instances across the current + config usage buckets.
 */
export function countUniqueCurrentAndConfigUsages(
  usageDetails?: Pick<ColorUsageDetailsResponse, 'current' | 'config'>,
): number {
  if (!usageDetails) {
    return 0;
  }
  const currentCount = countUniqueComponentUsages(usageDetails.current ?? []);
  const configCount = countUniqueComponentUsages(usageDetails.config ?? []);
  return currentCount + configCount;
}
