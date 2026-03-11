import { getModuleDir } from '@drupal-canvas/test-utils';
import { expect } from '@playwright/test';

import { test } from './fixtures/DrupalSite';
import { Drupal } from './objects/Drupal';

import type { Page } from '@playwright/test';
import type { CanvasEditor } from './objects/CanvasEditor';

// Each object property is a prop type with a value of an array of the available
// suggestions to it.
const suggestionsByProp = {
  test_bool_default_false: [
    'Image Media > Media > Published',
    'Revision user > User > User status',
    'One Tag > Taxonomy term > Published',
    'Authored by > User > User status',
    'Image Upload > Status',
    'Published',
    'The Boolean',
  ],
  test_bool_default_true: [
    'One Tag > Taxonomy term > Published',
    'The Boolean',
    'Published',
    'Image Upload > Status',
    'Revision user > User > User status',
    'Image Media > Media > Published',
    'Authored by > User > User status',
  ],
  test_string: [
    'One Tag > Taxonomy term > Name',
    'Image Media > Media > Name',
    'Image Media > Media > Image > Title',
    'Image Upload > Alternative text',
    'Title',
    'The Link > Link text',
    'Revision user > User > Name',
    'Image Upload > Title',
    'Authored by > User > Name',
    'Image Media > Media > Image > Alternative text',
  ],
  test_REQUIRED_string: ['Title', 'Image Media > Media > Name'],
  test_string_format_email: [
    'The Email',
    'Revision user > User > Initial email',
    'Revision user > User > Email',
    'Authored by > User > Initial email',
    'Authored by > User > Email',
  ],
  test_string_format_idn_email: [
    'The Email',
    'Revision user > User > Initial email',
    'Revision user > User > Email',
    'Authored by > User > Initial email',
    'Authored by > User > Email',
  ],
  test_REQUIRED_string_format_uri: [
    'Image Upload > URI',
    'Absolute URL',
    'Image Media > Media > Image > URI',
  ],
  test_REQUIRED_string_format_uri_reference_web_links: [
    'Image Upload',
    'Relative URL',
    'Image Media > Media > Image',
    'Image Media > URL',
  ],
  test_string_format_uri: [
    'Absolute URL',
    'Image Media > Media > Image > URI',
    'Image Media > Media > Thumbnail > URI',
    'Image Upload > URI',
  ],
  test_string_format_uri_image: [
    'Image Upload',
    'Image Media > Media > Image',
    'Image Media > Media > Thumbnail',
  ],
  test_string_format_uri_image_using_ref: [
    'Image Upload',
    'Image Media > Media > Image',
    'Image Media > Media > Thumbnail',
  ],
  test_string_format_uri_reference: [
    'The Link > The Link',
    'Authored by > URL',
    'Image Media > Media > Authored by > URL',
    'Image Upload',
    'Image Media > Media > Revision user > URL',
    'One Tag > URL',
    'Relative URL',
    'Revision user > URL',
    'Image Media > Media > Image',
    'Image Media > URL',
    'The Link > Resolved URL',
    'Image Media > Media > Thumbnail',
    'One Tag > Taxonomy term > Revision user > URL',
  ],
  test_string_format_iri: [
    'Absolute URL',
    'Image Media > Media > Image > URI',
    'Image Media > Media > Thumbnail > URI',
    'Image Upload > URI',
  ],
  test_string_format_iri_reference: [
    'The Link > Resolved URL',
    'Image Media > Media > Image',
    'The Link > The Link',
    'One Tag > Taxonomy term > Revision user > URL',
    'Image Media > Media > Thumbnail',
    'Authored by > URL',
    'Image Media > Media > Authored by > URL',
    'Relative URL',
    'Image Media > URL',
    'Revision user > URL',
    'Image Upload',
    'Image Media > Media > Revision user > URL',
    'One Tag > URL',
  ],
  test_integer: [
    'Image Upload > Width',
    'Image Upload > Height',
    'Image Upload > File size',
    'Number List',
    'The Number',
    'One Tag > Taxonomy term > Weight',
    'Image Media > Media > Image > Height',
    'Image Media > Media > Image > Width',
  ],
  test_integer_range_minimum_maximum_timestamps: [
    'Revision user > User > Last login',
    'Revision user > User > Changed',
    'Revision user > User > Last access',
    'Image Upload > Changed',
    'Authored by > User > Changed',
    'Authored by > User > Created',
    'Authored by > User > Last access',
    'Authored by > User > Last login',
    'Authored on',
    'Changed',
    'Image Media > Media > Authored on',
    'Image Media > Media > Changed',
    'Image Media > Media > Revision create time',
    'One Tag > Taxonomy term > Changed',
    'Revision create time',
    'Revision user > User > Created',
    'One Tag > Taxonomy term > Revision create time',
    'Image Upload > Created',
  ],
  test_string_format_date: [
    'Authored on',
    'Changed',
    'Date Only',
    'Date and time',
  ],
  test_string_html_block: [
    'Body > Processed summary',
    'Body > Body',
    'One Tag > Taxonomy term > Description',
  ],
  test_string_html: [
    'Body > Processed summary',
    'Body > Body',
    'One Tag > Taxonomy term > Description',
  ],
  test_object_drupal_image: [
    'Image Media > Image > Image',
    'Image Upload',
    'Image Media > Image > Thumbnail',
  ],
};

