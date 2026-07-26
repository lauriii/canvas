import { Provider } from 'react-redux';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { renderHook } from '@testing-library/react';

import { makeStore } from '@/app/store';
import { NodeType, setLayoutModel } from '@/features/layout/layoutModelSlice';
import useCopyPasteComponents from '@/hooks/useCopyPasteComponents';
import { rejectPlacementAtPath } from '@/hooks/useSlotRestrictions';

import type { RegionNode } from '@/features/layout/layoutModelSlice';
import type { ComponentsList } from '@/types/Component';

/**
 * Every placement path outside drag and drop is gated by the same resolver, so
 * that the client never expresses a placement the server then refuses at
 * publish time.
 *
 * @see \Drupal\canvas\SlotRestrictions
 */

const CONTAINER_UUID = 'c0000000-0000-4000-8000-000000000000';
const CHILD_UUID = 'c1111111-1111-4111-8111-111111111111';

const components = {
  'sdc.my_theme.container': {
    id: 'sdc.my_theme.container',
    name: 'Container',
    metadata: {
      slots: {
        items: { title: 'Items', expected: ['media'], maxItems: 1 },
      },
    },
  },
  // The same slot without a cardinality limit, so that `expected` is the only
  // thing that can refuse a placement.
  'sdc.my_theme.roomy-container': {
    id: 'sdc.my_theme.roomy-container',
    name: 'Roomy container',
    metadata: {
      slots: {
        items: { title: 'Items', expected: ['media'] },
      },
    },
  },
  'sdc.my_theme.card': {
    id: 'sdc.my_theme.card',
    name: 'Card',
    tags: ['media'],
  },
  'sdc.my_theme.hero': { id: 'sdc.my_theme.hero', name: 'Hero' },
} as unknown as ComponentsList;

/** One region holding a container whose restricted `items` slot holds a card. */
const layout = (): RegionNode[] => [
  {
    nodeType: NodeType.Region,
    id: 'content',
    name: 'Content',
    components: [
      {
        nodeType: NodeType.Component,
        uuid: CONTAINER_UUID,
        type: 'sdc.my_theme.container@1',
        slots: [
          {
            nodeType: NodeType.Slot,
            id: `${CONTAINER_UUID}/items`,
            name: 'items',
            components: [
              {
                nodeType: NodeType.Component,
                uuid: CHILD_UUID,
                type: 'sdc.my_theme.card@1',
                slots: [],
              },
            ],
          },
        ],
      },
    ],
  },
];

const mockToastError = vi.fn();
vi.mock('sonner', () => ({
  toast: {
    error: (...args: unknown[]) => mockToastError(...args),
  },
}));

vi.mock('@/services/componentAndLayout', async () => {
  const actual = await vi.importActual('@/services/componentAndLayout');
  return {
    ...actual,
    useGetComponentsQuery: () => ({ data: components }),
  };
});

describe('rejectPlacementAtPath', () => {
  // [region, component, slot, position] — inserting at the front of `items`.
  const intoItems = [0, 0, 0, 0];

  it('accepts a component the slot expects', () => {
    // An empty slot, so the single-item limit is not reached yet.
    const empty = layout();
    empty[0].components[0].slots[0].components = [];
    expect(
      rejectPlacementAtPath(
        empty,
        intoItems,
        ['sdc.my_theme.card'],
        components,
      ),
    ).toBeNull();
  });

  it('refuses a component the slot does not expect', () => {
    const empty = layout();
    empty[0].components[0].slots[0].components = [];
    expect(
      rejectPlacementAtPath(empty, intoItems, ['sdc.my_theme.hero'], components)
        ?.reason,
    ).toBe('Items accepts Card');
  });

  it('refuses once the slot is full', () => {
    expect(
      rejectPlacementAtPath(
        layout(),
        intoItems,
        ['sdc.my_theme.card'],
        components,
      )?.reason,
    ).toBe('Items is full (1 of 1)');
  });

  it('accepts anything at a region, which declares no restrictions', () => {
    expect(
      rejectPlacementAtPath(
        layout(),
        [0, 1],
        ['sdc.my_theme.hero'],
        components,
      ),
    ).toBeNull();
  });
});

describe('pasteAfterSelectedComponent', () => {
  let store: ReturnType<typeof makeStore>;

  const wrapper = ({ children }: { children: React.ReactNode }) => (
    <Provider store={store}>
      <MemoryRouter>{children}</MemoryRouter>
    </Provider>
  );

  const copyToClipboard = (componentId: string) =>
    localStorage.setItem(
      'copiedComponent',
      JSON.stringify({
        model: {},
        layout: [
          {
            nodeType: NodeType.Component,
            uuid: 'copied00-0000-4000-8000-000000000000',
            type: `${componentId}@1`,
            slots: [],
          },
        ],
      }),
    );

  const slotOccupancy = () =>
    store.getState().layoutModel.present.layout[0].components[0].slots[0]
      .components.length;

  beforeEach(() => {
    // This test environment ships no Web Storage, so stand one in for the
    // clipboard the hook reads.
    const stored = new Map<string, string>();
    vi.stubGlobal('localStorage', {
      getItem: (key: string) => stored.get(key) ?? null,
      setItem: (key: string, value: string) => stored.set(key, value),
      removeItem: (key: string) => stored.delete(key),
      clear: () => stored.clear(),
    });
    store = makeStore();
    store.dispatch(setLayoutModel({ layout: layout(), model: {} }));
    mockToastError.mockClear();
  });

  it('refuses to paste a component the destination slot does not expect', () => {
    // Room to spare in the slot, so only `expected` can refuse this.
    const roomy = layout();
    roomy[0].components[0].type = 'sdc.my_theme.roomy-container@1';
    store.dispatch(setLayoutModel({ layout: roomy, model: {} }));
    copyToClipboard('sdc.my_theme.hero');
    const { result } = renderHook(() => useCopyPasteComponents(), { wrapper });

    result.current.pasteAfterSelectedComponent(CHILD_UUID);

    expect(slotOccupancy()).toBe(1);
    expect(mockToastError).toHaveBeenCalledWith('Items accepts Card');
  });

  it('refuses to paste into a slot that is already full', () => {
    copyToClipboard('sdc.my_theme.card');
    const { result } = renderHook(() => useCopyPasteComponents(), { wrapper });

    result.current.pasteAfterSelectedComponent(CHILD_UUID);

    expect(slotOccupancy()).toBe(1);
    expect(mockToastError).toHaveBeenCalledWith('Items is full (1 of 1)');
  });

  it('pastes into a region, which declares no restrictions', () => {
    copyToClipboard('sdc.my_theme.hero');
    const { result } = renderHook(() => useCopyPasteComponents(), { wrapper });

    result.current.pasteAfterSelectedComponent(CONTAINER_UUID);

    expect(
      store.getState().layoutModel.present.layout[0].components,
    ).toHaveLength(2);
    expect(mockToastError).not.toHaveBeenCalled();
  });
});
