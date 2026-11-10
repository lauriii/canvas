import { describe, expect, it } from 'vitest';

import {
  findPreviewDropZoneId,
  freezeDropTargets,
  holdDropTargets,
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

  it('targets an empty slot from around it in its component, and keeps a component in the component that holds it', () => {
    // A header whose first slot is empty, between two other components.
    const page = slot(
      'page',
      [0, 0, 400, 280],
      [
        item('top', [0, 0, 400, 100]),
        item(
          'header',
          [0, 100, 400, 80],
          [
            slot('branding', [40, 116, 100, 48]),
            slot(
              'navigation',
              [160, 116, 220, 48],
              [
                item('nav', [160, 116, 110, 48]),
                item('logo', [270, 116, 110, 48]),
              ],
              'horizontal-flex',
            ),
          ],
        ),
        item('bottom', [0, 180, 400, 100]),
      ],
    );
    const at = (x: number, y: number, active = 'new') =>
      findPreviewDropZoneId(page, { x, y }, active);
    // In the padding of the header, around the empty slot.
    expect(at(20, 140)).toBe('branding');
    expect(at(145, 140)).toBe('branding');
    expect(at(60, 114)).toBe('branding');
    // Near the edge of the header, and outside it: beside the header.
    expect(at(60, 104)).toBe('top_bottom');
    expect(at(60, 90)).toBe('top_bottom');
    // Farther from the slot.
    expect(at(5, 130)).toBe('top_bottom');
    // Over the content of another slot.
    expect(at(165, 140)).toBe('nav_left');
    // The header holds the logo, which stays in the header until the pointer
    // is near the edge of the header.
    expect(at(5, 130, 'logo')).toBe('branding');
    expect(at(395, 150, 'logo')).toBeNull();
    expect(at(5, 105, 'logo')).toBe('top_bottom');

    // Grids filled edge to edge by their empty slot.
    const grids = slot(
      'page',
      [0, 0, 400, 84],
      [
        item('g1', [0, 0, 400, 42], [slot('g1-slot', [0, 1, 400, 40])]),
        item('g2', [0, 42, 400, 42], [slot('g2-slot', [0, 43, 400, 40])]),
      ],
    );
    const inGrids = (x: number, y: number) =>
      findPreviewDropZoneId(grids, { x, y }, 'new');
    expect(inGrids(200, 5)).toBe('g1_top');
    expect(inGrids(200, 20)).toBe('g1-slot');
    expect(inGrids(200, 40)).toBe('g1_bottom');
    expect(inGrids(200, 80)).toBe('g2_bottom');

    // A section whose slot holds only the dragged paragraph, between two
    // other components.
    const sections = slot(
      'page',
      [0, 0, 400, 300],
      [
        item('before', [0, 0, 400, 100]),
        item(
          'section',
          [0, 100, 400, 100],
          [
            slot(
              'section-slot',
              [16, 116, 368, 68],
              [item('p', [16, 116, 368, 68])],
            ),
          ],
        ),
        item('after', [0, 200, 400, 100]),
      ],
    );
    const inSections = (x: number, y: number) =>
      findPreviewDropZoneId(sections, { x, y }, 'p');
    expect(inSections(200, 120)).toBeNull();
    // In the padding of the section.
    expect(inSections(5, 150)).toBeNull();
    expect(inSections(200, 104)).toBe('before_bottom');
    expect(inSections(200, 196)).toBe('section_bottom');

    // A component whose only slot is collapsed holds the dragged component.
    const collapsed = slot(
      'page',
      [0, 0, 400, 300],
      [
        item('first', [0, 0, 400, 100]),
        item(
          'box',
          [0, 100, 400, 100],
          [slot('box-slot', [20, 120, 360, 0], [item('q', [20, 120, 360, 0])])],
        ),
      ],
    );
    expect(findPreviewDropZoneId(collapsed, { x: 10, y: 140 }, 'q')).toBe(
      'first_bottom',
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
    // Unless the scroll keeps what is under the pointer in place: s is still
    // at y 300 to 400, and t where it was before it.
    viewport.getBoundingClientRect = () => new DOMRect(0, -150, 400, 500);
    holdDropTargets(50);
    expect(findPreviewDropZoneId(list, { x: 200, y: 320 }, 't')).toBeNull();
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

  it('leaves room for the drop targets inside small components', () => {
    // A header as short as at half zoom, with an empty slot.
    const page = slot(
      'page',
      [0, 0, 400, 240],
      [
        item('top', [0, 0, 400, 100]),
        item('header', [0, 100, 400, 40], [slot('h-slot', [16, 108, 368, 24])]),
        item('bottom', [0, 140, 400, 100]),
      ],
    );
    const viewport = el({}, [0, 0, 400, 240], [page]);
    freezeDropTargets(viewport);
    const at = (x: number, y: number) =>
      findPreviewDropZoneId(page, { x, y }, 'new');
    // Coming from below: past the bottom edge band of the header.
    expect(at(200, 145)).toBe('header_bottom');
    expect(at(200, 124)).toBe('h-slot');
    unfreezeDropTargets();
    // The edge bands of the header are a sixth of its height.
    expect(at(200, 104)).toBe('top_bottom');
    expect(at(200, 108)).toBe('h-slot');
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
