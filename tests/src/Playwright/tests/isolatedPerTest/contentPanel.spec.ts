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

  // TODO follow-up: assert the grouped-content behavior (content-type folders,
  // search filtering, collapse/expand, and opening a row in the per-content
  // editor). It needs a bundle with an active exposed slot plus entities, for
  // which there is no exposed-slot Playwright fixture yet. Add a recipe that
  // seeds a templated Article bundle with an active exposed slot and a few
  // nodes, then assert that canvas.openContentPanel() shows a
  // `canvas-templated-content-node` group, the folder collapses/expands, the
  // "Search Content" field filters rows, and clicking a row navigates to
  // `/editor/node/{id}`.
  //
  // TODO follow-up (same fixture): per-content contextual panel, phase 1 of
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
