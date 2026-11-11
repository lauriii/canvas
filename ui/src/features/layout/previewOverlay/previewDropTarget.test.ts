import { afterEach, describe, expect, it } from 'vitest';

import {
  endDropTargets,
  findPreviewDropZoneId,
  holdDropTargets,
  previewDropZoneAt,
  startDropTargets,
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
  afterEach(() => endDropTargets());

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

  it('keeps the drop target while the pointer is on the dragged component where it is shown', () => {
    // A grid that wraps after two cards.
    const [a, b, c, d] = [
      item('a', [0, 0, 200, 200]),
      item('b', [200, 0, 200, 200]),
      item('c', [0, 200, 200, 200]),
      item('d', [200, 200, 200, 200]),
    ];
    const grid = slot(
      'grid',
      [0, 0, 400, 400],
      [a, b, c, d],
      'horizontal-grid',
    );
    const viewport = el({}, [0, 0, 400, 400], [grid]);
    startDropTargets(viewport);
    const at = (x: number, y: number) =>
      findPreviewDropZoneId(grid, { x, y }, 'a');
    expect(at(100, 100)).toBeNull();
    expect(at(300, 100)).toBe('b_right');
    // Showing a in the cell of b moves b to the first cell.
    a.getBoundingClientRect = () => new DOMRect(200, 0, 200, 200);
    b.getBoundingClientRect = () => new DOMRect(0, 0, 200, 200);
    // On a, also where d is the nearest other card.
    expect(at(300, 190)).toBe('b_right');
    // On d: a takes its cell, and b and c move back by one.
    expect(at(300, 300)).toBe('d_right');
    c.getBoundingClientRect = () => new DOMRect(200, 0, 200, 200);
    d.getBoundingClientRect = () => new DOMRect(0, 200, 200, 200);
    a.getBoundingClientRect = () => new DOMRect(200, 200, 200, 200);
    // Back toward where a was: on d again, which moves forward again.
    expect(at(100, 300)).toBe('c_right');
    d.getBoundingClientRect = () => new DOMRect(200, 200, 200, 200);
    a.getBoundingClientRect = () => new DOMRect(0, 200, 200, 200);
    // On b, in the cell a was in.
    expect(at(100, 100)).toBeNull();
    endDropTargets();

    // A component from elsewhere takes the cell under the pointer, and is
    // last past the last card.
    a.getBoundingClientRect = () => new DOMRect(0, 0, 200, 200);
    b.getBoundingClientRect = () => new DOMRect(200, 0, 200, 200);
    c.getBoundingClientRect = () => new DOMRect(0, 200, 200, 200);
    d.getBoundingClientRect = () => new DOMRect(200, 200, 100, 200);
    startDropTargets(viewport);
    expect(findPreviewDropZoneId(grid, { x: 150, y: 300 }, 'new')).toBe(
      'b_right',
    );
    expect(findPreviewDropZoneId(grid, { x: 350, y: 300 }, 'new')).toBe(
      'd_right',
    );
    endDropTargets();
  });

  it('resolves against where components are shown, also when the preview scrolls', () => {
    const [t, s] = [item('t', [0, 0, 400, 400]), item('s', [0, 400, 400, 100])];
    const list = slot('list', [0, 0, 400, 500], [t, s]);
    const viewport = el({}, [0, 0, 400, 500], [list]);
    startDropTargets(viewport);
    const at = (x: number, y: number) =>
      findPreviewDropZoneId(list, { x, y }, 't');
    expect(at(200, 460)).toBe('s_bottom');
    // Showing t after s moves s up.
    s.getBoundingClientRect = () => new DOMRect(0, 0, 400, 100);
    t.getBoundingClientRect = () => new DOMRect(0, 100, 400, 400);
    // On the first half of s, where t was when the drag started.
    expect(at(200, 30)).toBeNull();
    endDropTargets();

    const [a, b] = [item('a', [0, 0, 400, 100]), item('b', [0, 100, 400, 100])];
    const page = slot('page', [0, 0, 400, 200], [a, b]);
    const pane = el({}, [0, 0, 400, 200], [page]);
    const scrollTo = (y: number) =>
      [pane, page, a, b].forEach((node, i) => {
        const top = (i === 3 ? 100 : 0) - y;
        const height = i < 2 ? 200 : 100;
        node.getBoundingClientRect = () => new DOMRect(0, top, 400, height);
      });
    startDropTargets(pane);
    const over = (x: number, y: number) =>
      findPreviewDropZoneId(page, { x, y }, 'new');
    expect(over(200, 45)).toBe('a_top');
    // The preview scrolls by 10 pixels to keep what is under the pointer in
    // place: the pointer hasn't moved from where the drop target changed.
    scrollTo(10);
    holdDropTargets(10);
    expect(over(200, 45)).toBe('a_top');
    // Other scrolling moves another drop target under the pointer.
    scrollTo(26);
    expect(over(200, 45)).toBe('a_bottom');
    endDropTargets();
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
    startDropTargets(viewport);
    const at = (x: number, y: number) =>
      findPreviewDropZoneId(page, { x, y }, 'new');
    // Coming from below: past the bottom edge band of the header.
    expect(at(200, 145)).toBe('header_bottom');
    expect(at(200, 124)).toBe('h-slot');
    endDropTargets();
    // The edge bands of the header are a sixth of its height.
    expect(at(200, 104)).toBe('top_bottom');
    expect(at(200, 108)).toBe('h-slot');
  });

  it('keeps the drop target in the empty slot it is shown in, and at the edge of the page', () => {
    // A container with an empty slot, between two other components.
    const [top, box, held] = [
      item('top', [0, 0, 400, 100]),
      item('box', [0, 100, 400, 100]),
      slot('box-slot', [20, 120, 360, 60]),
    ];
    box.append(held);
    const page = slot(
      'page',
      [0, 0, 400, 300],
      [top, box, item('bottom', [0, 200, 400, 100])],
    );
    const viewport = el({}, [0, 0, 400, 300], [page]);
    startDropTargets(viewport);
    const at = (x: number, y: number) =>
      findPreviewDropZoneId(page, { x, y }, 'new');
    expect(at(300, 150)).toBe('box-slot');
    // Shown in the slot, a small component is all the slot holds.
    held.getBoundingClientRect = () => new DOMRect(20, 120, 100, 30);
    box.getBoundingClientRect = () => new DOMRect(0, 100, 400, 70);
    // Where the slot was, also near the edge the container has now.
    expect(at(320, 160)).toBe('box-slot');
    // Near the edge the container had.
    expect(at(320, 104)).toBe('top_bottom');
    endDropTargets();

    // Shown as the first component of a container that its slot fills, the
    // dragged component covers the edge of the container: the pointer near
    // that edge still targets the gap before the container.
    const [first, second, moved] = [
      item('first', [0, 0, 400, 100]),
      item('second', [0, 100, 400, 100]),
      item('moved', [0, 200, 400, 100]),
    ];
    const filled = item(
      'filled',
      [0, 0, 400, 200],
      [slot('filled-slot', [0, 0, 400, 200], [first, second])],
    );
    const rows = slot('rows', [0, 0, 400, 300], [filled, moved]);
    startDropTargets(el({}, [0, 0, 400, 300], [rows]));
    const drop = (x: number, y: number) =>
      findPreviewDropZoneId(rows, { x, y }, 'moved');
    expect(drop(200, 40)).toBe('first_top');
    moved.getBoundingClientRect = () => new DOMRect(0, 0, 400, 100);
    first.getBoundingClientRect = () => new DOMRect(0, 100, 400, 100);
    second.getBoundingClientRect = () => new DOMRect(0, 200, 400, 100);
    filled.getBoundingClientRect = () => new DOMRect(0, 0, 400, 300);
    expect(drop(200, 30)).toBe('first_top');
    expect(drop(200, 5)).toBe('filled_top');
    endDropTargets();

    // Leaving the previewed page shows the dragged component where it was:
    // back on it there, it stays there.
    const list = slot(
      'list',
      [0, 0, 400, 300],
      [
        item('a', [0, 0, 400, 100]),
        item('b', [0, 100, 400, 100]),
        item('c', [0, 200, 400, 100]),
      ],
    );
    const pane = el(
      { 'data-canvas-editor-pane': 'true' },
      [0, 0, 600, 300],
      [el({ class: 'canvas--viewport-overlay' }, [0, 0, 400, 300], [list])],
    );
    document.body.append(pane);
    startDropTargets(pane.firstElementChild as HTMLElement);
    expect(previewDropZoneAt({ x: 200, y: 20 }, 'c')).toBe('a_top');
    expect(previewDropZoneAt({ x: 500, y: 250 }, 'c')).toBeNull();
    expect(previewDropZoneAt({ x: 390, y: 250 }, 'c')).toBeNull();
    pane.remove();
  });

  it('settles on what the pointer is on as the layout follows the drop target', () => {
    // Lays components out as the preview does when the dragged component is
    // shown at a drop target: in a column, or in a grid of three columns.
    const simulate = (grid: boolean, sizes: number[], dragged: number) => {
      const items = sizes.map((_, i) => item(`i${i}`, [0, 0, 0, 0]));
      const list = slot(
        'list',
        [0, 0, 300, 2000],
        items,
        grid ? 'horizontal-grid' : 'vertical',
      );
      const order = (id: string | null) => {
        const others = items.filter((_, i) => i !== dragged);
        const match = id?.match(/^i(\d+)_(top|left|bottom|right)$/);
        const index = match
          ? others.indexOf(items[+match[1]]) +
            (match[2] === 'bottom' || match[2] === 'right' ? 1 : 0)
          : dragged;
        others.splice(index, 0, items[dragged]);
        return others;
      };
      const show = (id: string | null) => {
        let y = 0;
        order(id).forEach((node, i) => {
          const size = sizes[items.indexOf(node)];
          const box = grid
            ? new DOMRect((i % 3) * 100, Math.floor(i / 3) * 100, 100, 100)
            : new DOMRect(0, y, 300, size);
          node.getBoundingClientRect = () => box;
          y += size;
        });
        return order(id).indexOf(items[dragged]);
      };
      show(null);
      startDropTargets(el({}, [0, 0, 300, 2000], [list]));
      let point = { x: 50, y: 5 };
      return {
        // Moves the pointer in steps, showing each drop target, and returns
        // where the dragged component ends up and how often it moved.
        moveTo(x: number, y: number) {
          let moves = 0;
          let shown = -1;
          let last: string | null | undefined;
          for (let step = 1; step <= 40; step++) {
            const at = {
              x: point.x + ((x - point.x) * step) / 40,
              y: point.y + ((y - point.y) * step) / 40,
            };
            const id = findPreviewDropZoneId(list, at, `i${dragged}`);
            moves += step > 1 && id !== last ? 1 : 0;
            last = id;
            shown = show(id);
          }
          point = { x, y };
          return { shown, moves };
        },
      };
    };

    // In a grid, the dragged component ends up in the cell the pointer is
    // in, also on the way back to the cell it was in.
    for (let dragged = 0; dragged < 5; dragged++) {
      const grid = simulate(true, [100, 100, 100, 100, 100], dragged);
      const cells = [4, 1, 3, 0, 2, 4, dragged];
      expect(
        cells.map(
          (cell) =>
            grid.moveTo((cell % 3) * 100 + 50, Math.floor(cell / 3) * 100 + 50)
              .shown,
        ),
      ).toEqual(cells);
      // In the empty cell after the last one, it is last.
      expect(grid.moveTo(270, 150).shown).toBe(4);
      expect(grid.moveTo(280, 160).moves).toBe(0);
      endDropTargets();
    }

    // In a column of components of different heights, it moves one place at
    // a time, and stays while the pointer moves about on it.
    const sizes = [50, 300, 50, 120, 80];
    for (let dragged = 0; dragged < 5; dragged++) {
      const column = simulate(false, sizes, dragged);
      const seen = new Set<number>();
      for (const y of [595, 5, 595, 5]) {
        for (let at = y === 5 ? 595 : 5; at !== y; at += y === 5 ? -10 : 10) {
          const { shown, moves } = column.moveTo(150, at);
          expect(moves).toBeLessThanOrEqual(1);
          seen.add(shown);
          expect(column.moveTo(160, at + 2).moves).toBe(0);
        }
      }
      expect(seen.size).toBe(5);
      endDropTargets();
    }
  });

  it('keeps the drop target in an empty slot that moves with the component that has it', () => {
    // page
    // ├── d     y 0–20, dragged
    // ├── box   y 20–68, its empty slot y 20–60
    // └── z     y 68–268
    const [d, box, held, z] = [
      item('d', [0, 0, 400, 20]),
      item('box', [0, 20, 400, 48]),
      slot('box-slot', [0, 20, 400, 40]),
      item('z', [0, 68, 400, 200]),
    ];
    box.append(held);
    const page = slot('page', [0, 0, 400, 600], [d, box, z]);
    const viewport = el({}, [0, 0, 400, 600], [page]);
    startDropTargets(viewport);
    // How far the preview has scrolled.
    let scrolled = 0;
    const put = (node: HTMLElement, top: number, height: number) => {
      node.getBoundingClientRect = () =>
        new DOMRect(0, top - scrolled, 400, height);
      return top + height;
    };
    // Lays the page out as the preview does with d shown at a drop target:
    // the slot is as tall as d while it holds d, and 40px tall while empty.
    const show = (id: string | null) => {
      const inSlot = id === 'box-slot';
      let y = id === null ? put(d, 0, 20) : 0;
      put(held, y, inSlot ? 20 : 40);
      if (inSlot) put(d, y, 20);
      y = put(box, y, inSlot ? 28 : 48);
      if (id === 'box_bottom') y = put(d, y, 20);
      put(z, y, 200);
      viewport.getBoundingClientRect = () =>
        new DOMRect(0, -scrolled, 400, 600);
    };
    // The pointer moves straight down from d, one pixel at a time.
    const seen: (string | null)[] = [];
    for (let y = 10; y <= 80; y++) {
      const id = findPreviewDropZoneId(page, { x: 200, y }, 'd');
      if (id !== seen[seen.length - 1]) {
        seen.push(id);
        // The preview scrolls to keep the box where it is in the editor (see
        // keepInPlace() in livePreview.ts).
        const top = box.getBoundingClientRect().top;
        show(id);
        const moved = box.getBoundingClientRect().top - top;
        scrolled += moved;
        holdDropTargets(moved);
        show(id);
      }
    }
    expect(seen).toEqual([null, 'box-slot', 'box_bottom']);
  });

  it('keeps the pointer on a page that the drop target makes shorter', () => {
    // The last component of the page has two columns: x in the left one,
    // c (dragged) above y in the right one. The page ends where they end.
    const [x, c, y] = [
      item('x', [0, 0, 200, 50]),
      item('c', [200, 0, 200, 100]),
      item('y', [200, 100, 200, 300]),
    ];
    const [left, right] = [
      slot('left', [0, 0, 200, 50], [x]),
      slot('right', [200, 0, 200, 400], [c, y]),
    ];
    const columns = item('columns', [0, 0, 400, 400], [left, right]);
    const viewport = el(
      { class: 'canvas--viewport-overlay' },
      [0, 0, 400, 400],
      [slot('page', [0, 0, 400, 400], [columns])],
    );
    const pane = el(
      { 'data-canvas-editor-pane': 'true' },
      [0, 0, 600, 800],
      [viewport],
    );
    document.body.append(pane);
    startDropTargets(viewport);
    // Below the short column.
    expect(previewDropZoneAt({ x: 100, y: 350 }, 'c')).toBe('x_bottom');
    // Shown below x, c leaves the right column 300px tall: the columns and
    // the page end 100px higher, above the pointer.
    c.getBoundingClientRect = () => new DOMRect(0, 50, 200, 100);
    left.getBoundingClientRect = () => new DOMRect(0, 0, 200, 150);
    y.getBoundingClientRect = () => new DOMRect(200, 0, 200, 300);
    right.getBoundingClientRect = () => new DOMRect(200, 0, 200, 300);
    columns.getBoundingClientRect = () => new DOMRect(0, 0, 400, 300);
    viewport.getBoundingClientRect = () => new DOMRect(0, 0, 400, 300);
    expect(previewDropZoneAt({ x: 100, y: 350 }, 'c')).toBe('x_bottom');
    // Below where the page ended: outside it.
    expect(previewDropZoneAt({ x: 100, y: 420 }, 'c')).toBeNull();
    pane.remove();
  });

  it('settles on the drop target that shows the dragged component nearest to the pointer', () => {
    // A row that wraps, 400px wide. a and b leave 100px free at the end of
    // the first row; t and the dragged d, 80px wide, are in the second.
    const d = item('d', [300, 40, 80, 40]);
    const row = slot(
      'row',
      [0, 0, 400, 80],
      [
        item('a', [0, 0, 100, 40]),
        item('b', [100, 0, 200, 40]),
        item('t', [0, 40, 300, 40]),
        d,
      ],
      'horizontal-flex',
    );
    startDropTargets(el({}, [0, 0, 400, 80], [row]));
    // From d, along t, a pixel at a time.
    const seen: Array<string | null> = [];
    for (let x = 340; x >= 150; x--) {
      const id = findPreviewDropZoneId(row, { x, y: 60 }, 'd');
      // Before t, d fits at the end of the first row: t stays where it is.
      const box = new DOMRect(300, id === 'b_right' ? 0 : 40, 80, 40);
      d.getBoundingClientRect = () => box;
      if (id !== seen[seen.length - 1]) {
        seen.push(id);
      }
    }
    // Each of the two puts the other under the pointer. Where it was, d is
    // nearer to the pointer: it goes back there once, and stays.
    expect(seen).toEqual([null, 'b_right', null]);
  });

  it('holds the drop target against a hand held still at the edge of a short component', () => {
    // top 0-100 / header 100-124: branding [logo], navigation [nav] / bottom.
    const logo = item('logo', [0, 100, 60, 24]);
    const bottom = item('bottom', [0, 124, 400, 100]);
    const page = slot(
      'page',
      [0, 0, 400, 224],
      [
        item('top', [0, 0, 400, 100]),
        item(
          'header',
          [0, 100, 400, 24],
          [
            // Keeps its size while the logo is shown elsewhere.
            slot('branding', [0, 100, 60, 24], [logo], 'horizontal-flex'),
            slot(
              'navigation',
              [280, 100, 120, 24],
              [item('nav', [280, 100, 120, 24])],
              'horizontal-flex',
            ),
          ],
        ),
        bottom,
      ],
    );
    startDropTargets(el({}, [0, 0, 400, 224], [page]));
    // Lays out as the preview does: after the header, the logo is below it.
    const at = (x: number, y: number) => {
      const id = findPreviewDropZoneId(page, { x, y }, 'logo');
      const after = id === 'header_bottom';
      logo.getBoundingClientRect = () =>
        new DOMRect(0, after ? 124 : 100, 60, 24);
      bottom.getBoundingClientRect = () =>
        new DOMRect(0, after ? 148 : 124, 400, 100);
      return id;
    };
    expect(at(30, 112)).toBeNull();
    // The bottom edge band of the header starts at y 120.
    expect(at(30, 122)).toBe('header_bottom');
    // A hand held still, 1.5px either side of it: back in the header once.
    expect([119, 122, 119, 122].map((y) => at(30, y))).toEqual(
      Array(4).fill(null),
    );
    // From below the header.
    expect(at(30, 130)).toBe('header_bottom');
    endDropTargets();
  });

  it('reaches the gap before a container from its edge wherever the dragged component was shown', () => {
    const [first, second, moved] = [
      item('first', [0, 0, 400, 100]),
      item('second', [0, 100, 400, 100]),
      item('moved', [0, 200, 400, 100]),
    ];
    const filled = item(
      'filled',
      [0, 0, 400, 200],
      [slot('filled-slot', [0, 0, 400, 200], [first, second])],
    );
    const rows = slot('rows', [0, 0, 400, 300], [filled, moved]);
    startDropTargets(el({}, [0, 0, 400, 300], [rows]));
    const drop = (y: number) =>
      findPreviewDropZoneId(rows, { x: 200, y }, 'moved');
    expect(drop(5)).toBe('filled_top');
    // Shown before filled.
    moved.getBoundingClientRect = () => new DOMRect(0, 0, 400, 100);
    filled.getBoundingClientRect = () => new DOMRect(0, 100, 400, 200);
    first.getBoundingClientRect = () => new DOMRect(0, 100, 400, 100);
    second.getBoundingClientRect = () => new DOMRect(0, 200, 400, 100);
    expect(drop(130)).toBe('first_top');
    // Shown first in filled, where it was shown before filled.
    filled.getBoundingClientRect = () => new DOMRect(0, 0, 400, 300);
    expect([5, 6, 4, 8].map(drop)).toEqual(Array(4).fill('filled_top'));
  });

  it('keeps the drop target in an empty slot near the slot as it is now', () => {
    // h is 80px tall whatever its slot holds.
    const [t, h, hs, z] = [
      item('t', [0, 0, 400, 400]),
      item('h', [0, 400, 400, 80]),
      slot('h-slot', [16, 416, 368, 40]),
      item('z', [0, 480, 400, 600]),
    ];
    h.append(hs);
    const page = slot('page', [0, 0, 400, 1080], [t, h, z]);
    startDropTargets(el({}, [0, 0, 400, 1080], [page]));
    const at = (y: number) => findPreviewDropZoneId(page, { x: 40, y }, 't');
    expect(at(200)).toBeNull();
    expect(at(413)).toBe('h-slot');
    // t, in the slot, reaches far below h.
    t.getBoundingClientRect = () => new DOMRect(16, 16, 368, 400);
    h.getBoundingClientRect = () => new DOMRect(0, 0, 400, 80);
    hs.getBoundingClientRect = () => new DOMRect(16, 16, 368, 400);
    z.getBoundingClientRect = () => new DOMRect(0, 96, 400, 600);
    expect(at(422)).toBe('h-slot');
    expect(at(440)).toBe('h-slot');
    expect(at(460)).toBe('z_bottom');
  });

  it('changes the drop target only once the pointer has clearly moved', () => {
    const viewport = el({}, [0, 0, 400, 1000], [root]);
    startDropTargets(viewport);
    // The boundary between before and after a is at y 50.
    expect(find(200, 45)).toBe('a_top');
    expect(find(200, 53)).toBe('a_bottom');
    // Not back, or on, within a few pixels of where it changed.
    expect(find(200, 47)).toBe('a_bottom');
    expect(find(200, 30)).toBe('a_top');
    expect(find(200, 150)).toBe('d_top');
    endDropTargets();
  });

  it('holds the drop target where three of them meet', () => {
    // Near the corner of the row's top edge band and the boundary between
    // f and g, a hand held still doesn't move the dragged component around.
    const jitter = [
      [97, 513],
      [103, 513],
      [100, 507],
    ];
    expect(jitter.map(([x, y]) => find(x, y))).toEqual([
      'f_left',
      'f_right',
      'c_bottom',
    ]);
    const viewport = el({}, [0, 0, 400, 1000], [root]);
    startDropTargets(viewport);
    const seen = [...jitter, ...jitter].map(([x, y]) => find(x, y));
    expect(new Set(seen).size).toBe(1);
    endDropTargets();
  });
});
