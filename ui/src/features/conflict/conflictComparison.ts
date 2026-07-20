import type {
  PendingChange,
  PendingChanges,
} from '@/services/pendingChangesApi';

export type ConflictChangeEntry = {
  pointer: string;
  change: PendingChange;
};

export const getConflictQueue = (
  pendingChanges: PendingChanges | undefined,
): ConflictChangeEntry[] =>
  Object.entries(pendingChanges ?? {})
    .filter(
      ([, change]) =>
        change.entity_type === 'canvas_page' && change.hasConflict,
    )
    .sort(([, a], [, b]) => b.updated - a.updated)
    .map(([pointer, change]) => ({ pointer, change }));

export const findConflictIndex = (
  queue: ConflictChangeEntry[],
  entityType?: string,
  entityId?: string,
): number =>
  queue.findIndex(
    ({ change }) =>
      change.entity_type === entityType &&
      String(change.entity_id) === entityId,
  );

export const getConflictRouteFromEntry = ({
  change,
}: ConflictChangeEntry): string =>
  `/conflict/${change.entity_type}/${encodeURIComponent(String(change.entity_id))}`;

export const getNextConflictEntry = (
  queue: ConflictChangeEntry[],
  currentPointer: string,
): ConflictChangeEntry | undefined => {
  const remaining = queue.filter(({ pointer }) => pointer !== currentPointer);
  if (!remaining.length) {
    return undefined;
  }
  const currentIndex = queue.findIndex(
    ({ pointer }) => pointer === currentPointer,
  );
  return remaining[Math.min(currentIndex, remaining.length - 1)];
};
