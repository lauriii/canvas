import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

test.use({ modules: ['canvas_test_sdc'], enableTestExtensions: true });

// Keep in sync with the DRAG_AUTOSCROLL_* constants in
// ui/src/features/editorFrame/EditorFrame.tsx.
const EDGE_PX = 60;

// Behavior + regression test for the editor-canvas drag auto-scroll.
// The editor pane is a large scroll container. dnd-kit's built-in auto-scroll
// scrolled it while the pointer was still far from the edge, inconsistently, and
// on both axes. The pane now runs its own vertical-only auto-scroll: while
// dragging a component, holding (dwelling) near the top or bottom edge scrolls the
// canvas up or down so you can place a component above/below the fold — but
// sweeping past does nothing, the interior does nothing, and there is no
// horizontal auto-scroll (which was the original bug).
test.describe('Drag auto-scroll edge band', () => {
  test('auto-scrolls near the top/bottom edges only, not horizontally or in the interior', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.openCanvas(await canvas.createCanvas());
    await canvas.openLibraryPanel();

    // Add a component so the drag has a realistic canvas to scroll over.
    await canvas.addComponent({ id: 'sdc.canvas_test_sdc.my-hero' });
    await canvas.waitForContextualPanel();

    const pane = page.locator('[class*="editorPane"]').first();
    await expect(pane).toBeVisible();

    const readGeometry = () =>
      pane.evaluate((el) => {
        const r = el.getBoundingClientRect();
        return {
          left: r.left,
          top: r.top,
          right: r.right,
          bottom: r.bottom,
          maxScrollLeft: el.scrollWidth - el.clientWidth,
          maxScrollTop: el.scrollHeight - el.clientHeight,
        };
      });

    const setScroll = (left: number, top: number) =>
      pane.evaluate(
        (el, s) => {
          el.scrollLeft = s.left;
          el.scrollTop = s.top;
        },
        { left, top },
      );

    const readScroll = () =>
      pane.evaluate((el) => ({ left: el.scrollLeft, top: el.scrollTop }));

    // Center the scroll so there is room to move up or down.
    const g = await readGeometry();
    const centerLeft = Math.round(g.maxScrollLeft / 2);
    const centerTop = Math.round(g.maxScrollTop / 2);
    await setScroll(centerLeft, centerTop);

    // The pane must be vertically scrollable for the assertions to be meaningful.
    expect(g.maxScrollTop, 'pane is vertically scrollable').toBeGreaterThan(
      2 * EDGE_PX,
    );
    expect(g.maxScrollLeft, 'pane is horizontally scrollable').toBeGreaterThan(
      2 * EDGE_PX,
    );

    const midX = Math.round((g.left + g.right) / 2);
    const midY = Math.round((g.top + g.bottom) / 2);

    const source = page
      .locator(
        '[data-testid="canvas-primary-panel"] [data-canvas-type="component"]',
      )
      .first();

    // Pick up a library component, move into the pane's interior to arm the
    // auto-scroll, then hold at (targetX, targetY) for `holdMs` and return how far
    // the pane scrolled. The auto-scroll only engages after a short dwell in an
    // edge strip, so the vertical-edge holds must be well over that dwell.
    const dragAndMeasure = async (
      targetX: number,
      targetY: number,
      holdMs: number,
    ) => {
      await setScroll(centerLeft, centerTop);
      // force: true because the drag overlay can cover elements during the drag.
      // eslint-disable-next-line playwright/no-force-option
      await source.hover({ force: true });
      await page.mouse.down();
      await page.mouse.move(midX, midY, { steps: 10 });
      await setScroll(centerLeft, centerTop);
      const before = await readScroll();

      await page.mouse.move(targetX, targetY, { steps: 10 });
      // The auto-scroll runs on its own animation-frame loop while the pointer is
      // held; there is no DOM event to await, so a real wait is required here.
      // eslint-disable-next-line playwright/no-wait-for-timeout
      await page.waitForTimeout(holdMs);

      const after = await readScroll();
      await page.mouse.up();
      return { dx: after.left - before.left, dy: after.top - before.top };
    };

    // Holding within the TOP strip scrolls up (past the dwell + ramp).
    const top = await dragAndMeasure(
      midX,
      Math.round(g.top + EDGE_PX * 0.35),
      1200,
    );
    expect(
      top.dy,
      `near-top drag scrolled vertically by ${top.dy}px (expected a clear negative)`,
    ).toBeLessThan(-15);

    // Holding within the BOTTOM strip scrolls down.
    const bottom = await dragAndMeasure(
      midX,
      Math.round(g.bottom - EDGE_PX * 0.35),
      1200,
    );
    expect(
      bottom.dy,
      `near-bottom drag scrolled vertically by ${bottom.dy}px (expected a clear positive)`,
    ).toBeGreaterThan(15);

    // The interior does not scroll, even held well past the dwell.
    const interior = await dragAndMeasure(
      Math.round(g.left + EDGE_PX * 2),
      midY,
      800,
    );
    expect(
      Math.abs(interior.dy),
      `interior drag scrolled vertically by ${interior.dy}px`,
    ).toBeLessThan(5);
    expect(
      Math.abs(interior.dx),
      `interior drag scrolled horizontally by ${interior.dx}px`,
    ).toBeLessThan(5);

    // There is no horizontal auto-scroll: holding near the left or right edge
    // (at mid height, so it isn't in a vertical strip) does not scroll.
    const nearLeft = await dragAndMeasure(
      Math.round(g.left + EDGE_PX * 0.35),
      midY,
      800,
    );
    expect(
      Math.abs(nearLeft.dx),
      `near-left drag scrolled horizontally by ${nearLeft.dx}px (expected ~0)`,
    ).toBeLessThan(5);

    const nearRight = await dragAndMeasure(
      Math.round(g.right - EDGE_PX * 0.35),
      midY,
      800,
    );
    expect(
      Math.abs(nearRight.dx),
      `near-right drag scrolled horizontally by ${nearRight.dx}px (expected ~0)`,
    ).toBeLessThan(5);
  });
});
