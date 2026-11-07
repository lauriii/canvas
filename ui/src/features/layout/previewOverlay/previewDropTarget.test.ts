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
});
