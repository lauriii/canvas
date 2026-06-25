import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

import type { Page } from '@playwright/test';

type PendingResponse = {
  data?: Record<string, unknown>;
  errors?: Array<{
    source?: { pointer?: string };
  }>;
};

const unmatchedPublishErrorMessage =
  'An item in the publish request did not match the expected format or value. Please refresh your page and try again.';

const pendingPointer = (canvasPageId: number) =>
  `canvas_page:${canvasPageId}:en`;

const getPendingChanges = async (page: Page): Promise<PendingResponse> => {
  const response = await page.request.get('/canvas/api/v0/auto-saves/pending');
  expect([200, 409]).toContain(response.status());
  return response.json();
};

const waitForPendingChange = async (page: Page, canvasPageId: number) => {
  const pointer = pendingPointer(canvasPageId);
  await expect
    .poll(async () => {
      const response = await getPendingChanges(page);
      return Object.hasOwn(response.data ?? {}, pointer);
    })
    .toBe(true);
};

const waitForConflict = async (page: Page, canvasPageId: number) => {
  const pointer = pendingPointer(canvasPageId);
  await expect
    .poll(async () => {
      const response = await getPendingChanges(page);
      return response.errors?.some(
        (error) => error.source?.pointer === pointer,
      );
    })
    .toBe(true);
};

const waitForPendingChangeWithoutConflict = async (
  page: Page,
  canvasPageId: number,
) => {
  const pointer = pendingPointer(canvasPageId);
  await expect
    .poll(async () => {
      const response = await page.request.get(
        '/canvas/api/v0/auto-saves/pending',
      );
      const body = (await response.json()) as PendingResponse;

      return {
        status: response.status(),
        hasPendingChange: Object.hasOwn(body.data ?? {}, pointer),
        hasConflict:
          body.errors?.some((error) => error.source?.pointer === pointer) ??
          false,
      };
    })
    .toEqual({
      status: 200,
      hasPendingChange: true,
      hasConflict: false,
    });
};

const updateCanvasPageTitleOutsideAutoSave = async (
  page: Page,
  canvasPageId: number,
  title: string,
) => {
  const pageResponse = await page.request.get(
    `/canvas/api/v0/content/canvas_page/${canvasPageId}`,
  );
  expect(pageResponse.ok()).toBe(true);
  const pageData = await pageResponse.json();

  const csrfResponse = await page.request.get('/session/token');
  expect(csrfResponse.ok()).toBe(true);

  const response = await page.evaluate(
    async ({ canvasPageId, csrfToken, data }) => {
      const result = await fetch(
        `/canvas/api/v0/content/canvas_page/${canvasPageId}`,
        {
          method: 'PATCH',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken,
          },
          body: JSON.stringify(data),
        },
      );

      return {
        ok: result.ok,
        status: result.status,
        error: result.ok ? '' : await result.text(),
      };
    },
    {
      canvasPageId,
      csrfToken: await csrfResponse.text(),
      data: {
        title,
        status: pageData.status,
        path: pageData.path,
        components: pageData.components,
      },
    },
  );
  expect(
    response.ok,
    `Canvas page update failed (${response.status}): ${response.error}`,
  ).toBe(true);
};

test.describe('Conflict UX enabled', () => {
  test.use({
    modules: ['canvas_dev_cd'],
    enableTestExtensions: true,
  });

  test('shows review-list conflict controls', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });

    const canvasPage = await canvas.createCanvas({
      title: 'Conflict resolution page',
    });
    await waitForPendingChange(page, canvasPage.entity_id);
    await updateCanvasPageTitleOutsideAutoSave(
      page,
      canvasPage.entity_id,
      'Externally updated conflict resolution page',
    );
    await waitForConflict(page, canvasPage.entity_id);

    await page.getByTestId('canvas-publish-review').click();
    const review = page.getByTestId('canvas-publish-reviews-content');

    await expect(review.getByTestId('conflict-banner')).toContainText(
      '1 conflict to resolve',
    );
    await expect(review.getByText('0 of 1 changes selected')).toBeVisible();
    await expect(
      review.getByLabel('Select change Conflict resolution page'),
    ).toBeDisabled();
    await expect(
      review.getByTestId('canvas-publish-review-select-all'),
    ).toBeDisabled();
    await expect(review.getByTestId('change-conflict-icon')).toHaveCount(1);

    const conflictRow = review
      .getByTestId('pending-change-row')
      .filter({ hasText: 'Conflict resolution page' });
    await conflictRow.getByRole('button', { name: 'More options' }).click();
    await expect(
      page.getByRole('menuitem', { name: 'Resolve conflict' }),
    ).toBeVisible();
  });

  test('auto-unselects a selected page when it becomes conflicted', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });

    const canvasPage = await canvas.createCanvas({ title: 'Selectable page' });
    await waitForPendingChange(page, canvasPage.entity_id);

    const queuedConflictPage = await canvas.createCanvas({
      title: 'Queued conflict page',
    });
    await waitForPendingChange(page, queuedConflictPage.entity_id);
    await updateCanvasPageTitleOutsideAutoSave(
      page,
      queuedConflictPage.entity_id,
      'Externally updated queued conflict page',
    );
    await waitForConflict(page, queuedConflictPage.entity_id);

    await canvas.openCanvas(canvasPage);
    await page.getByTestId('canvas-publish-review').click();
    const review = page.getByTestId('canvas-publish-reviews-content');

    await review.getByLabel('Select change Selectable page').click();
    await expect(review.getByText('1 of 2 changes selected')).toBeVisible();

    await updateCanvasPageTitleOutsideAutoSave(
      page,
      canvasPage.entity_id,
      'Externally updated selectable page',
    );
    await waitForConflict(page, canvasPage.entity_id);

    await page.keyboard.press('Escape');
    await page.getByTestId('canvas-publish-review').click();

    await expect(review.getByTestId('conflict-banner')).toContainText(
      '2 conflicts to resolve',
    );
    await expect(review.getByText('0 of 2 changes selected')).toBeVisible();
    await expect(
      review.getByLabel('Select change Selectable page'),
    ).toBeDisabled();
    await expect(
      review.getByTestId('canvas-publish-review-select-all'),
    ).toBeDisabled();
    await expect(review.getByTestId('change-conflict-icon')).toHaveCount(2);
  });
});

