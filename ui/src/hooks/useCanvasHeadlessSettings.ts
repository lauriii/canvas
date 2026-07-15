import { useSyncExternalStore } from 'react';

import {
  CANVAS_HEADLESS_SETTINGS_CHANGE,
  getCanvasHeadlessSettings,
  restoreCanvasHeadlessFrontend,
} from '@/utils/drupal-globals';

import type { HeadlessSettings } from '@drupal-canvas/types';

restoreCanvasHeadlessFrontend();

const subscribe = (onStoreChange: () => void) => {
  window.addEventListener(CANVAS_HEADLESS_SETTINGS_CHANGE, onStoreChange);
  return () =>
    window.removeEventListener(CANVAS_HEADLESS_SETTINGS_CHANGE, onStoreChange);
};

/**
 * Returns the current headless settings and updates when the active frontend
 * changes without a page reload.
 */
export const useCanvasHeadlessSettings = () =>
  useSyncExternalStore<HeadlessSettings | undefined>(
    subscribe,
    getCanvasHeadlessSettings,
    getCanvasHeadlessSettings,
  );
