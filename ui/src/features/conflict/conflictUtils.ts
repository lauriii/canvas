import { getCanvasSettings } from '@/utils/drupal-globals';

import type { UnpublishedChange } from '@/types/Review';

export const getConflictRouteForChange = (
  change: Pick<UnpublishedChange, 'entity_type' | 'entity_id'>,
): string =>
  `/conflict/${change.entity_type}/${encodeURIComponent(String(change.entity_id))}`;

export const isConflictUxEnabled = (): boolean =>
  getCanvasSettings()?.devConflictDetectionMode === true;