test.describe('Conflict UX disabled', () => {
  test.use({
    enableTestExtensions: true,
  });

  test('treats changes as normal review rows when conflict detection is disabled', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });

    const canvasPage = await canvas.createCanvas({ title: 'Flag off page' });
    await waitForPendingChange(page, canvasPage.entity_id);
    await updateCanvasPageTitleOutsideAutoSave(
      page,
      canvasPage.entity_id,
      'Externally updated flag off page',
    );
    // @todo Invert this again when conflict detection is no longer hidden
    //   behind canvas_dev_cd.
    //   https://git.drupalcode.org/project/canvas/-/work_items/3591668
    await waitForPendingChangeWithoutConflict(page, canvasPage.entity_id);

    await page.getByTestId('canvas-publish-review').click();
    const review = page.getByTestId('canvas-publish-reviews-content');

    await expect(review.getByTestId('conflict-banner')).toBeHidden();
    await expect(review.getByTestId('change-conflict-icon')).toBeHidden();
    await expect(
      review.getByLabel('Select change Flag off page'),
    ).toBeEnabled();

    await review.getByTestId('canvas-publish-review-select-all').click();
    await expect(review.getByText('1 of 1 changes selected')).toBeVisible();
  });

  test('shows legacy publish conflict errors without conflict detection enabled', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });

    const canvasPage = await canvas.createCanvas({
      title: 'Legacy publish conflict page',
    });
    const pointer = pendingPointer(canvasPage.entity_id);
    await waitForPendingChange(page, canvasPage.entity_id);
    await waitForPendingChangeWithoutConflict(page, canvasPage.entity_id);

    let publishBody: Record<string, unknown> | undefined;
    await page.route('**/canvas/api/v0/auto-saves/publish', async (route) => {
      publishBody = route.request().postDataJSON() as Record<string, unknown>;
      await route.fulfill({
        status: 409,
        contentType: 'application/json',
        body: JSON.stringify({
          errors: [
            {
              detail: unmatchedPublishErrorMessage,
              source: {
                pointer,
              },
              code: 2,
              meta: {
                entity_type: 'canvas_page',
                entity_id: canvasPage.entity_id,
                label: 'Legacy publish conflict page',
                api_auto_save_key: pointer,
              },
            },
          ],
        }),
      });
    });

    await page.getByTestId('canvas-publish-review').click();
    const review = page.getByTestId('canvas-publish-reviews-content');

    await review.getByTestId('canvas-publish-review-select-all').click();
    await expect(review.getByText('1 of 1 changes selected')).toBeVisible();

    const publishResponse = page.waitForResponse(
      (response) =>
        response.url().includes('/canvas/api/v0/auto-saves/publish') &&
        response.request().method() === 'POST',
    );
    await review.getByRole('button', { name: 'Publish 1 selected' }).click();

    expect((await publishResponse).status()).toBe(409);
    expect(publishBody).toBeDefined();
    expect(Object.hasOwn(publishBody ?? {}, pointer)).toBe(true);

    await expect(
      review.getByTestId('canvas-review-publish-errors'),
    ).toContainText(unmatchedPublishErrorMessage);
    await expect(
      review.getByTestId('canvas-review-publish-errors'),
    ).toContainText('Legacy publish conflict page');
    await expect(review.getByTestId('conflict-banner')).toBeHidden();
    await expect(review.getByTestId('change-conflict-icon')).toBeHidden();
    await expect(
      review.getByRole('menuitem', { name: 'Resolve conflict' }),
    ).toBeHidden();
  });
});