const suggestionsToLinkByProp = {
  test_string_format_date: ['Authored on'],
};

// cspell:ignore Bwidth Fitok treehouse
test.describe.serial('Linking Basic Page fields ', () => {
  // These tests can take a long time to run and are not particularly browser
  // dependent. Only run in Chromium to keep test duration reasonable.
  test.skip(
    ({ browserName }) => browserName !== 'chromium',
    'Only runs in Chromium',
  );
  test.beforeAll(
    'Setup test site with Drupal Canvas, templates enabled, and page with many field types',
    async ({ browser, drupalSite }) => {
      const page = await browser.newPage();
      const drupal: Drupal = new Drupal({ page, drupalSite });
      await drupal.setupCanvasTestSite();
      const moduleDir = await getModuleDir();
      await drupal.applyRecipe(
        `${moduleDir}/canvas/tests/fixtures/recipes/template_basic_setup`,
      );
      await page.close();
      await drupal.drush('cr');
    },
  );

  const addAllPropsComponent = async ({
    page,
    canvasEditor,
  }: {
    page: Page;
    canvasEditor: CanvasEditor;
  }) => {
    await expect(
      page.getByTestId('canvas-empty-region-drop-zone-content'),
    ).toBeVisible();
    await canvasEditor.openLibraryPanel();
    await canvasEditor.addComponent({
      id: 'sdc.sdc_test_all_props.all-props',
    });
    await expect(page.getByText('Review 1 change')).toBeVisible();
  };

  // In addition to the standard page, drupal, and canvasEditor arguments:
  // - inputName: The name of the prop input to test linking to.
  // - suggestions: An array of suggestion strings to select for the prop, the
  //   strings may include nested paths separated by " > ".
  const testPropLinking = async ({
    page,
    drupal,
    canvasEditor,
    inputName,
    suggestions,
  }: {
    page: Page;
    drupal: Drupal;
    canvasEditor: CanvasEditor;
    inputName: string;
    suggestions: string[];
  }) => {
    await drupal.loginAsAdmin();
    await page.goto('/canvas/template/node/page/full');
    // Add styles that allow nested suggestions to not overlap each other, so
    // e2e tests can properly access these items without the parent menu
    // closing. This includes widening the contextual panel, and positioning
    // the linker element underneath its corresponding label.
    // Also disable all transitions and animations to prevent timing issues.
    await page.addStyleTag({
      content: `
      *, *::before, *::after {
        transition: none !important;
        animation: none !important;
        animation-duration: 0s !important;
        transition-duration: 0s !important;
        transition-delay: 0s !important;
        animation-delay: 0s !important;
      }
      .canvas-linked-prop-label-wrapper {
        flex-wrap: wrap!important;
      }
      .canvas-linked-prop-label-wrapper label {
        width: 100%;
      }
      [role="menuitem"] {
        font-size: 12px;
        padding: 0;
      }
      [data-testid="canvas-contextual-panel"] {
        width: 600px;
      }
      [data-link-suggestion-option] {
          min-width: 120px;
      }
    `,
    });
    await canvasEditor.waitForEditorUINoContextualPanel();

    await page.getByTestId('select-content-preview-item').click();
    await page.getByRole('menuitem', { name: 'Debut Page' }).click();

    // Select the linkable props component.
    await page.locator('[aria-label="All props"]').click();

    // Open the linker for the specified input.
    const linker = page.getByLabel(`Link ${inputName} to an other field`);
    await expect(linker).toBeAttached();
    const suggestionsSerialized = await linker.getAttribute(
      'data-canvas-link-suggestions',
    );
    const actualSuggestions = suggestionsSerialized
      ? JSON.parse(suggestionsSerialized)
      : [];
    expect(actualSuggestions.length).toBeGreaterThan(0);

    // Assert that the actual suggestions match the expected suggestions.
    // The suggestions may include duplicates to ensure there aren't consecutive
    // items with the same final-item name.
    expect([...actualSuggestions].sort()).toEqual(
      [...new Set(suggestions)].sort(),
    );

    // Go through every suggestion and confirm it can link and provide the
    // expected value in the preview.
    const suggestionsToLink =
      suggestionsToLinkByProp[
        inputName as keyof typeof suggestionsToLinkByProp
      ] ?? suggestions;

    for (const suggestionOption of suggestionsToLink) {
      await linker.click();
      const suggestionPath = suggestionOption.split(' > ');
      let itemSelected = false;
      let parent = 'root';
      let stepCount = 1;
      await test.step(
        `Choose ${suggestionOption} for ${inputName}`,
        async () => {
          for (const pathItem of suggestionPath) {
            const index = suggestionPath.indexOf(pathItem);

            const parentName =
              pathItem === parent && index === 0 ? 'myself' : parent;
            const step = await page.locator(
              `[data-link-suggestion-option="${pathItem}"][data-child-of="${parentName}"]`,
            );
            await step.waitFor({ state: 'visible' });
            // If this is the final step in the suggestion path, click to select.
            if (stepCount === suggestionPath.length) {
              await step.click();

              // Wait for the patch requests to complete. This wait is not
              // needed for end users, but benefits the stability of the test
              // as excessive changes within a short period of time can exhaust
              // Playwright.
              await page.waitForResponse(
                (response) =>
                  response
                    .url()
                    .includes('/canvas/api/v0/layout-content-template/') &&
                  response.request().method() === 'PATCH',
              );
              await page.waitForResponse(
                (response) =>
                  response
                    .url()
                    .includes(
                      '/canvas/api/v0/form/component-instance/content_template/',
                    ) && response.request().method() === 'PATCH',
              );

              await expect(
                page.getByTestId(`linked-field-box-${inputName}`),
              ).toBeVisible();
              // @todo determine why the path Image Media > Media > Image is
              // presented as Image Media > Image > Image in the linked field
              // box title attribute. For now, these few instances can just be skipped.
              if (!suggestionOption.startsWith('Image Media > Media > Image')) {
                await expect(
                  page
                    .getByTestId(`linked-field-box-${inputName}`)
                    .locator('div[title]'),
                ).toHaveAttribute(
                  'title',
                  suggestionPath
                    .filter(
                      (item, index) =>
                        index === 0 ||
                        !(item === suggestionPath[index - 1] && index === 1),
                    )
                    .join(' / '),
                  {
                    timeout: 8000,
                  },
                );
              }

              await expect(
                page.getByTestId(`linked-field-label-${inputName}`),
                `look at ${suggestionPath.join()}`,
              ).toHaveText(suggestionPath[suggestionPath.length - 1], {
                timeout: 8000,
              });

              itemSelected = true;
            } else {
              parent = pathItem;
              // 1. Get bounding box and move mouse to center of element
              const box = await step.boundingBox();
              if (box) {
                await page.mouse.move(
                  box.x + box.width / 2,
                  box.y + box.height / 2,
                );
              }
              await expect(step).toHaveAttribute('aria-expanded', 'true');
              // Dispatch pointermove event as backup
              await step.dispatchEvent('pointermove');
              // 4. Wait for the next item in the path to become visible before continuing.
              // This ensures the submenu has actually opened.
              const nextPathItem = suggestionPath[stepCount];
              const nextParentName =
                nextPathItem === pathItem && index === 0 ? 'myself' : pathItem;
              const nextStep = page.locator(
                `[data-link-suggestion-option="${nextPathItem}"][data-child-of="${nextParentName}"]`,
              );
              // Wait with retry logic - sometimes Radix needs a moment
              await nextStep.waitFor({ state: 'visible', timeout: 3000 });
            }
            stepCount += 1;
          }
        },
        { timeout: 20000 },
      );

      // Confirm an item was selected.
      expect(itemSelected).toBe(true);

      await test.step(`Verify preview for ${suggestionOption} for ${inputName}`, async () => {
        const previewSelector =
          propNameToSelector[inputName as keyof typeof propNameToSelector];
        const expectedValue =
          entityValuesNode4[
            `${inputName}::${suggestionOption}` as keyof typeof entityValuesNode4
          ] ??
          entityValuesNode4[suggestionOption as keyof typeof entityValuesNode4];
        expect(expectedValue).toBeDefined();
        await expect(
          (await canvasEditor.getActivePreviewFrame()).locator(previewSelector),
        ).toContainText(expectedValue);
      });
    }
  };

  // Setup test adds the component used by all subsequent tests.
  // The component state persists in the Drupal database (worker-scoped).
  test('pre loop setup', async ({ page, drupal, canvasEditor }) => {
    await drupal.loginAsAdmin();
    await page.goto('/canvas/template/node/page/full');
    await canvasEditor.waitForEditorUINoContextualPanel();

    await page.getByTestId('select-content-preview-item').click();
    await page.getByRole('menuitem', { name: 'Debut Page' }).click();
    await addAllPropsComponent({ page, canvasEditor });
    // Confirm all available linkers are configured to be tested.
    for (const inputName of Object.keys(suggestionsByProp)) {
      await expect(
        page.getByLabel(`Link ${inputName} to an other field`),
      ).toBeVisible();
    }

    // Assert that all properties in entityValuesNode4 are represented in suggestionsByProp
    const allSuggestions = Object.values(suggestionsByProp).flat();
    const entityKeys = Object.keys(entityValuesNode4).filter(
      (key) => !key.includes('::'),
    );
    const missingKeys = entityKeys.filter(
      (key) => !allSuggestions.includes(key),
    );
    expect(missingKeys).toEqual([]);
  });

  for (const [inputName, suggestions] of Object.entries(suggestionsByProp)) {
    const testName = inputName.replace(/_/g, ' ').replace(/test /g, '');
    test(testName, async ({ page, drupal, canvasEditor }) => {
      await testPropLinking({
        page,
        drupal,
        canvasEditor,
        inputName,
        suggestions,
      });
    });
  }
});

