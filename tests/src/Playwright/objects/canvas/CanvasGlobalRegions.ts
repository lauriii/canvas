import { expect } from '@playwright/test';

import type { CanvasBase } from './CanvasBase.js';

type Constructor<T = {}> = new (...args: any[]) => T;

export function CanvasGlobalRegionsMixin<TBase extends Constructor<CanvasBase>>(
  Base: TBase,
) {
  return class extends Base {
    /**
     * Enables Canvas page regions via Appearance > theme settings.
     *
     * @see \Drupal\canvas\Hook\PageRegionHooks::formSystemThemeSettingsSubmit
     */
    async enableGlobalRegions(theme = 'stark') {
      await this.page.goto(`/admin/appearance/settings/${theme}`);
      const useCanvas = this.page.locator('[name="use_canvas"]');
      await expect(useCanvas).toBeVisible();
      if (await useCanvas.isChecked()) {
        return;
      }
      await useCanvas.check();
      await this.page
        .getByRole('button', { name: 'Save configuration' })
        .click();
      await expect(this.page.locator('[data-drupal-messages]')).toContainText(
        /saved/,
      );
    }
  };
}
