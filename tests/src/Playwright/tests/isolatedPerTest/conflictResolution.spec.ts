import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

import type { Page } from '@playwright/test';

type PendingResponse = {
  data?: Record<string, { data_hash?: string; label?: string }>;
  errors?: Array<{
    source?: { pointer?: string };
    meta?: { conflict_id?: string };
  }>;
};

const unmatchedPublishErrorMessage =
  'An item in the publish request did not match the expected format or value. Please refresh your page and try again.';

const pendingPointer = (canvasPageId: string | number) =>
  `canvas_page:${canvasPageId}:en`;

const getPendingChanges = async (page: Page): Promise<PendingResponse> => {
  const response = await page.request.get('/canvas/api/v0/auto-saves/pending');
  expect([200, 409]).toContain(response.status());
  return response.json();
};

const waitForPendingChange = async (
  page: Page,
  canvasPageId: number,
  expectedLabel?: string,
) => {
  const pointer = pendingPointer(canvasPageId);
  await expect
    .poll(async () => {
      const response = await getPendingChanges(page);
      const pendingChange = response.data?.[pointer];
      return (
        !!pendingChange &&
        (!expectedLabel || pendingChange.label === expectedLabel)
      );
    })
    .toBe(true);
};

const waitForConflict = async (
  page: Page,
  canvasPageId: number,
): Promise<string> => {
  const pointer = pendingPointer(canvasPageId);
  let conflictId: string | undefined;

  await expect
    .poll(
      async () => {
        const response = await getPendingChanges(page);
        conflictId = response.errors?.find(
          (error) => error.source?.pointer === pointer,
        )?.meta?.conflict_id;
        return conflictId;
      },
      { timeout: 30_000 },
    )
    .toBeTruthy();

  if (!conflictId) {
    throw new Error(`No conflict ID found for ${pointer}`);
  }
  return conflictId;
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

const updateCanvasPageAutoSaveStatus = async (
  page: Page,
  canvasPageId: number,
  status: boolean,
) => {
  const csrfResponse = await page.request.get('/session/token');
  expect(csrfResponse.ok()).toBe(true);

  const response = await page.evaluate(
    async ({ canvasPageId, csrfToken, status }) => {
      const result = await fetch(
        `/canvas/api/v0/content/auto-save/canvas_page/${canvasPageId}`,
        {
          method: 'PATCH',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken,
          },
          body: JSON.stringify({ status }),
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
      status,
    },
  );
  expect(
    response.ok,
    `Canvas page auto-save status update failed (${response.status}): ${response.error}`,
  ).toBe(true);
};

const changeCanvasPageAutoSaveHash = async (
  page: Page,
  canvasPageId: number,
) => {
  const pointer = pendingPointer(canvasPageId);
  const before = (await getPendingChanges(page)).data?.[pointer]?.data_hash;

  for (const status of [false, true]) {
    await updateCanvasPageAutoSaveStatus(page, canvasPageId, status);
    const after = (await getPendingChanges(page)).data?.[pointer]?.data_hash;
    if (after && after !== before) {
      return;
    }
  }

  throw new Error(`Auto-save hash did not change for ${pointer}`);
};

const openPublishReview = async (page: Page) => {
  const trigger = page.getByTestId('canvas-publish-review');
  await expect(trigger).toBeVisible();
  await expect(trigger).toBeEnabled();

  const review = page.getByTestId('canvas-publish-reviews-content');
  if (await review.isVisible()) {
    return review;
  }

  for (let attempt = 0; attempt < 2; attempt++) {
    await trigger.click();
    try {
      await expect(review).toBeVisible({ timeout: 5000 });
      return review;
    } catch (error) {
      if (attempt === 1) {
        throw error;
      }
    }
  }
  return review;
};

test.describe('Conflict UX enabled', () => {
  test.use({
    modules: ['canvas_dev_cd'],
    enableTestExtensions: true,
  });

  test('shows review-list conflict controls and opens the resolver', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });

    const canvasPage = await canvas.createCanvas({
      title: 'Conflict resolution page',
    });
    const currentEntityId = String(canvasPage.entity_id);
    await waitForPendingChange(
      page,
      canvasPage.entity_id,
      'Conflict resolution page',
    );
    await updateCanvasPageTitleOutsideAutoSave(
      page,
      canvasPage.entity_id,
      'Externally updated conflict resolution page',
    );
    await waitForConflict(page, canvasPage.entity_id);

    const review = await openPublishReview(page);

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
    const resolveMenuItem = page.getByRole('menuitem', {
      name: 'Resolve conflict',
    });
    await expect(resolveMenuItem).toBeVisible();
    await resolveMenuItem.click();

    await expect(page).toHaveURL(
      new RegExp(`/canvas/conflict/canvas_page/${currentEntityId}$`),
    );
    await expect(review).toBeHidden();
    await expect(page.getByTestId('conflict-resolution-page')).toBeVisible();
  });

  test('resolves queued conflicts from the side-by-side comparison page', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });

    const queuedConflictPage = await canvas.createCanvas({
      title: 'Queued conflict page',
    });
    await waitForPendingChange(
      page,
      queuedConflictPage.entity_id,
      'Queued conflict page',
    );

    const canvasPage = await canvas.createCanvas({
      title: 'Side by side conflict page',
    });
    const currentEntityId = String(canvasPage.entity_id);
    const queuedEntityId = String(queuedConflictPage.entity_id);
    await waitForPendingChange(
      page,
      canvasPage.entity_id,
      'Side by side conflict page',
    );

    await updateCanvasPageTitleOutsideAutoSave(
      page,
      queuedConflictPage.entity_id,
      'Externally updated queued conflict page',
    );
    await waitForConflict(page, queuedConflictPage.entity_id);
    await updateCanvasPageTitleOutsideAutoSave(
      page,
      canvasPage.entity_id,
      'Externally updated side by side conflict page',
    );
    const currentConflictId = await waitForConflict(page, canvasPage.entity_id);

    const review = await openPublishReview(page);

    await expect(review.getByTestId('conflict-banner')).toContainText(
      '2 conflicts to resolve',
    );

    await review.getByTestId('resolve-conflicts-button').click();

    await expect(page).toHaveURL(
      new RegExp(`/canvas/conflict/canvas_page/${currentEntityId}$`),
    );
    await expect(review).toBeHidden();

    const resolver = page.getByTestId('conflict-resolution-page');
    const resolveConflictButton = resolver.getByRole('button', {
      name: 'Resolve conflict',
      exact: true,
    });

    await expect(resolver).toBeVisible();
    await expect(page.getByText('Review 1 of 2')).toBeVisible();
    await expect(page.getByText('Published version').first()).toBeVisible();
    await expect(page.getByText('New version').first()).toBeVisible();
    await expect(page.getByText(/Updated .+ at .+/).first()).toBeVisible();
    await expect(resolveConflictButton).toBeDisabled();
    await expect(
      page.locator('iframe[title="Page version preview"]'),
    ).toHaveCount(2);

    // The UI state cannot prove the exact conflict revision was sent, so this
    // waits for the PATCH and asserts the resolved_conflict_id payload below.
    const resolveRequestPromise = page.waitForRequest(
      (request) =>
        request.method() === 'PATCH' &&
        new URL(request.url()).pathname.endsWith(
          `/canvas/api/v0/content/auto-save/canvas_page/${currentEntityId}`,
        ),
    );
    await page.getByRole('button', { name: 'Select New version' }).click();
    await resolveConflictButton.click();
    const resolveRequest = await resolveRequestPromise;
    expect(resolveRequest.postDataJSON()).toEqual(
      expect.objectContaining({ resolved_conflict_id: currentConflictId }),
    );

    await expect(page).toHaveURL(
      new RegExp(`/canvas/conflict/canvas_page/${queuedEntityId}$`),
    );
    await expect(page.getByText('Review 2 of 2')).toBeVisible();
    await expect(resolveConflictButton).toBeDisabled();

    // Choosing the published version should discard the queued auto-save; wait
    // for that DELETE before asserting the resolver moves to the complete state.
    const discardRequestPromise = page.waitForRequest(
      (request) =>
        request.method() === 'DELETE' &&
        new URL(request.url()).pathname.endsWith(
          `/canvas/api/v0/auto-saves/canvas_page/${queuedEntityId}`,
        ),
    );
    await page
      .getByRole('button', { name: 'Select Published version' })
      .click();
    await resolveConflictButton.click();
    await discardRequestPromise;

    await expect(page).toHaveURL(/\/canvas\/conflict$/);
    await expect(page.getByText('Everything is up to date')).toBeVisible();
  });

  test('auto-unselects a selected page when it becomes conflicted', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });

    const canvasPage = await canvas.createCanvas({ title: 'Selectable page' });
    await waitForPendingChange(page, canvasPage.entity_id, 'Selectable page');

    await canvas.openCanvas(canvasPage);
    let review = await openPublishReview(page);

    await review.getByLabel('Select change Selectable page').click();
    await expect(review.getByText('1 of 1 changes selected')).toBeVisible();

    await updateCanvasPageTitleOutsideAutoSave(
      page,
      canvasPage.entity_id,
      'Externally updated selectable page',
    );
    await waitForConflict(page, canvasPage.entity_id);

    await review.getByLabel('Close').click();
    await expect(review).toBeHidden();
    review = await openPublishReview(page);

    await expect(review.getByTestId('conflict-banner')).toContainText(
      '1 conflict to resolve',
    );
    await expect(review.getByText('0 of 1 changes selected')).toBeVisible();
    await expect(
      review.getByLabel('Select change Selectable page'),
    ).toBeDisabled();
    await expect(
      review.getByTestId('canvas-publish-review-select-all'),
    ).toBeDisabled();
    await expect(review.getByTestId('change-conflict-icon')).toHaveCount(1);
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
    await waitForPendingChange(page, canvasPage.entity_id, 'Flag off page');
    await updateCanvasPageTitleOutsideAutoSave(
      page,
      canvasPage.entity_id,
      'Externally updated flag off page',
    );
    // @todo Invert this again when conflict detection is no longer hidden
    //   behind canvas_dev_cd.
    //   https://git.drupalcode.org/project/canvas/-/work_items/3591668
    await waitForPendingChangeWithoutConflict(page, canvasPage.entity_id);

    const review = await openPublishReview(page);

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
    await waitForPendingChange(
      page,
      canvasPage.entity_id,
      'Legacy publish conflict page',
    );
    await waitForPendingChangeWithoutConflict(page, canvasPage.entity_id);

    const review = await openPublishReview(page);

    await review.getByTestId('canvas-publish-review-select-all').click();
    await expect(review.getByText('1 of 1 changes selected')).toBeVisible();

    await changeCanvasPageAutoSaveHash(page, canvasPage.entity_id);

    // This legacy path is specifically about preserving the raw 409 publish
    // error, so the response status is part of the expected behavior.
    const publishRequestPromise = page.waitForRequest(
      (request) =>
        request.url().includes('/canvas/api/v0/auto-saves/publish') &&
        request.method() === 'POST',
    );
    const publishResponsePromise = page.waitForResponse(
      (response) =>
        response.url().includes('/canvas/api/v0/auto-saves/publish') &&
        response.request().method() === 'POST',
    );
    await review.getByRole('button', { name: 'Publish 1 selected' }).click();

    const publishRequest = await publishRequestPromise;
    expect(publishRequest.postDataJSON()).toEqual(
      expect.objectContaining({
        [pointer]: expect.any(Object),
      }),
    );
    expect((await publishResponsePromise).status()).toBe(409);

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
