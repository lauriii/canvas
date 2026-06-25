import { getCanvasSettings } from '@/utils/drupal-globals';

export const isConflictUxEnabled = (): boolean =>
  getCanvasSettings()?.devConflictDetectionMode === true;
