import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

/**
 * Tests the List element: place a List, configure its content source,
 * filters, sorting, layout, and pagination through the server-rendered
 * settings form, and verify both the editor preview and the published output.
 *
 * The settings form remounts whenever a structural setting (a select) changes:
 * each structural change below is followed by a wait for a control that only
 * the rebuilt form contains before the next interaction.
 */
test.describe('List element', () => {
  // Site install, content creation, the many settings-form rebuilds, and the
  // published-page checks together exceed the default timeout. test.slow()
  // would not help here: it only takes effect once the test body runs, after
  // the per-test site install fixture has already spent its budget. The
  // ceiling is generous because the per-test site install is IO-bound and
  // can take minutes on bind-mounted development file systems.
  test.describe.configure({ timeout: 600_000 });

  test('Place, configure, and publish a List element', async ({
    page,
    drupal,
    canvas,
  }) => {
    // Create the Article content type and three articles. The two titles
    // containing "ListSpec" match the filter added later, the third does not.
    // "Apple…" is created before "Zebra…" so the default newest-first order
    // differs from the alphabetical order asserted after sorting by title.
    await drupal.loginAsAdmin();
    await page.goto('/admin/structure/types/add');
    await page.getByRole('textbox', { name: 'name' }).fill('Article');
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(
      page.getByRole('contentinfo', { name: 'Status message' }),
    ).toContainText('The content type Article has been added.');
    for (const title of [
      'Apple ListSpec',
      'Zebra ListSpec',
      'Mango Standalone',
    ]) {
      await page.goto('/node/add/article');
      await page.getByLabel('Title').fill(title);
      await page.getByRole('button', { name: 'Save' }).click();
      await expect(
        page.getByRole('contentinfo', { name: 'Status message' }),
      ).toContainText(`Article ${title} has been created.`);
    }
    // Component discovery ran when Canvas was installed, before any content
    // type existed, so the List component does not exist yet. Discovery
    // re-runs on rebuild.
    // @see \Drupal\canvas\Hook\ComponentSourceHooks::rebuild()
    // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\ListComponentDiscovery::checkRequirements()
    await drupal.clearCache();
    await drupal.logout();

    await drupal.login({ username: 'editor', password: 'editor' });
    const canvasPage = await canvas.createCanvas();
    await canvas.openLibraryPanel();
    await canvas.addComponent({ id: 'list.list' });

    const form = page.locator(
      '[data-testid="canvas-contextual-panel"] [data-drupal-selector="component-instance-form"]',
    );

    // A form remount resets every collapsible section to its server-side
    // default state, so (re)open a section right before interacting with it.
    const openSection = async (name: string) => {
      const trigger = form.getByRole('button', { name, exact: true });
      await expect(trigger).toBeVisible();
      // Clicking toggles the section, so only click when it is closed.
      // eslint-disable-next-line playwright/no-conditional-in-test
      if ((await trigger.getAttribute('data-state')) !== 'open') {
        await trigger.click();
      }
      await expect(trigger).toHaveAttribute('data-state', 'open');
    };

    // The freshly placed List defaults to the bundle of the most recently
    // created content item and previews all three articles as linked titles,
    // newest first.
    await expect(form.locator('select[name$="[source][bundle]"]')).toHaveValue(
      'article',
    );
    await canvas.testInPreviewFrame(
      '.canvas-list-element .canvas-list__item',
      async (items) => {
        await expect(items).toHaveCount(3);
        await expect(items).toContainText([
          'Mango Standalone',
          'Zebra ListSpec',
          'Apple ListSpec',
        ]);
        await expect(
          items.locator('a.canvas-list__item-title-link').first(),
        ).toBeVisible();
      },
    );

    // Add a filter: Title contains "ListSpec". Selecting a field in the
    // trailing "Add a condition on" row is a structural change; the rebuilt
    // form shows the condition's operator select, defaulting to "Is set".
    await openSection('Filters');
    await form
      .locator('select[name$="[filters][conditions][0][field]"]')
      .selectOption('title');
    const conditionOperator = form.locator(
      'select[name$="[filters][conditions][0][operator]"]',
    );
    await expect(conditionOperator).toBeVisible();
    await expect(conditionOperator).toHaveValue('is_set');

    // Switching the operator to "Contains" is structural too: the rebuilt
    // form gains a value input.
    await conditionOperator.selectOption('contains');
    const conditionValue = form.locator(
      'input[name$="[filters][conditions][0][value]"]',
    );
    await expect(conditionValue).toBeVisible();
    await conditionValue.fill('ListSpec');
    await conditionValue.press('Tab');
    await canvas.testInPreviewFrame('.canvas-list__item', async (items) => {
      await expect(items).toHaveCount(2);
      await expect(items).toContainText(['Zebra ListSpec', 'Apple ListSpec']);
    });

    // Replace the default "Authored on" (newest first) sort with Title A→Z.
    await openSection('Sorting');
    const firstSortField = form.locator('select[name$="[sorts][0][field]"]');
    await expect(firstSortField).toHaveValue('created');
    await firstSortField.selectOption('');
    // After the rebuild only the trailing "Add a sort on" row remains.
    await expect(form.locator('select[name$="[sorts][1][field]"]')).toHaveCount(
      0,
    );
    await expect(firstSortField).toHaveValue('');
    await openSection('Sorting');
    await firstSortField.selectOption('title');
    const sortDirection = form.locator('select[name$="[sorts][0][direction]"]');
    await expect(sortDirection).toHaveCount(1);
    await expect(sortDirection).toHaveValue('asc');
    await canvas.testInPreviewFrame('.canvas-list__item', async (items) => {
      await expect(items).toContainText(['Apple ListSpec', 'Zebra ListSpec']);
    });

    // Arrange the items as a grid.
    await openSection('Layout');
    await form.locator('select[name$="[layout][mode]"]').selectOption('grid');
    // Grid replaces the stack-only controls with "Maximum items per row".
    await expect(
      form.locator('input[name$="[layout][max_per_row]"]'),
    ).toHaveCount(1);
    await canvas.testInPreviewFrame(
      '.canvas-list-element .canvas-list',
      async (list) => {
        await expect(list).toHaveClass(/canvas-list--grid/);
      },
    );

    // Paginate with a "Load more" button, one item per page. The editor
    // preview shows the first page window and the control.
    await openSection('Pagination');
    await form
      .locator('select[name$="[pagination][mode]"]')
      .selectOption('load_more');
    const perPageInput = form.locator('input[name$="[pagination][page_size]"]');
    await expect(perPageInput).toHaveCount(1);
    await openSection('Pagination');
    await perPageInput.fill('1');
    await perPageInput.press('Tab');
    await canvas.testInPreviewFrame('.canvas-list-element', async (element) => {
      await expect(element.locator('.canvas-list__item')).toHaveCount(1);
      await expect(
        element.locator('.canvas-list-element__load-more'),
      ).toBeVisible();
    });

    // Publish and verify the live page: the grid class, the first page of
    // filtered and sorted items, and working "Load more" pagination.
    await canvas.publishAllChanges();
    await page.goto(`/page/${canvasPage.entity_id}`);
    const published = page.locator('.canvas-list-element');
    await expect(published.locator('.canvas-list')).toHaveClass(
      /canvas-list--grid/,
    );
    const publishedItems = published.locator('.canvas-list__item');
    await expect(publishedItems).toHaveCount(1);
    await expect(
      publishedItems.first().locator('a.canvas-list__item-title-link'),
    ).toHaveText('Apple ListSpec');

    const loadMore = published.locator('.canvas-list-element__load-more');
    await expect(loadMore).toBeVisible();
    await loadMore.click();
    await expect(publishedItems).toHaveCount(2);
    await expect(
      publishedItems.nth(1).locator('a.canvas-list__item-title-link'),
    ).toHaveText('Zebra ListSpec');
    // Every filter match is shown, so the control removes itself.
    await expect(loadMore).toHaveCount(0);
  });
});
