import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

test.use({
  enableTestExtensions: true,
});

// The Content (CMS) panel surfaces templated entities (bundles with an active
// exposed slot) grouped by content type, so editors move between them without
// leaving Canvas (exposed-slots decision 6). Its left-menu icon is always
// shown; the panel itself shows an empty state until a bundle has an active
// exposed slot.
test.describe('Content (CMS) panel', () => {
  test('icon is always available and the panel shows an empty state without templated content', async ({
    drupal,
    canvas,
    page,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.createCanvas();

    // The Content icon is present in the left menu regardless of whether any
    // bundle has an active exposed slot (a sibling of Pages, not gated).
    const contentButton = page
      .getByTestId('canvas-side-menu')
      .getByLabel('Content');
    await expect(contentButton).toBeVisible();

    await canvas.openContentPanel();

    // With no templated content the panel offers its search field and an empty
    // state, not any content-type groups.
    await expect(
      page.getByRole('textbox', { name: 'Search content' }),
    ).toBeVisible();
    await expect(page.getByText('No content found')).toBeVisible();
  });

  // The grouped-content flow, on the article_exposed_slots fixture recipe: a
  // templated Article bundle (active exposed slot) with three seeded nodes.
  test('groups templated content with search, collapse, and row navigation', async ({
    drupal,
    canvas,
    page,
  }) => {
    await drupal.loginAsAdmin();
    await drupal.applyRecipe(
      `modules/contrib/canvas/tests/fixtures/recipes/article_exposed_slots`,
    );
    await drupal.addPermissions({
      role: 'editor',
      permissions: ['edit any article content'],
    });
    await drupal.logout();

    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.createCanvas();
    await canvas.openContentPanel();

    // The templated Article bundle renders as a content-type group holding
    // the seeded entities.
    const group = page.getByTestId('canvas-templated-content-node');
    await expect(group).toBeVisible();
    await expect(group.getByText('Templated alpha')).toBeVisible();
    await expect(group.getByText('Templated beta')).toBeVisible();
    await expect(group.getByText('Another gamma')).toBeVisible();

    // The folder header collapses and expands its rows.
    const folderTrigger = group.getByRole('button', { name: 'Article' });
    await folderTrigger.click();
    await expect(group.getByText('Templated alpha')).toBeHidden();
    await folderTrigger.click();
    await expect(group.getByText('Templated alpha')).toBeVisible();

    // The shared search field filters every group's rows.
    const search = page.getByRole('textbox', { name: 'Search content' });
    await search.fill('alpha');
    await expect(group.getByText('Templated alpha')).toBeVisible();
    await expect(group.getByText('Templated beta')).toHaveCount(0);
    await search.clear();
    await expect(group.getByText('Templated beta')).toBeVisible();

    // Selecting a row opens the entity in the per-content editor.
    await group.getByText('Templated alpha').click();
    await expect(page).toHaveURL(/\/editor\/node\/\d+/);
  });

  // TODO follow-up (article_exposed_slots fixture): per-content contextual panel, phase 1 of
  // the Content/Page data split (exposed-slots decision 10). In
  // `/editor/node/{id}` assert: the tab bar shows Page data (leftmost, the
  // default) and Content (`canvas-contextual-panel--content`); the Content
  // tab holds only a link out to Drupal's edit form
  // (`canvas-content-tab-edit-form-link`, href `/node/{id}/edit`); the Page
  // data tab renders the trimmed form: title, then URL alias as a plain
  // field (no "URL path settings" details), menu settings, authoring
  // information, and NO body/content fields and NO read-only meta block
  // (Published / Last saved / Author). Server-side coverage already exists in
  // EntityFormControllerTest::testPerContentFormTrimsToPageData(); this spec
  // covers the tab UI. Do not write this as a Cypress test.
});
