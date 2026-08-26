import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

import type { Locator } from '@playwright/test';

const COMPONENT_CODE = [
  'const Component = ({ author }) => (',
  '  <div>Author: {author?.name}</div>',
  ');',
  'export default Component;',
].join('\n');

async function openDialog(propForm: Locator): Promise<Locator> {
  await propForm
    .getByRole('button', { name: /^(Add type|Add field|Edit)$/ })
    .click();
  const dialog = propForm
    .page()
    .getByRole('dialog', { name: 'Content Relationship' });
  await expect(dialog).toBeVisible();
  return dialog;
}

async function selectFromDropdown(
  dialog: Locator,
  opener: string,
  option: string,
) {
  await dialog.getByText(opener, { exact: true }).click();
  await dialog
    .page()
    .locator('body > div > div.rt-SelectContent')
    .getByRole('option', { name: option, exact: true })
    .click();
}

async function chooseTarget(
  propForm: Locator,
  entityType: string,
  bundle?: string,
) {
  const dialog = await openDialog(propForm);
  const continueButton = dialog.getByRole('button', {
    name: 'Continue',
    exact: true,
  });
  await selectFromDropdown(dialog, 'Select entity type', entityType);
  await expect(
    dialog.getByText('Select entity type', { exact: true }),
  ).toBeHidden();
  if (bundle) {
    // A single-bundle entity type auto-selects its only bundle; a multi-bundle
    // type leaves the "Select bundle" placeholder for the user to choose. Poll
    // until one of those settled states is reached, then only pick when needed.
    const bundlePicker = dialog.getByText('Select bundle', { exact: true });
    await expect
      .poll(
        async () =>
          (await bundlePicker.isVisible()) ||
          (await continueButton.isEnabled()),
      )
      .toBe(true);
    if (await bundlePicker.isVisible()) {
      await selectFromDropdown(dialog, 'Select bundle', bundle);
    }
  }
  await expect(continueButton).toBeEnabled();
  await continueButton.click();
  await expect(
    dialog.getByRole('button', { name: 'Save', exact: true }),
  ).toBeVisible();
  const saveButton = dialog.getByRole('button', { name: 'Save', exact: true });
  await expect(saveButton).toBeDisabled();
  await fieldCheckbox(dialog, 'Title').click();
  await expect(saveButton).toBeEnabled();
  await saveButton.click();
  await expect(dialog).toBeHidden();
}

function fieldCheckbox(dialog: Locator, fieldLabel: string): Locator {
  return dialog.getByRole('checkbox', { name: fieldLabel, exact: true });
}

// Scopes a locator to a single per-bundle browse section inside a multi-target
// reference field. The root field tree and each bundle expose identically named
// checkboxes (e.g. "Title"), so selectors must be anchored to the exact bundle
// section. A section owns its own expand/collapse control (name flips between
// "Expand <bundle>" and "Collapse <bundle>") and — once expanded — a checkbox,
// while excluding the sibling bundle's control. Those three filters pin the
// BundleFieldsSection wrapper without ever climbing into the sibling section
// (which a shared-ancestor selector does when a section is transiently
// collapsed). The bundle must be expanded before scoping (the checkbox filter).
function bundleSection(
  dialog: Locator,
  bundleLabel: string,
  siblingLabel: string,
): Locator {
  // `has`/`hasNot` inner locators are re-rooted under each candidate, so they
  // must be page-anchored (not dialog-anchored, which would demand a nested
  // dialog inside the candidate and match nothing).
  const page = dialog.page();
  return dialog
    .locator('div')
    .filter({
      has: page.getByRole('button', {
        name: new RegExp(`^(Expand|Collapse) ${bundleLabel}$`),
      }),
    })
    .filter({
      hasNot: page.getByRole('button', {
        name: new RegExp(`^(Expand|Collapse) ${siblingLabel}$`),
      }),
    })
    .filter({ has: page.getByRole('checkbox') });
}

