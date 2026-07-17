import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

test.use({
  modules: ['canvas_test_sdc'],
  enableTestExtensions: true,
});

test.describe('Prop adapters', () => {
  test.beforeEach(async ({ drupal }) => {
    await drupal.loginAsAdmin();
    await drupal.applyRecipe(
      `modules/contrib/canvas/tests/fixtures/recipes/article_translation`,
    );
    await drupal.installModules(['canvas_test_article_fields']);
    await drupal.addPermissions({
      role: 'editor',
      permissions: [
        'use editorial transition create_new_draft',
        'use editorial transition publish',
        'edit any article content',
      ],
    });
    await drupal.logout();
  });

  test('Configure an Equals transform on a heading prop', async ({
    page,
    drupal,
    canvas,
  }) => {
    // A full journey (recipe, template build, transform config, publish,
    // rendered output) legitimately needs more than the default budget.
    test.slow();
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.openCanvasRoot();
    await canvas.openTemplatesPanel();

    // Build an Article "Full content" template with a hero component.
    await canvas.addTemplate('Article', 'Full content');
    await page.getByTestId('template-list-item-article-Full content').click();
    expect(page.url()).toContain('canvas/template/node/article/full/1');
    await canvas.openLibraryPanel();
    await canvas.addComponent({ id: 'sdc.canvas_test_sdc.my-hero' });

    // Open the heading prop linker and pick the "Equals" transform from the
    // Transform section of the dropdown.
    await page.getByLabel('Link heading to an other field').click();
    await page.locator('[data-transform-option="equals"]').click();

    const panel = page.getByTestId('adapter-config-panel');
    await expect(panel).toBeVisible();

    // Bind value → the Title field.
    const valueRow = panel.getByTestId('adapter-input-value');
    await valueRow.getByRole('combobox').click();
    await page.getByRole('option', { name: 'Title', exact: true }).click();

    // Bind comparison → the literal "x".
    const comparisonRow = panel.getByTestId('adapter-input-comparison');
    await comparisonRow.getByRole('radio', { name: 'Value' }).click();
    await comparisonRow.getByRole('textbox').fill('x');

    // Bind then → the literal "Free".
    const thenRow = panel.getByTestId('adapter-input-then');
    await thenRow.getByRole('radio', { name: 'Value' }).click();
    await thenRow.getByRole('textbox').fill('Free');

    // Reveal the optional else input and bind it to the Title field.
    await panel.getByRole('button', { name: 'Else', exact: true }).click();
    const elseRow = panel.getByTestId('adapter-input-else');
    await elseRow.getByRole('combobox').click();
    await page.getByRole('option', { name: 'Title', exact: true }).click();

    // The live preview evaluates against the preview entity (Article One).
    // Its title does not equal "x", so the else branch — the title itself —
    // is returned.
    await expect(panel.getByTestId('adapter-preview')).toContainText(
      'Article One',
    );

    await page.getByTestId('adapter-apply').click();
    await expect(panel).toBeHidden();

    // The prop shows as linked to the adapter source.
    await expect(page.getByTestId('linked-field-box-heading')).toBeVisible();
    await expect(page.getByTestId('linked-field-label-heading')).toContainText(
      'Equals',
    );

    // The editor preview renders the evaluated value.
    await canvas.testInPreviewFrame(
      '[data-component-id="canvas_test_sdc:my-hero"] h1',
      async (h1) => {
        await expect(h1).toContainText('Article One');
      },
    );

    await canvas.publishAllChanges();

    // The published article renders the transformed value.
    await page.goto('/article-one');
    await expect(page.locator('h1.my-hero__heading')).toHaveText('Article One');
  });
});
