import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

import type { FrameLocator, Page } from '@playwright/test';

test.use({ modules: ['canvas_test_sdc'] });

const contextualForm =
  '[data-testid="canvas-contextual-panel"] [data-drupal-selector="component-instance-form"]';

const editingHost = (frame: FrameLocator) =>
  frame.locator('[data-canvas-inline-editing]');

/** Double-clicks rendered text, through the overlay, as an author would. */
async function doubleClickText(
  page: Page,
  frame: FrameLocator,
  selector: string,
) {
  const box = await frame.locator(selector).first().boundingBox();
  if (!box) {
    throw new Error(`No box for ${selector}`);
  }
  await page.mouse.dblclick(box.x + box.width / 2, box.y + box.height / 2);
}

test.describe('Inline text editing', () => {
  test.beforeEach(async ({ drupal, canvas }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.openCanvas(await canvas.createCanvas());
    await canvas.openLibraryPanel();
  });

  test('edits plain text in place, as one undoable change', async ({
    page,
    canvas,
  }) => {
    await canvas.addComponent({ id: 'sdc.canvas_test_sdc.banner' });
    const frame = await canvas.getActivePreviewFrame();
    await doubleClickText(page, frame, 'article h2');
    await expect(editingHost(frame)).toHaveCount(1);
    // Labeled as the sidebar labels the prop.
    await expect(editingHost(frame)).toHaveAttribute('aria-label', 'Heading');

    await page.keyboard.press('End');
    await page.keyboard.type(' edited!');
    // Backspace edits the text; it never deletes the component.
    await page.keyboard.press('Backspace');
    // The sidebar shows the text as it is typed.
    await expect(
      page.locator(`${contextualForm} [data-canvas-sidebar-mirror]`),
    ).toHaveText('My banner title edited');
    await page.keyboard.press('Enter');

    await expect(editingHost(frame)).toHaveCount(0);
    await expect(
      (await canvas.getActivePreviewFrame()).locator('article h2'),
    ).toHaveText('My banner title edited');
    await expect(
      page.locator(`${contextualForm} .field--name-heading input`),
    ).toHaveValue('My banner title edited');
    // Focus is back on the component, for keyboard users.
    await expect(
      page.locator('.componentOverlay[data-canvas-selected="true"]'),
    ).toBeFocused();

    // The whole session is one undo step.
    await page.getByRole('button', { name: 'Undo' }).click();
    await expect(
      (await canvas.getActivePreviewFrame()).locator('article h2'),
    ).toHaveText('My banner title');
  });

  test('offers the link only for the text that labels it', async ({
    page,
    canvas,
  }) => {
    await canvas.addComponent({ id: 'sdc.canvas_test_sdc.my-hero' });
    const frame = await canvas.getActivePreviewFrame();
    const editLink = page.getByRole('button', { name: /^Edit link/ });

    await doubleClickText(page, frame, '.my-hero__heading');
    await expect(editingHost(frame)).toHaveCount(1);
    await expect(editLink).toHaveCount(0);
    await page.keyboard.press('Escape');
    await expect(editingHost(frame)).toHaveCount(0);

    await doubleClickText(page, frame, '.my-hero__cta--primary');
    await expect(editingHost(frame)).toHaveCount(1);
    await expect(editLink).toBeVisible();
  });

  test('keeps the previous text of a required prop left empty', async ({
    page,
    canvas,
  }) => {
    await canvas.addComponent({ id: 'sdc.canvas_test_sdc.banner' });
    const frame = await canvas.getActivePreviewFrame();
    await doubleClickText(page, frame, 'article h2');
    await expect(editingHost(frame)).toHaveCount(1);
    const saves: string[] = [];
    page.on('request', (request) => {
      if (
        request.method() === 'PATCH' &&
        request.url().includes('/canvas/api/v0/layout/')
      ) {
        saves.push(request.url());
      }
    });
    await page.keyboard.press('ControlOrMeta+a');
    await page.keyboard.press('Backspace');
    await page.keyboard.press('Escape');

    await expect(page.locator('[data-canvas-inline-notice]')).toContainText(
      'Heading is required',
    );
    await expect(frame.locator('article h2')).toHaveText('My banner title');
    // Nothing was saved: there is nothing to undo.
    expect(saves).toEqual([]);
  });

  test('formats text with the text format’s editor', async ({
    page,
    canvas,
  }) => {
    await canvas.addComponent({
      id: 'sdc.canvas_test_sdc.required-formatted-body',
    });
    // The text format's editor settings arrive with the sidebar form.
    await expect(
      page.locator(`${contextualForm} .field--name-body .ck-editor__editable`),
    ).toBeVisible();
    const frame = await canvas.getActivePreviewFrame();
    await doubleClickText(page, frame, 'text=Example');
    const toolbar = page.locator('[data-canvas-inline-text-toolbar]');
    await expect(toolbar.getByRole('button', { name: 'Bold' })).toBeVisible();

    await page.keyboard.press('ControlOrMeta+End');
    await page.keyboard.type(' text');
    await page.keyboard.press('Shift+ControlOrMeta+ArrowLeft');
    await toolbar.getByRole('button', { name: 'Bold' }).click();
    await expect(toolbar.getByRole('button', { name: 'Bold' })).toHaveAttribute(
      'aria-pressed',
      'true',
    );
    // Keys on the toolbar never reach the editor's Delete shortcut.
    await toolbar.getByRole('button', { name: 'Bold' }).focus();
    await page.keyboard.press('Backspace');
    await toolbar.getByRole('button', { name: 'Bold' }).press('Escape');
    await page.keyboard.press('Escape');

    await expect(editingHost(frame)).toHaveCount(0);
    await expect(
      (await canvas.getActivePreviewFrame()).locator('strong'),
    ).toHaveText('text');
  });

  test('reopens the text for a Backspace right after saving', async ({
    page,
    canvas,
  }) => {
    await canvas.addComponent({ id: 'sdc.canvas_test_sdc.banner' });
    const frame = await canvas.getActivePreviewFrame();
    await doubleClickText(page, frame, 'article h2');
    await expect(editingHost(frame)).toHaveCount(1);
    await page.keyboard.press('End');
    await page.keyboard.type('!');
    await page.keyboard.press('Enter');
    await expect(editingHost(frame)).toHaveCount(0);
    // A correction, not a request to delete the component.
    await page.keyboard.press('Backspace');
    const after = await canvas.getActivePreviewFrame();
    await expect(editingHost(after)).toHaveCount(1);
    await expect(after.locator('article')).toHaveCount(1);
  });

  test('presses toolbar buttons with Space', async ({ page, canvas }) => {
    await canvas.addComponent({
      id: 'sdc.canvas_test_sdc.required-formatted-body',
    });
    await expect(
      page.locator(`${contextualForm} .field--name-body .ck-editor__editable`),
    ).toBeVisible();
    const frame = await canvas.getActivePreviewFrame();
    await doubleClickText(page, frame, 'text=Example');
    const bold = page
      .locator('[data-canvas-inline-text-toolbar]')
      .getByRole('button', { name: 'Bold' });
    await expect(bold).toBeVisible();
    await page.keyboard.press('Alt+F10');
    await expect(bold).toBeFocused();
    await page.keyboard.press('Space');
    await expect(bold).toHaveAttribute('aria-pressed', 'true');
  });

  test('sends text it cannot edit in place to the sidebar', async ({
    page,
    canvas,
  }) => {
    await canvas.addComponent({ id: 'sdc.canvas_test_sdc.banner' });
    const frame = await canvas.getActivePreviewFrame();
    // A component developer opts this text out.
    await frame
      .locator('article')
      .evaluate((article) =>
        article.setAttribute('data-canvas-inline-edit', 'false'),
      );
    await doubleClickText(page, frame, 'article h2');
    await expect(editingHost(frame)).toHaveCount(0);
    await expect(page.getByText('Edit Heading in the panel')).toBeVisible();
    await expect(
      page.locator(`${contextualForm} .field--name-heading input`),
    ).toBeFocused();
  });

  test('edits an element marked with data-canvas-prop', async ({
    page,
    canvas,
  }) => {
    await canvas.addComponent({ id: 'sdc.canvas_test_sdc.banner' });
    const frame = await canvas.getActivePreviewFrame();
    // As a code component renders it: no markers, an explicit attribute.
    await frame.locator('article h2').evaluate((heading) => {
      heading.replaceChildren(heading.textContent!);
      heading.setAttribute('data-canvas-prop', 'heading');
    });
    await doubleClickText(page, frame, 'article h2');
    await expect(frame.locator('h2[data-canvas-inline-editing]')).toHaveCount(
      1,
    );
    await page.keyboard.press('End');
    await page.keyboard.type(' too');
    await page.keyboard.press('Enter');
    await expect(
      (await canvas.getActivePreviewFrame()).locator('article h2'),
    ).toHaveText('My banner title too');
  });

  test('keeps the text on offer when saving fails', async ({
    page,
    canvas,
  }) => {
    await canvas.addComponent({ id: 'sdc.canvas_test_sdc.banner' });
    const fail = (route: any) =>
      route.request().method() === 'PATCH'
        ? route.fulfill({ status: 500, body: '{}' })
        : route.continue();
    await page.route('**/canvas/api/v0/layout/**', fail);

    const frame = await canvas.getActivePreviewFrame();
    await doubleClickText(page, frame, 'article h2');
    await expect(editingHost(frame)).toHaveCount(1);
    await page.keyboard.press('End');
    await page.keyboard.type(' later');
    await page.keyboard.press('Enter');

    const notice = page.locator('[data-canvas-inline-notice="error"]');
    await expect(notice).toContainText('could not be saved');
    // The preview shows what was saved, not the failed edit.
    await expect(frame.locator('article h2')).toHaveText('My banner title');

    await page.unroute('**/canvas/api/v0/layout/**', fail);
    await notice.getByRole('button', { name: 'Try again' }).click();
    await expect(
      (await canvas.getActivePreviewFrame()).locator('article h2'),
    ).toHaveText('My banner title later');
  });

  test.describe('in code components', () => {
    test.use({
      modules: ['canvas_test_sdc', 'canvas_children_slot_component'],
    });

    test('edits formatted text a code component renders', async ({
      page,
      canvas,
    }) => {
      await canvas.addComponent({ id: 'js.hero' });
      await expect(
        page.locator(
          `${contextualForm} .field--name-description .ck-editor__editable`,
        ),
      ).toBeVisible();
      const frame = await canvas.getActivePreviewFrame();
      // No markers, and no attribute: found by its text after hydration.
      await doubleClickText(page, frame, 'text=A normal text description');
      await expect(editingHost(frame)).toHaveCount(1);
      await expect(
        page
          .locator('[data-canvas-inline-text-toolbar]')
          .getByRole('button', { name: 'Bold' }),
      ).toBeVisible();
      await page.keyboard.press('ControlOrMeta+End');
      await page.keyboard.type(' in place');
      await page.keyboard.press('Escape');

      await expect(editingHost(frame)).toHaveCount(0);
      await expect(
        (await canvas.getActivePreviewFrame()).getByText(
          'A normal text description in place',
        ),
      ).toBeVisible();
    });
  });
});