test.describe('Content entity reference props', () => {
  // The dialog only lists entity types the user can view, so grant the editor
  // the permissions needed to see User and Content.
  test.beforeEach(async ({ drupal }) => {
    await drupal.loginAsAdmin();
    // The user_picture field and node.body storage the module depends on come
    // from a recipe, not the module's config/install, so it installs on any
    // profile. Apply it before enabling the module.
    await drupal.applyRecipe(
      `modules/contrib/canvas/tests/fixtures/recipes/canvas_test_code_components_content_entity_ref_fields`,
    );
    await drupal.installModules([
      'canvas_test_code_components_content_entity_ref',
    ]);
    await drupal.addPermissions({
      role: 'editor',
      permissions: ['access content', 'access user profiles'],
    });
    await drupal.logout();
  });

  test('Adds and edits a content entity reference prop in the code editor', async ({
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    const canvasPage = await canvas.createCanvas();
    await canvas.openCanvas(canvasPage);
    await canvas.createCodeComponent('CerNode', COMPONENT_CODE);

    const propForm = await canvas.addCodeComponentProp(
      'author',
      'Content entity reference',
    );

    await test.step('Cancel discards an unsaved type selection', async () => {
      const dialog = await openDialog(propForm);
      const continueButton = dialog.getByRole('button', {
        name: 'Continue',
        exact: true,
      });
      await expect(continueButton).toBeVisible();
      await expect(continueButton).toBeDisabled();
      await selectFromDropdown(dialog, 'Select entity type', 'User');
      await expect(continueButton).toBeEnabled();
      await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
      await expect(dialog).toBeHidden();
      await expect(
        propForm.getByRole('button', { name: 'Add type', exact: true }),
      ).toBeVisible();
    });

    await test.step('Pick Content / News Item as the target', async () => {
      await chooseTarget(propForm, 'Content', 'News Item');
    });

    await test.step('Reset and cancel discards in-dialog changes', async () => {
      const dialog = await openDialog(propForm);
      await expect(
        dialog.getByRole('button', { name: 'Save', exact: true }),
      ).toBeVisible();

      const title = fieldCheckbox(dialog, 'Title');
      await expect(title).toBeVisible();
      await expect(
        dialog.getByRole('button', { name: 'Expand Title', exact: true }),
      ).toHaveCount(0);
      await title.click();
      await expect(title).toHaveAttribute('data-state', 'unchecked');

      await dialog
        .getByRole('button', { name: 'Reset type and bundle' })
        .click();
      await expect(
        dialog.getByText('Select entity type', { exact: true }),
      ).toBeVisible();
      await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
      await expect(dialog).toBeHidden();
      await expect(propForm).toContainText('News Item');
    });

    await test.step('Select a field property and a nested field property, then save', async () => {
      const dialog = await openDialog(propForm);
      await expect(fieldCheckbox(dialog, 'Title')).toHaveAttribute(
        'data-state',
        'checked',
      );
      await fieldCheckbox(dialog, 'Title').click();
      await expect(fieldCheckbox(dialog, 'Title')).toHaveAttribute(
        'data-state',
        'unchecked',
      );

      const body = fieldCheckbox(dialog, 'Body');
      await expect(body).toBeVisible();
      await dialog
        .getByRole('button', { name: 'Expand Body', exact: true })
        .click();
      const bodyProcessedText = fieldCheckbox(dialog, 'Body → Processed text');
      await expect(bodyProcessedText).toBeVisible();
      await bodyProcessedText.click();
      await expect(bodyProcessedText).toHaveAttribute('data-state', 'checked');
      await expect(
        fieldCheckbox(dialog, 'Body → Processed summary'),
      ).toHaveAttribute('data-state', 'unchecked');
      await expect(body).toHaveAttribute('data-state', 'indeterminate');

      await dialog
        .getByRole('button', { name: 'Expand Authored by', exact: true })
        .click();
      await dialog
        .getByRole('button', { name: 'Expand User', exact: true })
        .click();
      const nestedPicture = fieldCheckbox(dialog, 'Picture');
      await expect(nestedPicture).toBeVisible();
      await dialog
        .getByRole('button', { name: 'Expand Picture', exact: true })
        .click();
      const nestedPictureAlt = fieldCheckbox(
        dialog,
        'Authored by → Picture → Alternative text',
      );
      await expect(nestedPictureAlt).toBeVisible();
      await nestedPictureAlt.click();
      await expect(nestedPictureAlt).toHaveAttribute('data-state', 'checked');
      await expect(
        fieldCheckbox(dialog, 'Authored by → Picture → Width'),
      ).toHaveAttribute('data-state', 'unchecked');
      await expect(nestedPicture).toHaveAttribute(
        'data-state',
        'indeterminate',
      );

      await dialog.getByRole('button', { name: 'Save', exact: true }).click();
      await expect(dialog).toBeHidden();
    });

    await test.step('Reopening restores the saved selections', async () => {
      const reopened = await openDialog(propForm);
      await expect(fieldCheckbox(reopened, 'Body')).toHaveAttribute(
        'data-state',
        'indeterminate',
      );
      await reopened
        .getByRole('button', { name: 'Expand Body', exact: true })
        .click();
      await expect(
        fieldCheckbox(reopened, 'Body → Processed text'),
      ).toHaveAttribute('data-state', 'checked');
      await expect(
        fieldCheckbox(reopened, 'Body → Processed summary'),
      ).toHaveAttribute('data-state', 'unchecked');

      await reopened
        .getByRole('button', { name: 'Expand Authored by', exact: true })
        .click();
      await reopened
        .getByRole('button', { name: 'Expand User', exact: true })
        .click();
      await expect(fieldCheckbox(reopened, 'Picture')).toHaveAttribute(
        'data-state',
        'indeterminate',
      );
      await reopened
        .getByRole('button', { name: 'Expand Picture', exact: true })
        .click();
      await expect(
        fieldCheckbox(reopened, 'Authored by → Picture → Alternative text'),
      ).toHaveAttribute('data-state', 'checked');
      await expect(
        fieldCheckbox(reopened, 'Authored by → Picture → Width'),
      ).toHaveAttribute('data-state', 'unchecked');
    });
  });

  test('Browses a multi-target-bundle reference field and coalesces per-bundle picks', async ({
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    const canvasPage = await canvas.createCanvas();
    await canvas.openCanvas(canvasPage);
    await canvas.createCodeComponent('CerMultiBundle', COMPONENT_CODE);

    const propForm = await canvas.addCodeComponentProp(
      'related',
      'Content entity reference',
    );

    // "Related content" (field_related) targets both News Item and Blog post, so
    // browsing it must offer an independent per-bundle section for each bundle.
    await test.step('Expanding the reference reveals a section per target bundle', async () => {
      const dialog = await openDialog(propForm);
      const continueButton = dialog.getByRole('button', {
        name: 'Continue',
        exact: true,
      });
      await selectFromDropdown(dialog, 'Select entity type', 'Content');
      await selectFromDropdown(dialog, 'Select bundle', 'News Item');
      await expect(continueButton).toBeEnabled();
      await continueButton.click();

      const saveButton = dialog.getByRole('button', {
        name: 'Save',
        exact: true,
      });
      await expect(saveButton).toBeVisible();

      // The multi-bundle reference row exposes one "Expand" affordance; expanding
      // it must reveal one browse section per target bundle.
      await dialog
        .getByRole('button', { name: 'Expand Related content', exact: true })
        .click();
      const newsExpand = dialog.getByRole('button', {
        name: 'Expand News Item',
        exact: true,
      });
      const blogExpand = dialog.getByRole('button', {
        name: 'Expand Blog post',
        exact: true,
      });
      await expect(newsExpand).toBeVisible();
      await expect(blogExpand).toBeVisible();

      // Each bundle section expands independently: expanding News Item must not
      // expand Blog post. The News Item control flips to "Collapse" while the
      // Blog post control stays "Expand".
      await newsExpand.click();
      await expect(
        dialog.getByRole('button', { name: 'Collapse News Item', exact: true }),
      ).toBeVisible();
      await expect(blogExpand).toBeVisible();

      // The News Item section exposes both the base field "Title" and the
      // news_item-only "Body" field.
      const newsSection = bundleSection(dialog, 'News Item', 'Blog post');
      await expect(
        newsSection.getByRole('checkbox', { name: 'Title', exact: true }),
      ).toBeVisible();
      await expect(
        newsSection.getByRole('checkbox', { name: 'Body', exact: true }),
      ).toBeVisible();

      await blogExpand.click();
      await expect(
        dialog.getByRole('button', { name: 'Collapse Blog post', exact: true }),
      ).toBeVisible();

      // The two bundles expose genuinely different field sets: Blog post has
      // "Title" but not the news_item-only "Body".
      const blogSection = bundleSection(dialog, 'Blog post', 'News Item');
      await expect(
        blogSection.getByRole('checkbox', { name: 'Title', exact: true }),
      ).toBeVisible();
      await expect(
        blogSection.getByRole('checkbox', { name: 'Body', exact: true }),
      ).toHaveCount(0);

      // Each bundle's "Title" is an independent, bundle-specific pick: checking
      // it under News Item leaves Blog post's "Title" unchecked, and vice versa.
      const newsTitle = newsSection.getByRole('checkbox', {
        name: 'Title',
        exact: true,
      });
      const blogTitle = blogSection.getByRole('checkbox', {
        name: 'Title',
        exact: true,
      });
      await newsTitle.click();
      await expect(newsTitle).toHaveAttribute('data-state', 'checked');
      await expect(blogTitle).toHaveAttribute('data-state', 'unchecked');
      await blogTitle.click();
      await expect(blogTitle).toHaveAttribute('data-state', 'checked');
      await expect(newsTitle).toHaveAttribute('data-state', 'checked');

      // Two single-bundle picks are held; saving must coalesce them into one
      // bundle-specific branch expression on the stored component.
      await expect(saveButton).toBeEnabled();
      await saveButton.click();
      await expect(dialog).toBeHidden();
    });

    await test.step('Reopening restores both per-bundle picks', async () => {
      const reopened = await openDialog(propForm);
      await reopened
        .getByRole('button', { name: 'Expand Related content', exact: true })
        .click();
      await reopened
        .getByRole('button', { name: 'Expand News Item', exact: true })
        .click();
      await reopened
        .getByRole('button', { name: 'Expand Blog post', exact: true })
        .click();
      // The coalesced branch is expanded back per bundle: both picks restore.
      await expect(
        bundleSection(reopened, 'News Item', 'Blog post').getByRole(
          'checkbox',
          {
            name: 'Title',
            exact: true,
          },
        ),
      ).toHaveAttribute('data-state', 'checked');
      await expect(
        bundleSection(reopened, 'Blog post', 'News Item').getByRole(
          'checkbox',
          {
            name: 'Title',
            exact: true,
          },
        ),
      ).toHaveAttribute('data-state', 'checked');
    });
  });
});
