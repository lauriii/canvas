import { describe, expect, it } from 'vitest';
import { formatCanvasCommentMarker } from '@drupal-canvas/preview-geometry';

import { NodeType } from '@/features/layout/layoutModelSlice';

import {
  applyVersionHighlights,
  getVersionHighlights,
} from './pageVersionHighlights';

import type { ComponentNode } from '@/features/layout/layoutModelSlice';

const node = (uuid: string): ComponentNode => ({
  nodeType: NodeType.Component,
  uuid,
  type: 'js.card@abc',
  slots: [],
});

const component = (id: string, html: string) =>
  `<!--${formatCanvasCommentMarker({ type: 'component', position: 'start', id })}-->${html}<!--${formatCanvasCommentMarker({ type: 'component', position: 'end', id })}-->`;

describe('getVersionHighlights', () => {
  it('shows removed in the old version, added in the new, others in both', () => {
    const highlights = getVersionHighlights([
      { kind: 'field', label: 'Title', before: 'a', after: 'b' },
      { kind: 'added', node: node('a'), parent: 'Content', props: [] },
      { kind: 'removed', node: node('r'), parent: 'Content', props: [] },
      { kind: 'props', node: node('p'), props: [] },
      { kind: 'moved', node: node('m'), from: 'Content', to: 'Content' },
    ]);

    expect([...highlights.published]).toEqual([
      ['r', 'removed'],
      ['p', 'changed'],
      ['m', 'changed'],
    ]);
    expect([...highlights.new]).toEqual([
      ['a', 'added'],
      ['p', 'changed'],
      ['m', 'changed'],
    ]);
  });
});

describe('applyVersionHighlights', () => {
  it('marks the elements of highlighted components and clears old marks', () => {
    document.body.innerHTML =
      component('a', '<h1>A</h1><p>A2</p>') + component('b', '<p>B</p>');

    applyVersionHighlights(document, new Map([['a', 'added']]));

    const marked = () =>
      [...document.querySelectorAll('[data-canvas-version-diff]')].map(
        (element) =>
          `${element.textContent}:${element.getAttribute('data-canvas-version-diff')}`,
      );
    expect(marked()).toEqual(['A:added', 'A2:added']);

    // `display: contents` wrappers draw no outline; their children are marked.
    document.body.innerHTML = component(
      'c',
      '<canvas-island style="display: contents"><h2>C</h2></canvas-island>',
    );
    applyVersionHighlights(document, new Map([['c', 'added']]));
    expect(marked()).toEqual(['C:added']);

    document.body.innerHTML =
      component('a', '<h1>A</h1><p>A2</p>') + component('b', '<p>B</p>');

    applyVersionHighlights(document, new Map([['b', 'changed']]));
    expect(marked()).toEqual(['B:changed']);

    applyVersionHighlights(document, new Map());
    expect(marked()).toEqual([]);
  });
});
