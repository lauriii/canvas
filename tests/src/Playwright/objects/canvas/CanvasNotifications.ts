import { expect } from '@playwright/test';

import type { Page } from '@playwright/test';
import type { CanvasBase } from './CanvasBase.js';

type Constructor<T = {}> = new (...args: any[]) => T;

export function CanvasNotificationsMixin<TBase extends Constructor<CanvasBase>>(
  Base: TBase,
) {
  return class extends Base {
    /**
     * Creates a notification via the canvas_test_notifications admin form.
     *
     * Accepts an optional `page` parameter to run on a different browser
     * context (e.g. an admin window), defaulting to the main page.
     */
    async createNotification(
      fields: {
        type: 'info' | 'success' | 'warning' | 'error' | 'processing';
        title: string;
        message: string;
        key?: string;
        actions?: Array<{ label: string; href: string }>;
      },
      page?: Page,
    ) {
      const p = page ?? this.page;
      await p.goto('/admin/config/content/canvas/test-notifications');
      await p.getByLabel('Type').selectOption(fields.type);
      if (fields.key) {
        await p.getByLabel('Key').fill(fields.key);
      }
      await p.getByLabel('Title').fill(fields.title);
      await p.getByLabel('Message').fill(fields.message);
      if (fields.actions) {
        await p
          .getByLabel('Actions (JSON)')
          .fill(JSON.stringify(fields.actions));
      }
      await p.getByRole('button', { name: 'Create notification' }).click();
      await expect(p.getByText('Notification created with ID:')).toBeVisible();
    }
  };
}
