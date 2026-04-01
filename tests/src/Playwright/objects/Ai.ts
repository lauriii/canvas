import type { Page } from '@playwright/test';

export class Ai {
  readonly page: Page;

  constructor({ page }: { page: Page }) {
    this.page = page;
  }

  async openPanel() {
    await this.page.getByRole('button', { name: 'Open AI Panel' }).click();
    // `deep-chat` is a web component with shadow DOM; wait for it to be fully
    // initialized to reduce cross-browser flakiness.
    await this.page.locator('deep-chat').waitFor({ state: 'attached' });
    await this.page.waitForFunction(() => {
      const el = document.querySelector('deep-chat') as HTMLElement | null;
      const shadowRoot = el?.shadowRoot;
      return !!shadowRoot?.querySelector('div#text-input');
    });
  }

  async submitQuery(query: string) {
    const input = this.page.getByRole('textbox', { name: 'Build me a' });
    await input.fill(query);
    // Submitting via keyboard is less brittle than relying on button ordering.
    await input.press('Enter');
  }
}
