import type { Page } from '@playwright/test';

export class Ai {
  readonly page: Page;

  constructor({ page }: { page: Page }) {
    this.page = page;
  }

  async openPanel() {
    await this.page.getByRole('button', { name: 'Open AI Panel' }).click();
    await this.page.locator('deep-chat').evaluate((el) => {
      const shadowRoot = el.shadowRoot;
      if (!shadowRoot) {
        throw new Error('No shadow root found');
      }
      const textInputDiv = shadowRoot.querySelector('div#text-input');

      if (!textInputDiv) {
        throw new Error('No div with id "text-input" found in shadow root');
      }
    });
  }

  async submitQuery(query: string) {
    await this.page.getByRole('textbox', { name: 'Build me a' }).fill(query);
    await this.page
      .getByTestId('canvas-ai-panel')
      .getByRole('button')
      .nth(1)
      .click();
  }
}
