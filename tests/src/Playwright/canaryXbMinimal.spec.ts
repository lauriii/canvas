import { expect } from '@playwright/test';
import { test } from './fixtures/DrupalSite';

/**
 * Tests installing Experience Builder.
 */
test.describe('Canary XB Minimal', () => {
  test('Setup minimal test site with Experience Builder', async ({
    page,
    drupal,
  }) => {
    await drupal.setupMinimalXBTestSite();
    await drupal.loginAsAdmin();
    await page.goto('/homepage');
    /* eslint-disable no-useless-escape */
    await expect(page.locator('#block-stark-local-tasks')).toMatchAriaSnapshot(`
      - heading "Primary tabs" [level=2]
      - list:
        - listitem:
          - link "View":
            - /url: /homepage
        - listitem:
          - link "Edit":
            - /url: /\/xb\/xb_page\/\\d+/
    `);
    /* eslint-enable no-useless-escape */
  });

  test('Layer and Components Panel', async ({ page, drupal, xBEditor }) => {
    await drupal.loginAsAdmin();
    await page.goto('/homepage');
    const consoleErrors = [];
    page.on('console', async (msg) => {
      if (msg.type() === 'error') {
        const args = msg.args();
        // If the error was logged as an object, get its JSON representation.
        let error = null;
        if (args.length > 0) {
          try {
            error = await args[0].jsonValue();
            // eslint-disable-next-line @typescript-eslint/no-unused-vars
          } catch (e) {
            // If it's not JSON serializable, get the text
            error = await args[0].evaluate((arg) => arg.toString());
          }
          consoleErrors.push(error);
        }
      }
    });
    await xBEditor.goToEditor();
    await expect(page.locator('[data-testid="xb-side-menu"]'))
      .toMatchAriaSnapshot(`
      - button "Add":
        - img
      - button "Layers":
        - img
      - separator
      - button "Templates are coming soon" [disabled]:
        - img
    `);
    await page
      .locator('[data-testid="xb-side-menu"]')
      .locator('[aria-label="Add"]')
      .click();
    await expect(
      page.locator('[data-testid="xb-primary-panel"] h4:has-text("Library")'),
    ).toBeVisible();
    const collapsedButtons = page.locator(
      '[data-testid="xb-primary-panel"] button[aria-expanded="false"]',
    );
    const buttonCount = await collapsedButtons.count();
    for (let i = 0; i < buttonCount; i++) {
      const collapsedButton = page
        .locator(
          '[data-testid="xb-primary-panel"] button[aria-expanded="false"]',
        )
        .first();
      await collapsedButton.click();
    }
    await expect(page.locator('[data-testid="xb-primary-panel"]'))
      .toMatchAriaSnapshot(`
        - heading "Library" [level=4]
        - button:
          - img
        - button "Add new":
          - img
        - button "Patterns" [expanded]
        - region "Patterns":
          - img
          - paragraph: No items to show in Patterns
        - button "Components" [expanded]
        - region "Components":
          - list:
            - listitem:
              - img
              - text: Druplicon
            - listitem:
              - img
              - text: Heading
            - listitem:
              - img
              - text: Hero
            - listitem:
              - img
              - text: Image
            - listitem:
              - img
              - text: One Column
            - listitem:
              - img
              - text: Section
            - listitem:
              - img
              - text: Shoe Badge
            - listitem:
              - img
              - text: Shoe Tab
            - listitem:
              - img
              - text: Shoe Tab Group
            - listitem:
              - img
              - text: Shoe Tab Panel
            - listitem:
              - img
              - text: Two Column
        - button "Code" [expanded]
        - region "Code":
          - img
          - paragraph: No items to show in Code components
        - button "Dynamic Components" [expanded]
        - region "Dynamic Components":
          - button "Forms dynamic components"
          - button "Lists (Views) dynamic components"
          - button "Menus dynamic components"
          - button "System dynamic components"
          - button "User dynamic components"
      `);
    consoleErrors.forEach((consoleMessage) => {
      if (
        consoleMessage &&
        typeof consoleMessage === 'object' &&
        Object.hasOwn(consoleMessage, 'status')
      ) {
        expect(consoleMessage.status).not.toBe(500);
      }
    });
  });
});
