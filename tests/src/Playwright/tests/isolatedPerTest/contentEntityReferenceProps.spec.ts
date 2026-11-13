import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

import type { Locator, Page } from '@playwright/test';

// @cspell:ignore cerlist

const COMPONENT_CODE = [
  'const Component = ({ author }) => (',
  '  <div>Author: {author?.name}</div>',
  ');',
  'export default Component;',
].join('\n');

// The preview renders this code before the `related` prop exists, and while it
// is still a single reference prop: in both cases `related` is undefined.
const LIST_COMPONENT_CODE = [
  'const Component = ({ related }) => (',
  '  <section>',
  '    <h2>Related</h2>',
  '    <ul aria-label="Related content">',
  '      {related?.map((item, index) => (',
  '        <li key={index}>{item.label}</li>',
  '      ))}',
  '    </ul>',
  '  </section>',
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

// Creates a News Item node through the node form and returns its ID.
async function createNewsItem(page: Page, title: string): Promise<number> {
  await page.goto('/node/add/news_item');
  await page.getByLabel('Title', { exact: true }).fill(title);
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(
    page.getByRole('contentinfo', { name: 'Status message' }),
  ).toContainText(`${title} has been created.`);
  const match = new URL(page.url()).pathname.match(/^\/node\/(\d+)$/);
  expect(match).not.toBeNull();
  return Number(match![1]);
}

test.describe('Content entity reference props', () => {
  // The dialog only lists entity types the user can view, so grant the editor
  // the permissions needed to see User, and to create and delete the News Item
  // nodes that the tests reference. The editor inherits "access content" from
  // the authenticated role, so the permissions form does not allow to check it.
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
      permissions: [
        'access user profiles',
        'create news_item content',
        'delete any news_item content',
      ],
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

  test('Authors, sources and renders a content entity reference list prop', async ({
    page,
    drupal,
    canvas,
  }) => {
    const titles = {
      first: 'First news item',
      second: 'Second news item',
      third: 'Third news item',
    };

    await drupal.login({ username: 'editor', password: 'editor' });

    const nids = await test.step('Create three News Item nodes', async () => {
      const first = await createNewsItem(page, titles.first);
      const second = await createNewsItem(page, titles.second);
      const third = await createNewsItem(page, titles.third);
      return { first, second, third };
    });

    const canvasPage = await canvas.createCanvas();
    await canvas.openCanvas(canvasPage);
    await canvas.createCodeComponent('CerList', LIST_COMPONENT_CODE);

    const propForm = await canvas.addCodeComponentProp(
      'related',
      'Content entity reference',
    );

    await test.step('Allow up to three values and pick Content / News Item with its title', async () => {
      const allowMultiple = propForm.getByRole('checkbox', {
        name: 'Allow multiple values',
      });
      await allowMultiple.check();
      await expect(allowMultiple).toBeChecked();

      // The limited count starts at 2, the minimum for a list prop.
      await selectFromDropdown(propForm, 'Unlimited', 'Limited');
      const count = propForm.getByRole('spinbutton');
      await expect(count).toHaveValue('2');
      await propForm.getByRole('button', { name: 'Increase count' }).click();
      await expect(count).toHaveValue('3');

      const autoSave = page.waitForResponse(
        (response) =>
          response
            .url()
            .includes('/canvas/api/v0/config/auto-save/js_component/') &&
          response.request().method() === 'PATCH' &&
          (response.request().postData() ?? '').includes('news_item'),
      );
      await chooseTarget(propForm, 'Content', 'News Item');
      expect((await autoSave).ok()).toBe(true);
    });

    await test.step('Publish the component and add it to the components', async () => {
      // A list prop with examples fails validation, so the publish succeeds
      // only when the list prop has no examples.
      await canvas.publishAllChanges(['CerList', 'Global CSS']);
      await canvas.saveCodeComponent('js.cerlist');
    });

    await test.step('Pick two nodes in reverse creation order', async () => {
      // Load the editor again. After a publish from the code editor, the next
      // auto-save can fail with a 409 conflict, see #3592289.
      await canvas.openCanvas(canvasPage);
      await canvas.openLibraryPanel();
      await canvas.addComponent({ id: 'js.cerlist' });

      const relatedField = page
        .getByTestId('canvas-contextual-panel')
        .locator('[data-canvas-multiple-values="true"]')
        .filter({
          has: page.getByRole('heading', { name: 'related', exact: true }),
        });
      // The limited count of 3 gives three rows.
      await expect(
        relatedField.getByRole('button', { name: /^Edit related:/ }),
      ).toHaveCount(3);

      await canvas.editMultiValueProp(
        'related',
        `${titles.third} (${nids.third})`,
        0,
        'entity-reference',
      );
      await canvas.editMultiValueProp(
        'related',
        `${titles.first} (${nids.first})`,
        1,
        'entity-reference',
      );

      // The list keeps the picked order, and the empty third row adds no item.
      await canvas.testInPreviewFrame('canvas-island', async (islands) => {
        await expect(
          islands
            .getByRole('list', { name: 'Related content' })
            .getByRole('listitem'),
        ).toHaveText([titles.third, titles.first]);
      });
    });

    await test.step('The published page lists the nodes in the picked order', async () => {
      await canvas.publishAllChanges(['Untitled page']);
      await page.goto(`/page/${canvasPage.entity_id}`);
      await expect(
        page
          .locator('canvas-island')
          .getByRole('list', { name: 'Related content' })
          .getByRole('listitem'),
      ).toHaveText([titles.third, titles.first]);
    });

    await test.step('A deleted node is removed from the published page', async () => {
      await page.goto(`/node/${nids.third}/delete`);
      await page.getByRole('button', { name: 'Delete', exact: true }).click();
      await expect(
        page.getByRole('contentinfo', { name: 'Status message' }),
      ).toContainText(`${titles.third} has been deleted.`);

      await page.goto(`/page/${canvasPage.entity_id}`);
      await expect(
        page
          .locator('canvas-island')
          .getByRole('list', { name: 'Related content' })
          .getByRole('listitem'),
      ).toHaveText([titles.first]);
    });

    await test.step('A single content entity reference prop receives one object', async () => {
      // "News listing" reads `label` from its single `featuredNews` prop. A
      // list value has no `label` key, so the heading shows only for an object.
      await canvas.openCanvas(canvasPage);
      await canvas.openLibraryPanel();
      await canvas.addComponent({
        id: 'js.canvas_test_code_components_news_listing',
      });
      await canvas.editComponentProp(
        'featuredNews',
        `${titles.second} (${nids.second})`,
      );
      await canvas.testInPreviewFrame('canvas-island', async (islands) => {
        await expect(islands.getByRole('heading', { level: 3 })).toHaveText(
          titles.second,
        );
      });
    });
  });
});
