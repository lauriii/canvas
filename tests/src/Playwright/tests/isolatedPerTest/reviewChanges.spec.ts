import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

import type { Page } from '@playwright/test';

type PendingResponse = {
  data?: Record<string, { data_hash?: string }>;
};

const pendingPointer = (canvasPageId: string | number) =>
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

test.describe('Review selected changes', () => {
  test.use({
    modules: ['canvas_dev_cd'],
    enableTestExtensions: true,
  });

  test('reviews selected Page versions before discarding and publishing', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });

    const pageToPublish = await canvas.createCanvas({
      title: 'Review publish page',
    });
    await waitForPendingChange(page, pageToPublish.entity_id);

    const pageToDiscard = await canvas.createCanvas({
      title: 'Review discard page',
    });
    await waitForPendingChange(page, pageToDiscard.entity_id);

    const review = await openPublishReview(page);

    await review.getByLabel('Select change Review publish page').click();
    await review.getByLabel('Select change Review discard page').click();
    await expect(review.getByText('2 of 2 changes selected')).toBeVisible();

    await review
      .getByRole('button', { name: 'Review selected changes' })
      .click();

    await expect(page).toHaveURL(
      new RegExp(`/canvas/review/canvas_page/${pageToPublish.entity_id}$`),
    );
    await expect(page.getByTestId('review-changes-page')).toBeVisible();
    await expect(page.getByText('Review 1 of 2')).toBeVisible();
    await expect(page.getByText('Old version').first()).toBeVisible();
    await expect(page.getByText('New version').first()).toBeVisible();
    await expect(
      page.getByRole('switch', { name: /Selected for publishing/ }),
    ).toHaveAttribute('aria-checked', 'true');
    await expect(
      page.getByRole('button', { name: 'Publish selected changes' }),
    ).toBeEnabled();
    await expect(
      page
        .getByTestId('conflict-published-version-card')
        .getByText('Old version'),
    ).toBeVisible();
    await expect(
      page.getByTestId('conflict-new-version-card').getByText('New version'),
    ).toBeVisible();

    await page.getByRole('button', { name: 'Next' }).click();
    await expect(page).toHaveURL(
      new RegExp(`/canvas/review/canvas_page/${pageToDiscard.entity_id}$`),
    );
    await expect(page.getByText('Review 2 of 2')).toBeVisible();
    await expect(
      page
        .getByTestId('conflict-published-version-card')
        .getByText('Old version'),
    ).toBeVisible();
    await expect(
      page.getByTestId('conflict-new-version-card').getByText('New version'),
    ).toBeVisible();

    const discardRequestPromise = page.waitForRequest(
      (request) =>
        request.method() === 'DELETE' &&
        new URL(request.url()).pathname.endsWith(
          `/canvas/api/v0/auto-saves/canvas_page/${pageToDiscard.entity_id}`,
        ),
    );
    await page.getByRole('button', { name: 'Select Old version' }).click();
    await expect(
      page.getByRole('switch', { name: /Selected for publishing/ }),
    ).toHaveAttribute('aria-checked', 'false');
    await page.getByRole('button', { name: 'Discard changes' }).click();
    await discardRequestPromise;

    await expect(page).toHaveURL(/\/canvas\/review$/);
    const completeState = page.getByTestId('review-complete-state');
    await expect(completeState).toBeVisible();
    await expect(
      completeState.getByText('All changes reviewed').last(),
    ).toBeVisible();

    const publishRequestPromise = page.waitForRequest(
      (request) =>
        request.method() === 'POST' &&
        new URL(request.url()).pathname.endsWith(
          '/canvas/api/v0/auto-saves/publish',
        ),
    );
    const publishResponsePromise = page.waitForResponse(
      (response) =>
        response.url().includes('/canvas/api/v0/auto-saves/publish') &&
        response.request().method() === 'POST',
    );
    await page
      .getByRole('button', { name: 'Publish selected changes' })
      .click();

    const publishRequest = await publishRequestPromise;
    const publishBody = publishRequest.postDataJSON() as Record<
      string,
      unknown
    >;
    expect(
      Object.hasOwn(publishBody, pendingPointer(pageToPublish.entity_id)),
    ).toBe(true);
    expect(
      Object.hasOwn(publishBody, pendingPointer(pageToDiscard.entity_id)),
    ).toBe(false);
    expect((await publishResponsePromise).ok()).toBe(true);
    await expect(page).toHaveURL(/\/canvas\/editor/);
  });
});

test.describe('Review selected changes disabled', () => {
  test.use({
    enableTestExtensions: true,
  });

  test('hides side-by-side review actions and redirects direct review visits when conflict detection is disabled', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });

    const canvasPage = await canvas.createCanvas({
      title: 'Review flag off page',
    });
    await waitForPendingChange(page, canvasPage.entity_id);

    const review = await openPublishReview(page);

    await review.getByLabel('Select change Review flag off page').click();
    await expect(review.getByText('1 of 1 changes selected')).toBeVisible();
    await expect(
      review.getByRole('button', { name: 'Review selected changes' }),
    ).toBeHidden();

    const reviewRow = review
      .getByTestId('pending-change-row')
      .filter({ hasText: 'Review flag off page' });
    await reviewRow.getByRole('button', { name: 'More options' }).click();
    await expect(
      page.getByRole('menuitem', { name: 'Review changes' }),
    ).toBeHidden();

    await page.goto(`/canvas/review/canvas_page/${canvasPage.entity_id}`);

    await expect(page).toHaveURL(/\/canvas\/editor$/);
    await expect(page.getByTestId('review-changes-page')).toBeHidden();
  });
});
