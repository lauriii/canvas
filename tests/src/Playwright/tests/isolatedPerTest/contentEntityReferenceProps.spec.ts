import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

import type { Locator } from '@playwright/test';

test.use({
  modules: ['canvas_test_code_components_content_entity_ref'],
});

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

test.describe('Content entity reference props', () => {
  // The dialog only lists entity types the user can view, so grant the editor
  // the permissions needed to see User and Content.
  test.beforeEach(async ({ drupal }) => {
    await drupal.loginAsAdmin();
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
      const bodyValue = fieldCheckbox(dialog, 'Body → Text');
      await expect(bodyValue).toBeVisible();
      await bodyValue.click();
      await expect(bodyValue).toHaveAttribute('data-state', 'checked');
      await expect(fieldCheckbox(dialog, 'Body → Summary')).toHaveAttribute(
        'data-state',
        'unchecked',
      );
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
      await expect(fieldCheckbox(reopened, 'Body → Text')).toHaveAttribute(
        'data-state',
        'checked',
      );
      await expect(fieldCheckbox(reopened, 'Body → Summary')).toHaveAttribute(
        'data-state',
        'unchecked',
      );

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
});
