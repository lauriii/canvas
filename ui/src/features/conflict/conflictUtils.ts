import { getCanvasSettings } from '@/utils/drupal-globals';

export const isConflictUxEnabled = (): boolean =>
  getCanvasSettings()?.devMode === true;
