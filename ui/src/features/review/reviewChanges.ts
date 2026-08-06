import type {
  PendingChange,
  PendingChanges,
} from '@/services/pendingChangesApi';
import type { UnpublishedChange } from '@/types/Review';

export type ReviewChangeEntry = {
  pointer: string;
  change: PendingChange;
};

const isPointerList = (value: unknown): value is string[] =>
  Array.isArray(value) && value.every((item) => typeof item === 'string');

export const getReviewRouteForChange = (
  change: Pick<UnpublishedChange, 'entity_type' | 'entity_id'>,
): string =>
  `/review/${change.entity_type}/${encodeURIComponent(String(change.entity_id))}`;

export const isReviewableChange = (
  change: Pick<UnpublishedChange, 'entity_type' | 'hasConflict'>,
): boolean => change.entity_type === 'canvas_page' && !change.hasConflict;

export const getReviewRouteStatePointers = (
  state: unknown,
): {
  selectedPointers?: string[];
  reviewPointers?: string[];
  reviewComplete?: boolean;
} => {
  if (!state || typeof state !== 'object') {
    return {};
  }

  const candidate = state as Record<string, unknown>;

  return {
    selectedPointers: isPointerList(candidate.selectedPointers)
      ? candidate.selectedPointers
      : undefined,
    reviewPointers: isPointerList(candidate.reviewPointers)
      ? candidate.reviewPointers
      : undefined,
    reviewComplete: candidate.reviewComplete === true,
  };
};

export const getReviewQueue = (
  pendingChanges: PendingChanges | undefined,
  reviewPointers?: string[],
): ReviewChangeEntry[] => {
  const reviewPointerOrder = new Map(
    reviewPointers?.map((pointer, index) => [pointer, index]) ?? [],
  );
  const hasReviewPointers = reviewPointerOrder.size > 0;

  return Object.entries(pendingChanges ?? {})
    .filter(
      ([pointer, change]) =>
        isReviewableChange(change) &&
        (!hasReviewPointers || reviewPointerOrder.has(pointer)),
    )
    .sort(([pointerA, changeA], [pointerB, changeB]) => {
      if (hasReviewPointers) {
        return (
          (reviewPointerOrder.get(pointerA) ?? Number.MAX_SAFE_INTEGER) -
          (reviewPointerOrder.get(pointerB) ?? Number.MAX_SAFE_INTEGER)
        );
      }
      return changeB.updated - changeA.updated;
    })
    .map(([pointer, change]) => ({ pointer, change }));
};

export const findReviewIndex = (
  queue: ReviewChangeEntry[],
  entityType?: string,
  entityId?: string,
): number =>
  queue.findIndex(
    ({ change }) =>
      change.entity_type === entityType &&
      String(change.entity_id) === entityId,
  );

export const getReviewRouteFromEntry = ({
  change,
}: ReviewChangeEntry): string => getReviewRouteForChange(change);
