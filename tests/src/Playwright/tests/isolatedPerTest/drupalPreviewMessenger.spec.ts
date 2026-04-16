import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

import type { Response } from '@playwright/test';

/** Must match \\Drupal\\canvas_test_preview_messenger\\EventSubscriber\\PreviewMessengerTestSubscriber::HEADER_NAME */
const PREVIEW_MESSAGE_TEST_HEADER = 'X-Canvas-Test-Preview-Message';

/** Must match \\Drupal\\canvas_test_preview_messenger\\EventSubscriber\\PreviewMessengerTestSubscriber::QUERY_PARAM */
const PREVIEW_MESSAGE_TEST_QUERY_PARAM = 'canvas_test_preview_message';

/** Must match \\Drupal\\canvas_test_preview_messenger\\EventSubscriber\\PreviewMessengerTestSubscriber::PROBE_MESSAGE */
const PROBE_MESSAGE = 'Playwright preview messenger probe.';

const LAYOUT_API_GLOB = '**/canvas/api/v0/layout/**';

test.describe('Drupal preview messenger (E2E)', () => {
  test.beforeEach(async ({ drupal, page }) => {
    await drupal.enableTestExtensions();
    await drupal.loginAsAdmin();
    await drupal.installModules([
      'canvas_test_sdc',
      'canvas_test_preview_messenger',
    ]);
    // New test modules may not appear in the extension list until caches are cleared.
    await drupal.clearCache();
    await drupal.logout();

    await page.route(LAYOUT_API_GLOB, async (route) => {
      if (route.request().method() !== 'GET') {
        await route.continue();
        return;
      }
      const req = route.request();
      const url = new URL(req.url());
      if (url.searchParams.get(PREVIEW_MESSAGE_TEST_QUERY_PARAM) !== '1') {
        url.searchParams.set(PREVIEW_MESSAGE_TEST_QUERY_PARAM, '1');
      }
      const headers = { ...req.headers() };
      headers[PREVIEW_MESSAGE_TEST_HEADER] = '1';
      await route.continue({ url: url.toString(), headers });
    });
  });

  test.afterEach(async ({ page }) => {
    await page.unroute(LAYOUT_API_GLOB);
  });

  test('layout GET messages appear as editor shell toast', async ({
    drupal,
    canvas,
    page,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });

    const layoutResponsePredicate = (response: Response) => {
      const req = response.request();
      if (req.method() !== 'GET' || !response.ok()) {
        return false;
      }
      const url = req.url();
      if (!url.includes('/canvas/api/v0/layout/')) {
        return false;
      }
      // Ignore un-gated layout GETs; the route handler adds this query for probes.
      try {
        return (
          new URL(url).searchParams.get(PREVIEW_MESSAGE_TEST_QUERY_PARAM) ===
          '1'
        );
      } catch {
        return false;
      }
    };

    // Resolve the gated layout response during createCanvas, then read JSON before
    // any later full navigation drops the body from CDP
    // (Network.getResponseBody: No resource with given identifier).
    const [layoutResponse] = await Promise.all([
      page.waitForResponse(layoutResponsePredicate),
      canvas.createCanvas(),
    ]);
    const layoutJson: { messages?: Array<{ type: string; message: string }> } =
      await layoutResponse.json();
    expect(layoutJson.messages?.length).toBeGreaterThan(0);
    expect(
      layoutJson.messages?.some((m) => m.message.includes(PROBE_MESSAGE)),
    ).toBe(true);

    // DrupalPreviewMessageToaster exposes a region with data-testid for tests.
    await expect(
      page.getByTestId('drupal-preview-messages').getByText(PROBE_MESSAGE),
    ).toBeVisible({ timeout: 20_000 });
  });
});
