import { describe, expect, it } from 'vitest';

import {
  findPreviewDropZoneId,
  freezeDropTargets,
  unfreezeDropTargets,
} from '@/features/layout/previewOverlay/previewDropTarget';

type Box = [left: number, top: number, width: number, height: number];

function el(
  attrs: Record<string, string>,
  box: Box,
  children: HTMLElement[] = [],
) {
  const node = document.createElement('div');
  Object.entries(attrs).forEach(([k, v]) => node.setAttribute(k, v));
  const [left, top, width, height] = box;
  node.getBoundingClientRect = () =>
    ({
      left,
      top,
      width,
      height,
      right: left + width,
      bottom: top + height,
    }) as DOMRect;
  node.append(...children);
  return node;
}
const item = (uuid: string, box: Box, children?: HTMLElement[]) =>
  el({ 'data-canvas-drop-item': uuid }, box, children);
const slot = (
  id: string,
  box: Box,
  children?: HTMLElement[],
  dir = 'vertical',
) =>
  el(
    { 'data-canvas-drop-container': id, 'data-canvas-stack-direction': dir },
    box,
    children,
  );

// region
// ├── a        y 0–100
// ├── c        y 100–500, its slot y 110–490 holds d (110–300) and e (300–490)
// ├── row      y 500–600, a horizontal slot holding f (x 0–200) and g (x 200–400)
// └── h        y 600–700, its slot is empty
const root = el(
  { 'data-canvas-drop-container': 'region' },
  [0, 0, 400, 1000],
  [
    item('a', [0, 0, 400, 100]),
    item(
      'c',
      [0, 100, 400, 400],
      [
        slot(
          'c-slot',
          [10, 110, 380, 380],
          [item('d', [10, 110, 380, 190]), item('e', [10, 300, 380, 190])],
        ),
      ],
    ),
    item(
      'row',
      [0, 500, 400, 100],
      [
        slot(
          'row-slot',
          [0, 500, 400, 100],
          [item('f', [0, 500, 200, 100]), item('g', [200, 500, 200, 100])],
          'horizontal-flex',
        ),
      ],
    ),
    item('h', [0, 600, 400, 100], [slot('h-slot', [20, 620, 360, 60])]),
  ],
);

const find = (x: number, y: number, active = 'new') =>
  findPreviewDropZoneId(root, { x, y }, active);

