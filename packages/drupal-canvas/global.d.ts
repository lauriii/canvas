import type { DrupalSettings } from '@drupal-canvas/types';

declare global {
  interface Window {
    drupalSettings: DrupalSettings;
  }
}
