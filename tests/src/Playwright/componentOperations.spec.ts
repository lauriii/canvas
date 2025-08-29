import { test } from './fixtures/DrupalSite';
import { expect } from '@playwright/test';
import { Drupal } from './objects/Drupal';

const consoleErrors = [];

test.describe('Perform CRUD operations on components', () => {
  test.beforeAll(
    'Setup test site with Experience Builder',
    async ({ browser, drupalSite }) => {
      const page = await browser.newPage();
      const drupal: Drupal = new Drupal({ page, drupalSite });
      await drupal.installModules(['experience_builder', 'xb_test_sdc']);
      await page.close();
    },
  );

  test.beforeEach(async ({ drupal, page }) => {
    await drupal.loginAsAdmin();
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
  });

  test.afterEach(async () => {
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

  test('Layer and Components Panel', async ({ page, drupal, xBEditor }) => {
    await drupal.createXbPage('Panels', '/panels');
    await page.goto('/panels');
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
        - region "Patterns"
        - button "Components" [expanded]
        - region "Components":
          - button "Atom/Media" [expanded]:
            - img
            - img
          - list:
            - button "Video":
              - img
          - button "Atom/Tabs" [expanded]:
            - img
            - img
          - list:
            - button "Shoe Tab":
              - img
            - button "Shoe Tab Panel":
              - img
          - button "Atom/Text" [expanded]:
            - img
            - img
          - list:
            - button "Heading":
              - img
            - button "Shoe Badge":
              - img
          - button "Container" [expanded]:
            - img
            - img
          - list:
            - button "Grid Container":
              - img
            - button "One Column":
              - img
            - button "Two Column":
              - img
          - button "Container/Special" [expanded]:
            - img
            - img
          - list:
            - button "Shoe Tab Group":
              - img
          - button "core" [expanded]:
            - img
            - img
          - list:
            - button "Page title":
              - img
            - button "Primary admin actions":
              - img
            - button "Tabs":
              - img
          - button "Forms" [expanded]:
            - img
            - img
          - list:
            - button "User login":
              - img
          - button "Lists (Views)" [expanded]:
            - img
            - img
          - list:
            - button "Recent content":
              - img
            - button "Who's online":
              - img
          - button "Menus" [expanded]:
            - img
            - img
          - list:
            - button "Administration":
              - img
            - button "Footer":
              - img
            - button "Main navigation":
              - img
            - button "Tools":
              - img
            - button "User account menu":
              - img
          - button "Other" [expanded]:
            - img
            - img
          - list:
            - button "Call to Absolute Action":
              - img
            - button "Card":
              - img
            - button "Card with local image":
              - img
            - button "Card with remote image":
              - img
            - button "Card with stream wrapper image":
              - img
            - button "Component no meta:enum":
              - img
            - button "Druplicon":
              - img
            - button "Hero":
              - img
            - button "Section":
              - img
            - button "Test SDC Image":
              - img
            - button "XB test SDC for image gallery (>1 image)":
              - img
            - button /XB test SDC for sparkline \\(>1 integers between -\\d+ and \\d+\\)/:
              - img
            - 'button "XB test SDC for testing type: \`Drupal\\\\Core\\\\Template\\\\Attribute\` special casing"':
              - img
            - button "XB test SDC that crashes when 'crash' prop is TRUE":
              - img
            - button "XB test SDC with optional image and heading":
              - img
            - button "XB test SDC with optional image, with example":
              - img
            - button "XB test SDC with optional image, without example":
              - img
            - button "XB test SDC with props and slots":
              - img
            - button "XB test SDC with props but no slots":
              - img
            - button "XB test SDC with required image, with example":
              - img
          - button "Status" [expanded]:
            - img
            - img
          - list:
            - button "Deprecated SDC":
              - img
            - button "Experimental SDC":
              - img
          - button "System" [expanded]:
            - img
            - img
          - list:
            - button "Breadcrumbs":
              - img
            - button "Clear cache":
              - img
            - button "Messages":
              - img
            - button "Powered by Drupal":
              - img
            - button "Site branding":
              - img
          - button "User" [expanded]:
            - img
            - img
          - list:
            - button "Who's new":
              - img
         `);
  });

  test('Component hovers and clicks', async ({ page, drupal, xBEditor }) => {
    await drupal.createXbPage('Hovers', '/hovers');
    await page.goto('/hovers');
    await xBEditor.goToEditor();
    await xBEditor.addComponent({ id: 'sdc.xb_test_sdc.my-hero' });
    await xBEditor.addComponent({ id: 'sdc.xb_test_sdc.card' });
    await xBEditor.openLayersPanel();

    // Confirm no component has a hover outline.
    await expect(
      page.locator('#xbPreviewOverlay .componentOverlay[class*="hovered"]'),
    ).toHaveCount(0);
    // Hover over a component in the layers panel and verify it's outlined in the preview iframe.
    await page
      .locator('[data-testid="xb-primary-panel"] [data-xb-type="component"]')
      .locator(`text="Hero"`)
      .hover();
    const hero = page.locator(
      '.componentOverlay:has([data-xb-component-id="sdc.xb_test_sdc.my-hero"])',
    );
    const card = page.locator(
      '.componentOverlay:has([data-xb-component-id="sdc.xb_test_sdc.card"])',
    );
    await expect(hero).toHaveCSS('outline-width', '1px');
    await expect(hero).toHaveCSS('outline-style', 'solid');
    await expect(hero).not.toHaveCSS('outline-color', 'rgba(0, 0, 0, 0)');
    await expect(card).toHaveCSS('outline-style', 'dashed');
    await page
      .locator('[data-testid="xb-primary-panel"] [data-xb-type="component"]')
      .locator(`text="Card"`)
      .hover();
    await expect(card).toHaveCSS('outline-width', '1px');
    await expect(card).toHaveCSS('outline-style', 'solid');
    await expect(card).not.toHaveCSS('outline-color', 'rgba(0, 0, 0, 0)');
    await expect(hero).toHaveCSS('outline-color', 'rgba(0, 0, 0, 0)');

    // Hover over a component in the preview frame and verify it's outlined.
    await xBEditor.hoverPreviewComponent('sdc.xb_test_sdc.my-hero');
    await expect(hero).toHaveCSS('outline-width', '1px');
    await expect(hero).toHaveCSS('outline-style', 'solid');
    await expect(hero).not.toHaveCSS('outline-color', 'rgba(0, 0, 0, 0)');
    await expect(card).toHaveCSS('outline-style', 'dashed');
    await xBEditor.hoverPreviewComponent('sdc.xb_test_sdc.card');
    await expect(card).toHaveCSS('outline-width', '1px');
    await expect(card).toHaveCSS('outline-style', 'solid');
    await expect(card).not.toHaveCSS('outline-color', 'rgba(0, 0, 0, 0)');
    await expect(hero).toHaveCSS('outline-color', 'rgba(0, 0, 0, 0)');

    // Check edit props form opens when clicking the component in the preview.
    await xBEditor.clickPreviewComponent('sdc.xb_test_sdc.my-hero');
    await expect(
      page.locator(
        '[data-testid="xb-contextual-panel"] [data-drupal-selector="component-instance-form"] .field--name-subheading input',
      ),
    ).toBeVisible();
    await xBEditor.clickPreviewComponent('sdc.xb_test_sdc.card');
    await expect(
      page.locator(
        '[data-testid="xb-contextual-panel"] [data-drupal-selector="component-instance-form"] .field--name-content input',
      ),
    ).toBeVisible();
  });

  test('Can handle empty heading prop in hero component', async ({
    page,
    drupal,
    xBEditor,
  }) => {
    await drupal.createXbPage('Hero', '/hero');
    await page.goto('/hero');
    await xBEditor.goToEditor();
    await expect(page.locator('#block-stark-page-title h1')).toHaveCount(0);

    await xBEditor.addComponent({ id: 'sdc.xb_test_sdc.my-hero' });

    // Heading.
    await expect(
      (await xBEditor.getActivePreviewFrame()).locator(
        '[data-component-id="xb_test_sdc:my-hero"] h1',
      ),
    ).toContainText('There goes my hero');
    await xBEditor.editComponentProp('heading', '');
    await expect(
      (await xBEditor.getActivePreviewFrame()).locator(
        '[data-component-id="xb_test_sdc:my-hero"] h1',
      ),
    ).not.toContainText('There goes my hero');

    // Refresh the page.
    await page.reload();
    await expect(
      (await xBEditor.getActivePreviewFrame()).locator(
        '[data-component-id="xb_test_sdc:my-hero"] h1',
      ),
    ).not.toContainText('There goes my hero');
    await expect(
      (await xBEditor.getActivePreviewFrame()).locator(
        '[data-component-id="xb_test_sdc:my-hero"] .my-hero__subheading',
      ),
    ).toContainText('Watch him as he goes!');

    // CTAs.
    await expect(
      (await xBEditor.getActivePreviewFrame()).locator(
        '[data-component-id="xb_test_sdc:my-hero"] a[href="https://example.com"]',
      ),
    ).toBeVisible();
    await xBEditor.editComponentProp('cta1href', 'https://drupal.org');
    await expect(
      (await xBEditor.getActivePreviewFrame()).locator(
        '[data-component-id="xb_test_sdc:my-hero"] a.my-hero__cta--primary',
      ),
    ).toHaveAttribute('href', /drupal\.org/);
  });

  test('Can delete component with delete key', async ({
    page,
    drupal,
    xBEditor,
  }) => {
    await drupal.createXbPage('Delete', '/delete');
    await page.goto('/delete');
    await xBEditor.goToEditor();
    await xBEditor.addComponent({ id: 'sdc.xb_test_sdc.card' });
    await xBEditor.deleteComponent('sdc.xb_test_sdc.card');
  });

  test('Can add a component with slots', async ({ page, drupal, xBEditor }) => {
    await drupal.createXbPage('Slots', '/slots');
    await page.goto('/slots');
    await xBEditor.goToEditor();
    await xBEditor.addComponent({ id: 'sdc.xb_test_sdc.props-slots' });
    await xBEditor.addComponent({ id: 'sdc.xb_test_sdc.card' });
    await xBEditor.openLayersPanel();
    await expect(page.locator('[data-testid="xb-primary-panel"]'))
      .toMatchAriaSnapshot(`
      - heading "Layers" [level=4]
      - button:
        - img
      - img
      - text: Content
      - tree:
        - treeitem "Collapse component tree XB test SDC with props and slots Open contextual menu":
          - button "Collapse component tree" [expanded]:
            - img
          - img
          - text: XB test SDC with props and slots
          - button "Open contextual menu"
          - img
          - text: The Body
          - img
          - text: The Footer
          - img
          - text: The Colophon
        - treeitem "Card Open contextual menu":
          - img
          - text: Card
      `);
    await xBEditor.moveComponent('Card', 'the_footer');
    await expect(page.locator('[data-testid="xb-primary-panel"]'))
      .toMatchAriaSnapshot(`
      - heading "Layers" [level=4]
      - button:
        - img
      - img
      - text: Content
      - tree:
        - treeitem "Collapse component tree XB test SDC with props and slots Open contextual menu":
          - button "Collapse component tree" [expanded]:
            - img
          - img
          - text: XB test SDC with props and slots
          - button "Open contextual menu"
          - img
          - text: The Body
          - button "Collapse slot" [expanded]:
            - img
          - img
          - text: The Footer
          - tree:
            - treeitem "Card Open contextual menu":
              - img
              - text: Card
              - button "Open contextual menu"
          - img
          - text: The Colophon

      `);
  });

  test('The iframe loads the SDC CSS', async ({ drupal, page, xBEditor }) => {
    await drupal.setPreprocessing({ css: false });
    await drupal.createXbPage('Load SDC CSS', '/sdc-styles');
    await page.goto('/sdc-styles');
    await xBEditor.goToEditor();
    await xBEditor.addComponent({ name: 'Hero' });

    const head = await xBEditor.getIframeHead();
    expect(head).not.toBeUndefined();
    const headHTML = await page.evaluate((el) => el.innerHTML, head);
    expect(headHTML).toContain('components/my-hero/my-hero.css');

    await xBEditor.deleteComponent('sdc.xb_test_sdc.my-hero');

    const head2 = await xBEditor.getIframeHead();
    expect(head2).not.toBeUndefined();
    const head2HTML = await page.evaluate((el) => el.innerHTML, head2);
    expect(head2HTML).not.toContain('components/my-hero/my-hero.css');
  });

  test('Should be able to blur autocomplete without problems. See #3519734', async ({
    page,
    drupal,
    xBEditor,
  }) => {
    await drupal.createXbPage('Blur Autocomplete', '/blur-autocomplete');
    await page.goto('/blur-autocomplete');
    await xBEditor.goToEditor();

    await xBEditor.addComponent({ name: 'Hero' });

    // Fill in Heading and Sub-heading fields
    const headType = 'Head is different';
    const subType = 'Sub also experienced change';
    await page.getByLabel('Heading', { exact: true }).fill(headType);
    await page.getByLabel('Sub-heading', { exact: true }).fill(subType);
    await expect(page.getByLabel('Heading', { exact: true })).toHaveValue(
      headType,
    );
    await expect(page.getByLabel('Sub-heading', { exact: true })).toHaveValue(
      subType,
    );
    await expect(
      (await xBEditor.getActivePreviewFrame()).locator(
        '[data-component-id="xb_test_sdc:my-hero"] h1',
      ),
    ).toContainText(headType);

    await expect(
      (await xBEditor.getActivePreviewFrame()).locator(
        '[data-component-id="xb_test_sdc:my-hero"] p',
      ),
    ).toContainText(subType);

    // Type in the autocomplete field, then blur by clicking another field
    await page.getByLabel('CTA 1 link', { exact: true }).fill('com');
    // Click another field to blur the autocomplete field, which prior to the fix in #3519734
    // would revert the preview to earlier values.
    await page.getByLabel('CTA 2 text', { exact: true }).click();

    // To make this a test that will fail without the fix present, but pass with
    // the fix in place, we need a fixed value wait here so that there's enough time
    // for the problem to appear in the preview. The correct contents will be asserted after this.
    // Wait 1000ms
    await page.waitForTimeout(1000);

    // Assert the preview still has the correct values
    await expect(
      (await xBEditor.getActivePreviewFrame()).locator(
        '[data-component-id="xb_test_sdc:my-hero"] h1',
      ),
    ).toContainText(headType);

    await expect(
      (await xBEditor.getActivePreviewFrame()).locator(
        '[data-component-id="xb_test_sdc:my-hero"] p',
      ),
    ).toContainText(subType);
    await expect(page.getByLabel('Heading', { exact: true })).toHaveValue(
      headType,
    );
    await expect(page.getByLabel('Sub-heading', { exact: true })).toHaveValue(
      subType,
    );
  });
});