describe('findPreviewDropZoneId', () => {
  it('picks the gap nearest to the pointer, without dead zones', () => {
    expect(find(200, 30)).toBe('a_top');
    expect(find(200, 70)).toBe('a_bottom');
    // Below the last component.
    expect(find(200, 950)).toBe('h_bottom');
  });

  it('descends into slots, except near the container edges', () => {
    expect(find(200, 250)).toBe('d_bottom');
    expect(find(200, 150)).toBe('d_top');
    // The middle of a tall container resolves to its slot's children.
    expect(find(200, 320)).toBe('d_bottom');
    // Inside the container's edge band: beside the container.
    expect(find(200, 104)).toBe('a_bottom');
    expect(find(200, 496)).toBe('c_bottom');
  });

  it('targets the nearest slot inside the bounding box of the slots of a component', () => {
    // A header with a slot at each end, between two other components.
    const page = slot(
      'page',
      [0, 0, 400, 280],
      [
        item('top', [0, 0, 400, 100]),
        item(
          'header',
          [0, 100, 400, 80],
          [
            slot(
              'branding',
              [20, 116, 100, 48],
              [item('logo', [20, 116, 100, 48])],
              'horizontal-flex',
            ),
            slot(
              'navigation',
              [200, 116, 180, 48],
              [item('nav', [200, 116, 180, 48])],
              'horizontal-flex',
            ),
            // Collapsed in the preview.
            slot('collapsed', [20, 170, 100, 0]),
          ],
        ),
        item('bottom', [0, 180, 400, 100]),
      ],
    );
    const at = (x: number, y: number, active = 'new') =>
      findPreviewDropZoneId(page, { x, y }, active);
    expect(at(150, 140)).toBe('logo_right');
    expect(at(170, 140)).toBe('nav_left');
    // The slot the dragged component is alone in is where it was.
    expect(at(150, 140, 'logo')).toBeNull();
    expect(at(170, 140, 'logo')).toBe('nav_left');
    // Outside the slots: beside the header.
    expect(at(150, 114)).toBe('top_bottom');
    expect(at(150, 166)).toBe('header_bottom');
    expect(at(5, 130)).toBe('top_bottom');
    expect(at(395, 150)).toBe('header_bottom');

    // Columns of unequal height: slots are as tall as their content. They
    // overlap by less than a pixel.
    const columns = slot(
      'page',
      [0, 0, 400, 400],
      [
        item(
          'columns',
          [0, 0, 400, 400],
          [
            slot(
              'short',
              [0, 20, 210.5, 100],
              [item('s', [0, 20, 210.5, 100])],
            ),
            slot(
              'tall',
              [210, 20, 190, 360],
              [
                item('t1', [210, 20, 190, 180]),
                item('t2', [210, 200, 190, 180]),
              ],
            ),
          ],
        ),
      ],
    );
    // Below the short column, nearer to the tall one.
    expect(findPreviewDropZoneId(columns, { x: 180, y: 300 }, 'new')).toBe(
      's_bottom',
    );
    expect(findPreviewDropZoneId(columns, { x: 180, y: 300 }, 's')).toBeNull();

    // A rail in a row, with a narrow slot below a wide one.
    const row = slot(
      'row',
      [0, 0, 300, 400],
      [
        item('left', [0, 0, 100, 400]),
        item(
          'rail',
          [100, 0, 100, 400],
          [
            slot('upper', [110, 20, 80, 100], [item('u', [110, 20, 80, 100])]),
            slot(
              'lower',
              [110, 140, 30, 240],
              [item('l', [110, 140, 30, 240])],
            ),
          ],
        ),
        item('right', [200, 0, 100, 400]),
      ],
      'horizontal-flex',
    );
    const inRow = (x: number, y: number) =>
      findPreviewDropZoneId(row, { x, y }, 'new');
    expect(inRow(150, 125)).toBe('u_bottom');
    expect(inRow(150, 135)).toBe('l_top');
    // Beside the narrow slot, nearer to the wide one.
    expect(inRow(185, 150)).toBe('l_top');
    // Outside the slots: beside the rail.
    expect(inRow(150, 10)).toBe('left_right');
    expect(inRow(195, 200)).toBe('rail_right');

    // Slots that are neither all side by side nor all above each other.
    const corners = slot(
      'page',
      [0, 0, 400, 400],
      [
        item(
          'corners',
          [0, 0, 400, 400],
          [
            slot('first', [20, 20, 100, 100]),
            slot('last', [280, 280, 100, 100]),
          ],
        ),
      ],
    );
    // Level with the last slot, and horizontally nearer to the first.
    expect(findPreviewDropZoneId(corners, { x: 190, y: 330 }, 'new')).toBe(
      'last',
    );
  });

  it('targets the gap next to a component outside its slots', () => {
    // A grid of cards with an image above their slot.
    const card = (id: string, x: number, y: number) =>
      item(
        id,
        [x, y, 200, 200],
        [
          slot(
            `${id}-slot`,
            [x + 10, y + 110, 180, 80],
            [item(`${id}-text`, [x + 10, y + 110, 180, 80])],
          ),
        ],
      );
    const grid = slot(
      'grid',
      [0, 0, 400, 400],
      [
        card('a', 0, 0),
        card('b', 200, 0),
        card('c', 0, 200),
        card('d', 200, 200),
      ],
      'horizontal-grid',
    );
    expect(findPreviewDropZoneId(grid, { x: 300, y: 50 }, 'a')).toBe('b_right');
    expect(findPreviewDropZoneId(grid, { x: 300, y: 150 }, 'a')).toBe(
      'b-text_top',
    );
  });

  it('follows the horizontal stack direction', () => {
    expect(find(50, 550)).toBe('f_left');
    expect(find(150, 550)).toBe('f_right');
    expect(find(350, 550)).toBe('g_right');
  });

  it('targets empty slots by their id', () => {
    expect(find(200, 650)).toBe('h-slot');
  });

  it('returns null where a drop would not move the dragged component', () => {
    expect(find(200, 350, 'd')).toBeNull();
    // Over d itself.
    expect(find(200, 200, 'd')).toBeNull();
    expect(find(200, 450, 'd')).toBe('e_bottom');
  });

  it('resolves against the layout at the start of the drag', () => {
    const list = slot(
      'list',
      [0, 0, 400, 500],
      [item('t', [0, 0, 400, 400]), item('s', [0, 400, 400, 100])],
    );
    const viewport = el({}, [0, 0, 400, 500], [list]);
    freezeDropTargets(viewport);
    // Showing t after s moves s up, and t under the pointer. Over where t
    // is shown, it would otherwise move back.
    const [t, s] = Array.from(list.children) as HTMLElement[];
    s.getBoundingClientRect = () => new DOMRect(0, 0, 400, 100);
    t.getBoundingClientRect = () => new DOMRect(0, 100, 400, 400);
    expect(findPreviewDropZoneId(list, { x: 200, y: 460 }, 't')).toBe(
      's_bottom',
    );
    // Scrolling moves the frozen layout with the page.
    viewport.getBoundingClientRect = () => new DOMRect(0, -100, 400, 500);
    expect(findPreviewDropZoneId(list, { x: 200, y: 360 }, 't')).toBe(
      's_bottom',
    );
    unfreezeDropTargets();
    expect(findPreviewDropZoneId(list, { x: 200, y: 460 }, 't')).toBeNull();
  });

  it('moves the dragged component into the cell under the pointer in grids', () => {
    // A grid that wraps after two cards.
    const grid = slot(
      'grid',
      [0, 0, 400, 400],
      [
        item('a', [0, 0, 200, 200]),
        item('b', [200, 0, 200, 200]),
        item('c', [0, 200, 200, 200]),
        item('d', [200, 200, 200, 200]),
      ],
      'horizontal-grid',
    );
    const at = (x: number, y: number, active: string) =>
      findPreviewDropZoneId(grid, { x, y }, active);
    // Also from the first half of a cell.
    expect(at(220, 100, 'a')).toBe('b_right');
    expect(at(220, 300, 'a')).toBe('d_right');
    expect(at(20, 300, 'a')).toBe('c_right');
    expect(at(380, 100, 'd')).toBe('a_right');
    expect(at(180, 100, 'd')).toBe('a_left');
    // Not a grid: before or after the component under the pointer.
    expect(find(150, 550, 'g')).toBeNull();
    expect(find(50, 550, 'g')).toBe('f_left');
  });

  it("doesn't take a single column of narrow components for a grid", () => {
    // A narrow centered component above narrow left-aligned ones.
    const column = slot(
      'column',
      [0, 0, 400, 300],
      [
        item('a', [150, 0, 100, 100]),
        item('b', [0, 100, 100, 100]),
        item('c', [0, 200, 100, 100]),
      ],
    );
    // The second half of b is after b, where c already is.
    expect(findPreviewDropZoneId(column, { x: 50, y: 180 }, 'c')).toBeNull();
  });

  it('changes the drop target only when the pointer is clearly past it', () => {
    const viewport = el({}, [0, 0, 400, 1000], [root]);
    freezeDropTargets(viewport);
    // The boundary between before and after a is at y 50.
    expect(find(200, 45)).toBe('a_top');
    expect(find(200, 55)).toBe('a_top');
    expect(find(200, 70)).toBe('a_bottom');
    expect(find(200, 45)).toBe('a_bottom');
    expect(find(200, 30)).toBe('a_top');
    unfreezeDropTargets();
  });
});