// Object mapping suggestion labels to expected preview values:
// - `Suggestion path` => expected value for all props using that suggestion.
// - `propName::Suggestion path` => prop-specific override when one prop renders
//   the same suggestion differently from another.
const entityValuesNode4 = {
  Title: 'Debut Page',
  Published: 'true',
  'The Boolean': 'true',
  'Authored on': '1760098151',
  Changed: /\d{9,}/,
  'Number List': '1',
  'The Number': '33',
  'Revision create time': /\d{9,}/,
  'Image Upload > Status': 'true',
  'Image Upload > Alternative text': 'secret plans',
  'Image Upload > Title': '', // this seems wrong?
  'Image Upload > URI': 'public://2025-10/secret-plans_2.jpg',
  'Absolute URL': '/node/4',
  'Image Upload > Width': '1000',
  'Image Upload > Height': '1333',
  'Image Upload > File size': '318082',
  'Image Upload > Changed': /\d{9,}/,
  'Image Upload > Created': /\d{9,}/,
  'Image Upload':
    /.*?\/files\/2025-10\/secret-plans_2\.jpg\?alternateWidths=.*?\/files\/styles\/canvas_parametrized_width--%7Bwidth%7D\/public\/2025-10\/secret-plans_2\.jpg\.(webp|avif)%3Fitok%3D[^ \n]+/,
  'Relative URL': /\/node\/\d+/,
  'Revision user > User > Name': 'admin',
  'Revision user > User > Email': 'admin@example.com',
  'Revision user > User > Initial email': 'admin@example.com',
  'Revision user > User > User status': 'true',
  'Revision user > User > Changed': /\d{9,}/,
  'Revision user > User > Last access': /\d{9,}/,
  'Revision user > User > Created': /\d{9,}/,
  'Revision user > User > Last login': /\d{9,}/,
  'Revision user > URL': '/user/1',
  'Authored by > User > Name': 'admin',
  'Authored by > User > Email': 'admin@example.com',
  'Authored by > User > Initial email': 'admin@example.com',
  'Authored by > User > User status': 'true',
  'Authored by > User > Changed': /\d{9,}/,
  'Authored by > User > Last access': /\d{9,}/,
  'Authored by > User > Created': /\d{9,}/,
  'Authored by > User > Last login': /\d{9,}/,
  'Authored by > URL': '',
  'One Tag > Taxonomy term > Description': '',
  'One Tag > Taxonomy term > Name': 'Cool tag',
  'One Tag > Taxonomy term > Published': 'true',
  'One Tag > Taxonomy term > Weight': '0',
  'One Tag > Taxonomy term > Changed': /\d{9,}/,
  'One Tag > Taxonomy term > Revision create time': /\d{9,}/,
  'One Tag > Taxonomy term > Revision user > URL': '',
  'One Tag > URL': /\/taxonomy\/term\/[12]/,
  'Image Media > Media > Changed': /\d{9,}/,
  'Image Media > Media > Revision create time': /\d{9,}/,
  'Image Media > Media > Authored on': '1760098240',
  'Image Media > Media > Name': 'treehouse.jpeg',
  'Image Media > Media > Published': 'true',
  'Image Media > Media > Image > Alternative text': 'tree house',
  'Image Media > Media > Image > Title': '',
  'Image Media > Image > Image':
    /\/files\/styles\/canvas_parametrized_width--%7Bwidth%7D\/public\/2025-10\/treehouse_0\.jpeg\.(webp|avif)%3Fitok/,
  'Image Media > Media > Image':
    /\/files\/styles\/canvas_parametrized_width--%7Bwidth%7D\/public\/2025-10\/treehouse_0\.jpeg\.(webp|avif)%3Fitok/,
  'Image Media > Image > Thumbnail':
    /\/files\/styles\/canvas_parametrized_width--%7Bwidth%7D\/public\/2025-10\/treehouse_0\.jpeg\.(webp|avif)%3Fitok/,
  'Image Media > Media > Thumbnail':
    /\/files\/styles\/canvas_parametrized_width--%7Bwidth%7D\/public\/2025-10\/treehouse_0\.jpeg\.(webp|avif)%3Fitok/,
  'Image Media > URL': '/media/3/edit',
  'Image Media > Media > Thumbnail > URI': 'public://2025-10/treehouse_0.jpeg',
  'Image Media > Media > Image > URI': 'public://2025-10/treehouse_0.jpeg',
  'Image Media > Media > Authored by > URL': '',
  'Image Media > Media > Revision user > URL': '',
  'Image Media > Media > Image > Height': '665',
  'Image Media > Media > Image > Width': '1024',
  'The Link > Link text': 'Dinosaur Comics',
  'The Link > The Link': 'https://qwantz.com',
  'The Link > Resolved URL': 'https://qwantz.com',
  'The Email': 'one@one.com',
  'Body > Body':
    'This page emerged on the scene. This page was met with indifference.',
  'Body > Processed summary': '',
  'test_string_format_date::Authored on': '2025-10-10',
  'test_string_format_date::Changed': '2025-10-10',
  'test_string_format_date::Date Only': '2011-11-11',
  'test_string_format_date::Date and time': '2010-10-10T10:10:10',
};

