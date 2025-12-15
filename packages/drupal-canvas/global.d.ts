import type { DrupalSettings } from '../../ui/src/types/DrupalSettings.ts';

declare global {
  interface Window {
    drupalSettings: DrupalSettings;
  }
}
