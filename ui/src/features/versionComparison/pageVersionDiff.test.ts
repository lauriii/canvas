import { describe, expect, it } from 'vitest';

import { NodeType } from '@/features/layout/layoutModelSlice';

import { diffPageVersions } from './pageVersionDiff';

import type {
  ComponentNode,
  ResolvedValues,
} from '@/features/layout/layoutModelSlice';
import type { PageVersionData } from './pageVersionDiff';

const component = (
  uuid: string,
  slots: ComponentNode['slots'] = [],
): ComponentNode => ({
  nodeType: NodeType.Component,
  uuid,
  type: 'sdc.test.card@abc',
  slots,
});

const version = (
  content: ComponentNode[],
  model: Record<string, ResolvedValues>,
  title: string,
): PageVersionData => ({
  layout: [
    {
      nodeType: NodeType.Region,
      id: 'content',
      name: 'Content',
      components: content,
    },
  ],
  model: Object.fromEntries(
    Object.entries(model).map(([uuid, resolved]) => [uuid, { resolved }]),
  ),
  entity_form_fields: {
    'title[0][value]': title,
    'path[0][alias]': '/a',
    // Differs between versions, but is skipped as internal.
    'path[0][pid]': title,
  },
});

describe('diffPageVersions', () => {
  it('lists field, prop, added, removed, and moved changes', () => {
    const before = version(
      [
        component('a'),
        component('b'),
        component('c'),
        component('parent', [
          {
            nodeType: NodeType.Slot,
            id: 'parent/body',
            name: 'Body',
            components: [component('child')],
          },
        ]),
      ],
      { a: { text: 'Hello' }, b: {}, c: {}, parent: {}, child: {} },
      'Old title',
    );
    const after = version(
      [
        component('b'),
        component('a'),
        component('parent', [
          {
            nodeType: NodeType.Slot,
            id: 'parent/body',
            name: 'Body',
            components: [],
          },
        ]),
        component('child'),
        component('d'),
      ],
      { a: { text: 'Hi' }, b: {}, parent: {}, child: {}, d: { text: 'New' } },
      'New title',
    );

    const changes = diffPageVersions(before, after);
    const summary = changes.map((change) =>
      change.kind === 'field'
        ? change.label
        : `${change.kind}:${change.node.uuid}`,
    );

    expect(summary).toEqual([
      'Title',
      'removed:c',
      'moved:a',
      'props:a',
      'moved:child',
      'added:d',
    ]);
    expect(changes).toContainEqual(
      expect.objectContaining({
        kind: 'props',
        props: [{ name: 'text', before: 'Hello', after: 'Hi' }],
      }),
    );
    expect(changes).toContainEqual(
      expect.objectContaining({ kind: 'moved', from: 'Body', to: 'Content' }),
    );
    expect(changes).toContainEqual(
      expect.objectContaining({
        kind: 'added',
        props: [{ name: 'text', value: 'New' }],
      }),
    );
  });

  it('shows rich text as text, unless only the markup changed', () => {
    const changes = diffPageVersions(
      version(
        [component('a')],
        { a: { body: '<p>Hello <b>you</b></p>', note: '<p>Hi</p>' } },
        'Same',
      ),
      version(
        [component('a')],
        { a: { body: '<p>Hello there</p>', note: '<p><b>Hi</b></p>' } },
        'Same',
      ),
    );

    expect(changes).toEqual([
      expect.objectContaining({
        kind: 'props',
        props: [
          { name: 'body', before: 'Hello you', after: 'Hello there' },
          { name: 'note', before: '<p>Hi</p>', after: '<p><b>Hi</b></p>' },
        ],
      }),
    ]);
  });

  it('returns no changes for identical versions', () => {
    const data = version([component('a')], { a: { text: 'Hello' } }, 'Same');
    expect(diffPageVersions(data, structuredClone(data))).toEqual([]);
  });
});
