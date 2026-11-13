import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

import type { Page } from '@playwright/test';

// @cspell:ignore Bonjour revoir
/**
 * Tests translating the strings of a code component made in the code editor.
 *
 * The code editor finds the strings in the source code and Drupal registers
 * them with Locale.
 *
 * @see docs/adr/0023-code-component-interface-translation.md
 */

test.use({ modules: ['language', 'locale'] });

const code = (
  body: string,
) => `import { useTranslation } from 'drupal-canvas/react';

export default function Greeting() {
  const { t, formatPlural } = useTranslation();
  return (
    <div>
${body}
    </div>
  );
}
`;

const hello = `      <p>{t('Hello @name', { '@name': 'Ada' })}</p>`;
const month = `      <p>{t('May', {}, { context: 'Long month name' })}</p>`;
const files = `      <p>{formatPlural(2, '1 file', '@count files')}</p>`;
const goodbye = `      <p>{t('Goodbye')}</p>`;
const removed = `      <p>{t('Removed later')}</p>`;

async function filterStrings(page: Page, search: string) {
  await page.goto('/admin/config/regional/translate');
  await page.getByLabel('String contains').fill(search);
  await page.getByLabel('Translation language').selectOption('fr');
  // Not the "Filter translatable strings" details summary.
  await page.getByRole('button', { name: 'Filter', exact: true }).click();
}

// Other strings can contain the searched text too, such as "Mayotte".
function translationField(page: Page, source: string, context?: string) {
  const name = `Source string (Built-in English) ${source}${context ? ` In Context: ${context}` : ''}`;
  return page
    .locator('table.locale-translate-edit-table tbody tr')
    .filter({ has: page.getByRole('cell', { name, exact: true }) })
    .getByLabel('Translated string (French)');
}

async function translateString(
  page: Page,
  source: string,
  translation: string,
  context?: string,
) {
  await filterStrings(page, source);
  await translationField(page, source, context).fill(translation);
  await page.getByRole('button', { name: 'Save translations' }).click();
  await expect(page.getByText('The strings have been saved.')).toBeVisible();
}

async function replaceCode(page: Page, source: string) {
  // The code editor does not auto-save changes made before it finishes its
  // first compilation. Its preview only shows code compiled since it opened,
  // and every version of the component greets Ada.
  await expect(
    page
      .locator('iframe[data-canvas-iframe="canvas-code-editor-preview"]')
      .contentFrame()
      .locator('#canvas-code-editor-preview-root'),
    'The code editor compiles the component before it is changed.',
  ).toContainText('Hello Ada', { timeout: 60_000 });
  const autoSaved = page.waitForResponse(
    (response) => {
      if (
        !response
          .url()
          .includes('/canvas/api/v0/config/auto-save/js_component/greeting') ||
        response.request().method() !== 'PATCH'
      ) {
        return false;
      }
      try {
        return (
          response.request().postDataJSON()?.data?.sourceCodeJs === source &&
          response.ok()
        );
      } catch {
        return false;
      }
    },
    { timeout: 30_000 },
  );
  const codeEditor = page.locator(
    '[data-testid="canvas-code-editor-main-panel"] div[role="textbox"]',
  );
  await expect(codeEditor).toBeVisible();
  await codeEditor.selectText();
  await page.keyboard.press('Delete');
  await codeEditor.fill(source);
  await autoSaved;
}

test.describe('Code component translation', () => {
  test('Translates the strings found in the code editor', async ({
    page,
    canvas,
    drupal,
  }) => {
    await drupal.loginAsAdmin();
    // Do not download translations when adding a language.
    await page.goto('/admin/config/regional/translate/settings');
    await page.getByLabel('Local files only').check();
    await page.getByRole('button', { name: 'Save configuration' }).click();
    await page.goto('/admin/config/regional/language/add');
    await page
      .locator('[data-drupal-selector="edit-predefined-langcode"]')
      .selectOption('fr');
    await page
      .locator('[data-drupal-selector="edit-predefined-submit"]')
      .click();
    await page.waitForURL('**/admin/config/regional/language', {
      timeout: 30000,
    });

    const canvasPage = await canvas.createCanvas();
    await canvas.openCanvas(canvasPage);
    await canvas.createCodeComponent(
      'Greeting',
      code([hello, month, files, goodbye, removed].join('\n')),
    );
    await expect(canvas.getCodePreviewFrame()).toContainText('Hello Ada');
    await canvas.publishAllChanges(['Greeting', 'Global CSS']);
    await canvas.saveCodeComponent('js.greeting');
    await canvas.addComponent({ id: 'js.greeting' }, { hasInputs: false });
    await canvas.publishAllChanges(['Untitled page']);

    // Publishing registers the strings, so they are translatable before any
    // page renders them in French.
    await filterStrings(page, '@count files');
    await expect(
      page.locator('table.locale-translate-edit-table'),
    ).toContainText('1 file');
    await translateString(page, 'Hello @name', 'Bonjour @name');
    await translateString(page, 'May', 'Mai', 'Long month name');
    await translateString(page, 'Goodbye', 'Au revoir');

    await page.goto(`/fr/page/${canvasPage.entity_id}`);
    const island = page.locator('canvas-island');
    await expect(island).toContainText('Bonjour Ada');
    await expect(island).toContainText('Mai');
    await expect(island).toContainText('2 files');
    await expect(island).toContainText('Au revoir');
    await expect(island).toContainText('Removed later');

    // Strings the source code no longer uses are removed, unless they are
    // translated.
    await page.goto('/canvas/code-editor/component/greeting');
    await replaceCode(page, code([hello, month, files].join('\n')));
    await canvas.publishAllChanges(['Greeting']);
    await filterStrings(page, 'Removed later');
    await expect(page.getByText('No strings available.')).toBeVisible();
    await filterStrings(page, 'Goodbye');
    await expect(translationField(page, 'Goodbye')).toHaveValue('Au revoir');

    // Invalid literals are reported in the code editor. Its strings are then
    // unknown: the component can be published, but its strings are not
    // translated, and Locale keeps their translations.
    await page.goto('/canvas/code-editor/component/greeting');
    await replaceCode(page, code([hello, `      <p>{t('')}</p>`].join('\n')));
    await expect(
      page.getByText(
        'Error: The translatable strings could not be determined.',
      ),
    ).toBeVisible();
    await expect(
      page.getByText(
        'Line 8, column 13: A translatable string must not be empty.',
      ),
    ).toBeVisible();
    await canvas.publishAllChanges(['Greeting']);
    await page.goto(`/fr/page/${canvasPage.entity_id}`);
    await expect(page.locator('canvas-island')).toContainText('Hello Ada');
    await filterStrings(page, 'Hello @name');
    await expect(translationField(page, 'Hello @name')).toHaveValue(
      'Bonjour @name',
    );
  });
});