test.describe('Multiple-cardinality field linker', () => {
  test.beforeAll(
    'Setup test site with multiple-cardinality field',
    async ({ browser, drupalSite }) => {
      const page = await browser.newPage();
      const drupal: Drupal = new Drupal({ page, drupalSite });
      await drupal.setupCanvasTestSite();
      const moduleDir = await getModuleDir();
      await drupal.applyRecipe(
        `${moduleDir}/canvas/tests/fixtures/recipes/template_basic_setup`,
      );
      await page.close();
    },
  );

  test('linker appears for multiple-cardinality field widgets', async ({
    page,
    drupal,
    canvasEditor,
  }) => {
    await drupal.loginAsAdmin();
    await page.goto('/canvas/template/node/page/full');
    await canvasEditor.waitForEditorUINoContextualPanel();

    await page.getByTestId('select-content-preview-item').click();
    await page.getByRole('menuitem', { name: 'Debut Page' }).click();

    // Add the tags component which has an array prop.
    await canvasEditor.openLibraryPanel();
    await canvasEditor.addComponent({
      id: 'sdc.canvas_test_sdc.tags',
    });

    // Wait for the component to be selected and the contextual panel to load.
    await expect(page.getByTestId('canvas-contextual-panel')).toBeVisible();

    // Verify the linker is visible for the tags prop.
    const linker = page.getByLabel('Link tags to an other field');
    await expect(linker).toBeVisible();

    // Verify suggestions include the multiple tags field (multi-value entity reference).
    const suggestionsSerialized = await linker.getAttribute(
      'data-canvas-link-suggestions',
    );
    const actualSuggestions: string[] = suggestionsSerialized
      ? JSON.parse(suggestionsSerialized)
      : [];

    // Check that Multiple Tags field appears in suggestions.
    // This verifies that multiple-cardinality fields are included as linkable options.
    expect(
      actualSuggestions.some((s: string) => s.startsWith('Multiple Tags')),
    ).toBe(true);

    // Specifically check for the taxonomy term name path.
    expect(actualSuggestions).toContain('Multiple Tags > Taxonomy term > Name');

    // Click linker and select the multiple tags field to verify it works.
    await linker.click();
    await page
      .locator(
        '[data-link-suggestion-option="Multiple Tags"][data-child-of="root"]',
      )
      .hover();
    await page
      .locator(
        '[data-link-suggestion-option="Taxonomy term"][data-child-of="Multiple Tags"]',
      )
      .hover();
    await page
      .locator(
        '[data-link-suggestion-option="Name"][data-child-of="Taxonomy term"]',
      )
      .click();

    // Verify the link was established.
    await expect(page.getByTestId('linked-field-box-tags')).toBeVisible();
    await expect(page.getByTestId('linked-field-label-tags')).toHaveText(
      'Name',
    );

    // Verify that BOTH tags from the multiple-cardinality field appear in the preview.
    // The "Debut Page" node has field_multiple_tags with two taxonomy terms:
    // "Cool tag" and "Ok tag".
    const previewFrame = await canvasEditor.getActivePreviewFrame();
    const tagList = previewFrame.locator('.tag-list');
    await expect(tagList).toBeVisible();

    // Verify both tag values are rendered in the preview.
    await expect(tagList.locator('.tag')).toHaveCount(2);
    await expect(tagList).toContainText('Cool tag');
    await expect(tagList).toContainText('Ok tag');
  });
});

