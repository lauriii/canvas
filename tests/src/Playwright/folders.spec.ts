import { expect } from '@playwright/test';
import { test } from './fixtures/DrupalSite';
import { Drupal } from './objects/Drupal';

/**
 * Tests folder management in Experience Builder.
 */
test.describe('Folder Management', () => {
  test.beforeAll(
    'Setup test site with Experience Builder',
    async ({ browser, drupalSite }) => {
      const page = await browser.newPage();
      const drupal: Drupal = new Drupal({ page, drupalSite });
      await drupal.drush('cr');

      await drupal.installModules(['experience_builder', 'xb_test_folders']);

      // @todo remove the cache clear once https://www.drupal.org/project/drupal/issues/3534825
      // is fixed.
      await drupal.drush('cr');
      await page.close();
    },
  );

  test('Folder display and creation', async ({ page, drupal, xBEditor }) => {
    await drupal.loginAsAdmin();
    await drupal.createXbPage('Test Page', '/test-page');
    await page.goto('/test-page');
    await xBEditor.goToEditor();

    await page.click('[aria-label="Manage library"]');
    await expect(
      page.locator('[data-testid="add-new-folder-button"]'),
    ).toBeVisible();

    // We begin on the Components tab.
    await expect(
      page.locator(
        '[data-testid="xb-manage-library-components-tab-select"][aria-selected="true"]',
      ),
    ).toBeVisible();
    await expect(
      page.locator('[data-testid="xb-manage-library-components-tab-content"]'),
    ).toBeVisible();

    // Confirm the Components tab contents.
    await expect(
      page.locator('[data-testid="xb-manage-library-components-tab-content"]'),
    ).toMatchAriaSnapshot(`
      - tabpanel "Components":
        - button "Add new folder":
          - img
        - button "Atom/Media 1" [expanded]:
          - img
          - img
        - list:
          - listitem:
            - img
            - text: Video
        - button "Atom/Tabs 2" [expanded]:
          - img
          - img
        - list:
          - listitem:
            - img
            - text: Shoe Tab
          - listitem:
            - img
            - text: Shoe Tab Panel
        - button "Atom/Text 2" [expanded]:
          - img
          - img
        - list:
          - listitem:
            - img
            - text: Heading
          - listitem:
            - img
            - text: Shoe Badge
        - button "Container 3" [expanded]:
          - img
          - img
        - list:
          - listitem:
            - img
            - text: Grid Container
          - listitem:
            - img
            - text: One Column
          - listitem:
            - img
            - text: Two Column
        - button "Container/Special 1" [expanded]:
          - img
          - img
        - list:
          - listitem:
            - img
            - text: Shoe Tab Group
        - button "core 3" [expanded]:
          - img
          - img
        - list:
          - listitem:
            - img
            - text: Page title
          - listitem:
            - img
            - text: Primary admin actions
          - listitem:
            - img
            - text: Tabs
        - button "Empty Components 0" [expanded]:
          - img
          - img
        - list
        - button "Forms 1" [expanded]:
          - img
          - img
        - list:
          - listitem:
            - img
            - text: User login
        - button "Lists (Views) 2" [expanded]:
          - img
          - img
        - list:
          - listitem:
            - img
            - text: Recent content
          - listitem:
            - img
            - text: Who's online
        - button "Menus 5" [expanded]:
          - img
          - img
        - list:
          - listitem:
            - img
            - text: Administration
          - listitem:
            - img
            - text: Footer
          - listitem:
            - img
            - text: Main navigation
          - listitem:
            - img
            - text: Tools
          - listitem:
            - img
            - text: User account menu
        - button /Other \\d+/ [expanded]:
          - img
          - img
        - list:
          - listitem:
            - img
            - text: Call to Absolute Action
          - listitem:
            - img
            - text: Card
          - listitem:
            - img
            - text: Card with local image
          - listitem:
            - img
            - text: Card with remote image
          - listitem:
            - img
            - text: Card with stream wrapper image
          - listitem:
            - img
            - text: Component no meta:enum
          - listitem:
            - img
            - text: Druplicon
          - listitem:
            - img
            - text: Hero
          - listitem:
            - img
            - text: Section
          - listitem:
            - img
            - text: Test SDC Image
          - listitem:
            - img
            - text: XB test SDC for image gallery (>1 image)
          - listitem:
            - img
            - text: /XB test SDC for sparkline \\(>1 integers between -\\d+ and \\d+\\)/
          - listitem:
            - img
            - text: "XB test SDC for testing type: \`Drupal\\\\Core\\\\Template\\\\Attribute\` special casing"
          - listitem:
            - img
            - text: XB test SDC that crashes when 'crash' prop is TRUE
          - listitem:
            - img
            - text: XB test SDC with optional image and heading
          - listitem:
            - img
            - text: XB test SDC with optional image, with example
          - listitem:
            - img
            - text: XB test SDC with optional image, without example
          - listitem:
            - img
            - text: XB test SDC with props and slots
          - listitem:
            - img
            - text: XB test SDC with props but no slots
          - listitem:
            - img
            - text: XB test SDC with required image, with example
        - button "Status 2" [expanded]:
          - img
          - img
        - list:
          - listitem:
            - img
            - text: Deprecated SDC
          - listitem:
            - img
            - text: Experimental SDC
        - button "System 5" [expanded]:
          - img
          - img
        - list:
          - listitem:
            - img
            - text: Breadcrumbs
          - listitem:
            - img
            - text: Clear cache
          - listitem:
            - img
            - text: Messages
          - listitem:
            - img
            - text: Powered by Drupal
          - listitem:
            - img
            - text: Site branding
        - button "User 1" [expanded]:
          - img
          - img
        - list:
          - listitem:
            - img
            - text: Who's new
    `);
    await page
      .locator('[data-testid="xb-manage-library-patterns-tab-select"]')
      .click();

    // Move to the Patterns tab.
    await expect(
      page.locator(
        '[data-testid="xb-manage-library-patterns-tab-select"][aria-selected="true"]',
      ),
    ).toBeVisible();
    await expect(
      page.locator('[data-testid="xb-manage-library-patterns-tab-content"]'),
    ).toBeVisible();

    // Confirm the Patterns tab contents.
    await expect(
      page.locator('[data-testid="xb-manage-library-patterns-tab-content"]'),
    ).toMatchAriaSnapshot(`
      - tabpanel "Patterns":
        - button "Add new folder":
          - img
        - button "Animal Pats 2" [expanded]:
          - img
          - img
        - list:
          - listitem:
            - img
            - text: Cat pattern
          - listitem:
            - img
            - text: Dog pattern
        - button "Color Patterns 2" [expanded]:
          - img
          - img
        - list:
          - listitem:
            - img
            - text: Red pattern
          - listitem:
            - img
            - text: Yellow pattern
        - button "Empty Patterns 0" [expanded]:
          - img
          - img
        - list
        - list:
          - listitem:
            - img
            - text: Grid Container using pattern
    `);

    // Move to the Code tab.
    await page
      .locator('[data-testid="xb-manage-library-code-tab-select"]')
      .click();
    await expect(
      page.locator(
        '[data-testid="xb-manage-library-code-tab-select"][aria-selected="true"]',
      ),
    ).toBeVisible();
    await expect(
      page.locator('[data-testid="xb-manage-library-code-tab-content"]'),
    ).toBeVisible();

    // Confirm the Code tab contents.
    await expect(
      page.locator('[data-testid="xb-manage-library-code-tab-content"]'),
    ).toMatchAriaSnapshot(`
        - tabpanel "Code":
          - button "Add new folder":
            - img
          - button "Active Users of Using 2" [expanded]:
            - img
            - img
          - img
          - text: Using drupalSettings getSiteData (Internal)
          - button "Open contextual menu"
          - img
          - text: Component using imports (Internal)
          - button "Open contextual menu"
          - button "Empty Code 0" [expanded]:
            - img
            - img
          - button "Proclaimers of With 2" [expanded]:
            - img
            - img
          - img
          - text: My Code Component Link (Internal)
          - button "Open contextual menu"
          - img
          - text: With no props (Internal)
          - button "Open contextual menu"
          - img
          - text: Captioned video (Internal)
          - button "Open contextual menu"
          - img
          - text: Vanilla Image (Internal)
          - button "Open contextual menu"
          - img
          - text: With enums (Internal)
          - button "Open contextual menu"
          - img
          - text: With props (Internal)
          - button "Open contextual menu"
      `);

    // Helper to add folders and confirm they appear.
    const testAddFolder = async (
      foldersToAdd: string[],
      allExpectedFolders: string[],
    ) => {
      for (const folderName of foldersToAdd) {
        await expect(
          page.locator('[data-testid="xb-manage-library-add-folder-content"]'),
        ).not.toBeAttached();
        await page.click('[data-testid="add-new-folder-button"]');
        page
          .locator('[data-testid="xb-manage-library-add-folder-content"]')
          .waitFor({ state: 'visible' });
        await expect(
          page.locator('#add-new-folder-in-tab-form'),
        ).toBeAttached();
        await expect(
          page.locator(
            '[data-testid="xb-manage-library-new-folder-name-submit"]',
          ),
        ).not.toBeEnabled();
        await page
          .locator('[data-testid="xb-manage-library-new-folder-name"]')
          .fill(folderName);
        await expect(
          page.locator(
            '[data-testid="xb-manage-library-new-folder-name-submit"]',
          ),
        ).toBeEnabled({ timeout: 5000 });
        await page.click(
          '[data-testid="xb-manage-library-new-folder-name-submit"]',
        );
        await page
          .locator(`[data-xb-folder-name="${folderName}"]`)
          .waitFor({ state: 'attached' });
      }

      const folderElements = await page.locator('[data-xb-folder-name]').all();
      const actualFolderNames = await Promise.all(
        folderElements.map(async (element) => {
          return await element.getAttribute('data-xb-folder-name');
        }),
      );
      expect(actualFolderNames).toEqual(allExpectedFolders);
    };

    // Test adding a folder to the Code tab.
    await testAddFolder(
      ['Awesome New Folder', 'Is a Code Folder', 'Very Nice New Folder'],
      [
        'Active Users of Using',
        'Awesome New Folder',
        'Empty Code',
        'Is a Code Folder',
        'Proclaimers of With',
        'Very Nice New Folder',
      ],
    );

    // Test adding a folder to the Patterns tab.
    await page
      .locator('[data-testid="xb-manage-library-patterns-tab-select"]')
      .click();
    await expect(
      page.locator(
        '[data-testid="xb-manage-library-patterns-tab-select"][aria-selected="true"]',
      ),
    ).toBeVisible();
    await testAddFolder(
      ['Awesome New Folder', 'Is a Pattern Folder', 'Very Nice New Folder'],
      [
        'Animal Pats',
        'Awesome New Folder',
        'Color Patterns',
        'Empty Patterns',
        'Is a Pattern Folder',
        'Very Nice New Folder',
      ],
    );

    // Test adding a folder to the Components tab.

    await page
      .locator('[data-testid="xb-manage-library-components-tab-select"]')
      .click();
    await expect(
      page.locator(
        '[data-testid="xb-manage-library-components-tab-select"][aria-selected="true"]',
      ),
    ).toBeVisible();
    await testAddFolder(
      ['Awesome New Folder', 'Is a Component Folder', 'Very Nice New Folder'],
      [
        // @see \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\JsComponent::createConfigEntity()
        // @todo Remove next line in https://www.drupal.org/project/experience_builder/issues/3541364
        '@todo',
        'Atom/Media',
        'Atom/Tabs',
        'Atom/Text',
        'Awesome New Folder',
        'Container',
        'Container/Special',
        'core',
        'Empty Components',
        'Forms',
        'Is a Component Folder',
        'Lists (Views)',
        'Menus',
        'Other',
        'Status',
        'System',
        'User',
        'Very Nice New Folder',
      ],
    );
  });
});