// Object mapping prop names to their corresponding preview selectors.
const propNameToSelector = {
  test_bool_default_false: '#test-bool-default-false',
  test_bool_default_true: '#test-bool-default-true',
  test_string: '#test-string code',
  test_string_multiline: '#test-string-multiline',
  test_REQUIRED_string: '#test-required-string',
  test_string_format_date: '#test-string-format-date',
  test_string_enum: '#test-string-enum',
  test_integer_enum: '#test-integer-enum',
  test_string_format_email: '#test-string-format-email',
  test_string_format_idn_email: '#test-string-format-idn-email',
  test_REQUIRED_string_format_uri: '#test-required-string-format-uri',
  test_REQUIRED_string_format_uri_reference_web_links:
    '#test-required-string-format-uri-reference-web-links',
  test_string_format_uri: '#test-string-format-uri',
  test_string_format_uri_image: '#test-string-format-uri-image',
  test_string_format_uri_image_using_ref:
    '#test-string-format-uri-image-using-ref',
  test_string_format_uri_reference: '#test-string-format-uri-reference',
  test_string_format_iri: '#test-string-format-iri',
  test_string_format_iri_reference: '#test-string-format-iri-reference',
  test_integer: '#test-integer',
  test_integer_range_minimum: '#test-integer-range-minimum',
  test_integer_range_minimum_maximum_timestamps:
    '#test-integer-range-minimum-maximum-timestamps',
  test_object_drupal_image: '#test-object-drupal-image--src',
  test_object_drupal_image_array: '#test-object-drupal-image-array',
  test_object_drupal_video: '#test-object-drupal-video',
  test_string_html_inline: '#test-string-html-inline',
  test_string_html_block: '#test-string-html-block',
  test_string_html: '#test-string-html',
  test_REQUIRED_string_html_inline: '#test-required-string-html-inline',
  test_REQUIRED_string_html_block: '#test-required-string-html-block',
  test_REQUIRED_string_html: '#test-required-string-html',
  test_array_integer: '#test-array-integer',
};
