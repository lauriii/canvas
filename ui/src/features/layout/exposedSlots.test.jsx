import { Provider } from 'react-redux';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it } from 'vitest';
import { Theme } from '@radix-ui/themes';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { makeStore } from '@/app/store';
import {
  CANVAS_SLOT_EMPTY_MARKER_TYPE,
  countExposedSlots,
  exposedSlotsFromServer,
  exposedSlotsToServer,
  filterNonMarkerComponents,
  findExposedSlotEntry,
  findExposedSlotsInSubtree,
  findSlotNestingConflict,
  isEmptySlotMarkerNode,
  isLockedSlotRegion,
} from '@/features/layout/exposedSlots';
import DeleteComponentWithExposedSlotsDialog from '@/features/layout/exposeSlot/DeleteComponentWithExposedSlotsDialog';
import {
  addExposedSlot,
  deleteComponentAndExposedSlots,
  deleteNode,
  duplicateNode,
  insertNodes,
  overrideSlotDefaultContent,
  removeExposedSlot,
  revertSlotOverride,
  selectExposedSlots,
  selectIsPerContentMode,
  selectLayout,
  selectSlotOverrides,
  setInitialLayoutModel,
  updateExposedSlotLabel,
} from '@/features/layout/layoutModelSlice';
import { getNodeAtPath } from '@/features/layout/layoutUtils';
import { setDialogWithDataOpen } from '@/features/ui/dialogSlice';
import {
  deriveSlotFieldName,
  validateSlotFieldName,
} from '@/features/validation/validation';
import { EMPTY_SLOT_MARKER_ID } from '@/services/pageVariants';

// The expose/detach *dialog* UI coverage (the single "Slot field" Select that
// defaults to reusing an existing field, the "Add new slot…" create path, and
// Detach) lives in the Playwright spec
// tests/src/Playwright/tests/isolatedPerTest/exposeSlot.spec.ts, because those
// dialogs now depend on the slot-field candidate/create APIs. This file keeps
// the pure helper, reducer and per-content coverage.

// A minimal layout with one component hosting one empty slot.
const buildLayoutModel = () => ({
  layout: [
    {
      name: 'Content',
      id: 'content',
      nodeType: 'region',
      components: [
        {
          nodeType: 'component',
          uuid: 'comp-1',
          type: 'sdc.canvas_test_all_props@1',
          slots: [
            {
              nodeType: 'slot',
              id: 'comp-1/the_body',
              name: 'the_body',
              components: [],
            },
          ],
        },
      ],
    },
  ],
  model: { 'comp-1': { resolved: {} } },
});

const renderWith = (store, ui) =>
  render(
    <Provider store={store}>
      <MemoryRouter>
        <Theme
          accentColor="blue"
          hasBackground={false}
          panelBackground="solid"
          appearance="light"
        >
          {ui}
        </Theme>
      </MemoryRouter>
    </Provider>,
  );

describe('deriveSlotFieldName', () => {
  it('derives a canvas_slot_-prefixed field machine name from a label', () => {
    expect(deriveSlotFieldName('My Hero')).toBe('canvas_slot_my_hero');
    expect(deriveSlotFieldName('  Featured   Area!! ')).toBe(
      'canvas_slot_featured_area',
    );
    expect(deriveSlotFieldName('Call-to-Action')).toBe(
      'canvas_slot_call_to_action',
    );
    expect(deriveSlotFieldName('123 Go')).toBe('canvas_slot_123_go');
  });

  it('returns an empty string when no valid suffix remains', () => {
    expect(deriveSlotFieldName('!!!')).toBe('');
    expect(deriveSlotFieldName('   ')).toBe('');
  });
});

describe('validateSlotFieldName', () => {
  it('accepts valid slot field names', () => {
    expect(validateSlotFieldName('canvas_slot_my_hero')).toBe('');
    expect(validateSlotFieldName('canvas_slot_hero')).toBe('');
    expect(validateSlotFieldName('canvas_slot_abc')).toBe('');
  });

  it('requires a value', () => {
    expect(validateSlotFieldName('')).toBe('Machine name is required.');
    expect(validateSlotFieldName('   ')).toBe('Machine name is required.');
  });

  it('requires the canvas_slot_ prefix', () => {
    expect(validateSlotFieldName('my_hero')).toBe(
      'Machine name must start with "canvas_slot_".',
    );
  });

  it('rejects invalid patterns', () => {
    // Trailing underscore not allowed.
    expect(validateSlotFieldName('canvas_slot_hero_')).not.toBe('');
    // Uppercase / special characters not allowed.
    expect(validateSlotFieldName('canvas_slot_Hero')).not.toBe('');
    expect(validateSlotFieldName('canvas_slot_he ro')).not.toBe('');
  });

  it('enforces the 32-character limit', () => {
    // 'canvas_slot_' (12) + 21 chars = 33, over the 32-char field-name cap.
    expect(validateSlotFieldName(`canvas_slot_${'a'.repeat(21)}`)).not.toBe('');
  });

  it('enforces uniqueness within the template', () => {
    expect(
      validateSlotFieldName('canvas_slot_hero', [
        'canvas_slot_hero',
        'canvas_slot_footer',
      ]),
    ).toBe('This machine name is already in use in this template.');
    expect(
      validateSlotFieldName('canvas_slot_sidebar', [
        'canvas_slot_hero',
        'canvas_slot_footer',
      ]),
    ).toBe('');
  });
});

describe('exposedSlots helpers', () => {
  const sliceShape = {
    hero: {
      label: 'Hero',
      slotName: 'the_body',
      componentUuid: 'comp-1',
    },
    footer: {
      label: 'Footer',
      slotName: 'the_footer',
      componentUuid: 'comp-2',
    },
  };

  it('round-trips between slice and server shapes', () => {
    const server = exposedSlotsToServer(sliceShape);
    expect(server.hero).toEqual({
      component_uuid: 'comp-1',
      slot_name: 'the_body',
      label: 'Hero',
    });
    expect(server.footer).toEqual({
      component_uuid: 'comp-2',
      slot_name: 'the_footer',
      label: 'Footer',
    });
    expect(exposedSlotsFromServer(server)).toEqual(sliceShape);
  });

  it('finds an exposed slot by host component + slot name', () => {
    expect(findExposedSlotEntry(sliceShape, 'comp-1', 'the_body')?.alias).toBe(
      'hero',
    );
    expect(findExposedSlotEntry(sliceShape, 'comp-1', 'nope')).toBe(null);
    expect(findExposedSlotEntry(undefined, 'comp-1', 'the_body')).toBe(null);
  });

  it('counts the exposed slots in a server-side map', () => {
    expect(countExposedSlots(exposedSlotsToServer(sliceShape))).toBe(2);
    expect(countExposedSlots(undefined)).toBe(0);
  });

  it('finds nesting conflicts blocking a slot exposure in both directions', () => {
    // outer (comp-1/the_body, exposed as "hero") hosts inner (comp-3), whose
    // own slot is the exposure candidate.
    const layout = [
      {
        name: 'Content',
        id: 'content',
        nodeType: 'region',
        components: [
          {
            nodeType: 'component',
            uuid: 'comp-1',
            type: 'sdc.canvas_test_all_props@1',
            slots: [
              {
                nodeType: 'slot',
                id: 'comp-1/the_body',
                name: 'the_body',
                components: [
                  {
                    nodeType: 'component',
                    uuid: 'comp-3',
                    type: 'sdc.canvas_test_all_props@1',
                    slots: [
                      {
                        nodeType: 'slot',
                        id: 'comp-3/the_body',
                        name: 'the_body',
                        components: [],
                      },
                    ],
                  },
                ],
              },
            ],
          },
        ],
      },
    ];
    const innerSlot = layout[0].components[0].slots[0].components[0].slots[0];
    const outerSlot = layout[0].components[0].slots[0];
    const exposedOuter = { hero: sliceShape.hero };
    const exposedInner = {
      inner: { label: 'Inner', slotName: 'the_body', componentUuid: 'comp-3' },
    };

    // Exposing the inner slot while the outer is exposed: blocked ('inside').
    const inside = findSlotNestingConflict(
      exposedOuter,
      layout,
      innerSlot,
      'comp-3',
    );
    expect(inside?.direction).toBe('inside');
    expect(inside?.alias).toBe('hero');

    // Exposing the outer slot while the inner is exposed: blocked ('contains').
    const contains = findSlotNestingConflict(
      exposedInner,
      layout,
      outerSlot,
      'comp-1',
    );
    expect(contains?.direction).toBe('contains');
    expect(contains?.alias).toBe('inner');

    // A sibling-hosted exposed slot does not block, nor does an empty map.
    expect(
      findSlotNestingConflict(
        { footer: sliceShape.footer },
        layout,
        innerSlot,
        'comp-3',
      ),
    ).toBe(null);
    expect(
      findSlotNestingConflict(undefined, layout, innerSlot, 'comp-3'),
    ).toBe(null);
  });

  it('finds exposed slots hosted anywhere within a component subtree', () => {
    const { layout } = buildLayoutModel();
    const component = layout[0].components[0];
    const found = findExposedSlotsInSubtree(
      { hero: sliceShape.hero },
      component,
    );
    expect(found).toHaveLength(1);
    expect(found[0].alias).toBe('hero');
    // A slot hosted by a different component is not matched.
    expect(
      findExposedSlotsInSubtree({ footer: sliceShape.footer }, component),
    ).toHaveLength(0);
  });
});

describe('layoutModelSlice exposed-slot reducers', () => {
  let store;

  beforeEach(() => {
    store = makeStore({});
    store.dispatch(
      setInitialLayoutModel({ ...buildLayoutModel(), updatePreview: false }),
    );
  });

  it('adds, relabels and detaches an exposed slot', () => {
    store.dispatch(
      addExposedSlot({
        alias: 'hero',
        label: 'Hero',
        slotName: 'the_body',
        componentUuid: 'comp-1',
      }),
    );
    expect(selectExposedSlots(store.getState()).hero).toEqual({
      label: 'Hero',
      slotName: 'the_body',
      componentUuid: 'comp-1',
    });

    store.dispatch(
      updateExposedSlotLabel({ alias: 'hero', label: 'Hero area' }),
    );
    expect(selectExposedSlots(store.getState()).hero.label).toBe('Hero area');

    // Detach (the former "Remove") deletes the working-set entry; the backing
    // field and any per-entity content survive on the server.
    store.dispatch(removeExposedSlot('hero'));
    expect(selectExposedSlots(store.getState())).not.toHaveProperty('hero');
  });

  it('exposes the first slot when the server sent an empty array (regression)', () => {
    // PHP serializes an empty `exposed_slots: {}` as a JSON array `[]`; exposing
    // the first slot must still work (previously threw an Immer error).
    store.dispatch(
      setInitialLayoutModel({
        ...buildLayoutModel(),
        exposedSlots: [],
        updatePreview: false,
      }),
    );
    store.dispatch(
      addExposedSlot({
        alias: 'hero',
        label: 'Hero',
        slotName: 'the_body',
        componentUuid: 'comp-1',
      }),
    );
    const exposed = selectExposedSlots(store.getState());
    expect(Array.isArray(exposed)).toBe(false);
    expect(exposed.hero).toEqual({
      label: 'Hero',
      slotName: 'the_body',
      componentUuid: 'comp-1',
    });
  });

  it('deletes a hosting component and its exposed slot together', () => {
    store.dispatch(
      addExposedSlot({
        alias: 'hero',
        label: 'Hero',
        slotName: 'the_body',
        componentUuid: 'comp-1',
      }),
    );

    store.dispatch(
      deleteComponentAndExposedSlots({ uuid: 'comp-1', aliases: ['hero'] }),
    );

    const layout = selectLayout(store.getState());
    expect(layout[0].components).toHaveLength(0);
    expect(selectExposedSlots(store.getState())).not.toHaveProperty('hero');
  });
});

describe('DeleteComponentWithExposedSlotsDialog', () => {
  let store;

  beforeEach(() => {
    store = makeStore({});
    store.dispatch(
      setInitialLayoutModel({ ...buildLayoutModel(), updatePreview: false }),
    );
    store.dispatch(
      addExposedSlot({
        alias: 'hero',
        label: 'Hero',
        slotName: 'the_body',
        componentUuid: 'comp-1',
      }),
    );
    store.dispatch(
      setDialogWithDataOpen({
        operation: 'deleteComponentWithExposedSlots',
        data: {
          componentUuid: 'comp-1',
          componentName: 'Test component',
          aliases: ['hero'],
          labels: ['Hero'],
        },
      }),
    );
    renderWith(store, <DeleteComponentWithExposedSlotsDialog />);
  });

  it('names the hosted slot and deletes component + slot on confirm', async () => {
    expect(
      await screen.findByText(/hosts exposed slot "Hero"/),
    ).toBeInTheDocument();
    await userEvent.click(
      await screen.findByRole('button', { name: 'Delete' }),
    );
    expect(selectLayout(store.getState())[0].components).toHaveLength(0);
    expect(selectExposedSlots(store.getState())).not.toHaveProperty('hero');
  });
});

// --- Per-content (slot-scoped) editing ------------------------------------

// A per-content layout: each exposed slot is its own top-level region node
// keyed by the backing field name, containing only entity-owned content.
// Template chrome is not part of the layout at all; the slot's template
// default ships as data in slotDefaults (for the unlock fork). Passing
// slotOverrides puts the store in per-content mode.
// @see ApiLayoutController per-content GET.
const buildPerContentModel = () => ({
  layout: [
    {
      name: 'Hero',
      id: 'hero',
      nodeType: 'region',
      components: [],
    },
  ],
  model: {},
  exposedSlots: {
    hero: {
      label: 'Hero',
      slotName: 'exposed_slot',
      componentUuid: 'host-1',
    },
  },
  slotOverrides: { hero: { overridden: false, empty: false } },
  slotDefaults: {
    hero: {
      layout: [
        {
          nodeType: 'component',
          uuid: 'default-1',
          type: 'sdc.canvas_test_all_props@1',
          slots: [],
        },
      ],
      model: { 'default-1': { resolved: {} } },
    },
  },
});

// The components of the exposed slot's region in the current store state.
const exposedSlotComponents = (state) =>
  selectLayout(state).find((region) => region.id === 'hero').components;

describe('per-content mode helpers', () => {
  it('detects locked slot regions', () => {
    const { exposedSlots, slotOverrides, slotDefaults } =
      buildPerContentModel();
    // Not overridden + a template default exists: locked.
    expect(
      isLockedSlotRegion('hero', exposedSlots, slotOverrides, slotDefaults),
    ).toBe(true);
    // Overridden: not locked.
    expect(
      isLockedSlotRegion(
        'hero',
        exposedSlots,
        { hero: { overridden: true, empty: false } },
        slotDefaults,
      ),
    ).toBe(false);
    // No template default: an ordinary empty area, no lock.
    expect(
      isLockedSlotRegion('hero', exposedSlots, slotOverrides, { hero: null }),
    ).toBe(false);
    // Not an exposed slot region at all.
    expect(
      isLockedSlotRegion('content', exposedSlots, slotOverrides, slotDefaults),
    ).toBe(false);
  });

  it('resolves a node at a layout path', () => {
    const layout = [
      {
        name: 'Hero',
        id: 'hero',
        nodeType: 'region',
        components: [
          {
            nodeType: 'component',
            uuid: 'c-1',
            type: 'sdc.canvas_test_all_props@1',
            slots: [],
          },
        ],
      },
    ];
    expect(getNodeAtPath(layout, [0, 0]).uuid).toBe('c-1');
    expect(getNodeAtPath(layout, [0, 5])).toBe(null);
  });

  it('recognizes and filters the empty-slot marker', () => {
    expect(CANVAS_SLOT_EMPTY_MARKER_TYPE.split('@')[0]).toBe(
        );
    const marker = {
      nodeType: 'component',
      uuid: 'm',
      type: CANVAS_SLOT_EMPTY_MARKER_TYPE,
      slots: [],
    };
    const ordinary = {
      nodeType: 'component',
      uuid: 'c-1',
      type: 'sdc.canvas_test_all_props@1',
      slots: [],
    };
    expect(isEmptySlotMarkerNode(marker)).toBe(true);
    expect(isEmptySlotMarkerNode(ordinary)).toBe(false);
    expect(
      filterNonMarkerComponents([marker, ordinary]).map((c) => c.uuid),
    ).toEqual(['c-1']);
  });
});

describe('per-content override reducers', () => {
  let store;

  beforeEach(() => {
    store = makeStore({});
    store.dispatch(
      setInitialLayoutModel({
        ...buildPerContentModel(),
        updatePreview: false,
      }),
    );
  });

  it('enters per-content mode from the slot-scoped GET', () => {
    expect(selectIsPerContentMode(store.getState())).toBe(true);
    expect(selectSlotOverrides(store.getState()).hero).toEqual({
      overridden: false,
      empty: false,
    });
    // The template default is data, not layout: it is stored aside and its
    // UUID appears nowhere in the editable layout or model.
    expect(
      store.getState().layoutModel.present.slotDefaults.hero.layout,
    ).toHaveLength(1);
    expect(exposedSlotComponents(store.getState())).toHaveLength(0);
    expect(store.getState().layoutModel.present.model).not.toHaveProperty(
      'default-1',
    );
  });

  it('forks the default to fresh, editable UUIDs and marks it overridden', () => {
    store.dispatch(overrideSlotDefaultContent('hero'));

    const components = exposedSlotComponents(store.getState());
    expect(components).toHaveLength(1);
    // Fresh UUID: no longer the template default's UUID.
    expect(components[0].uuid).not.toBe('default-1');
    // Its model was copied under the new UUID; the template default's UUID
    // stays out of the editable model.
    expect(store.getState().layoutModel.present.model).toHaveProperty(
      components[0].uuid,
    );
    expect(store.getState().layoutModel.present.model).not.toHaveProperty(
      'default-1',
    );
    // The slot is now overridden and not empty.
    expect(selectSlotOverrides(store.getState()).hero).toEqual({
      overridden: true,
      empty: false,
    });
  });

  it('forks the default on a first drop into a still-defaulted exposed slot', () => {
    store.dispatch(
      insertNodes({
        to: [0, 0],
        useUUID: 'new-1',
        layoutModel: {
          layout: [
            {
              nodeType: 'component',
              uuid: 'new-1',
              type: 'sdc.canvas_test_all_props@1',
              slots: [],
            },
          ],
          model: { 'new-1': { resolved: {} } },
        },
      }),
    );

    const components = exposedSlotComponents(store.getState());
    const uuids = components.map((c) => c.uuid);
    // The dropped component landed, and the default was forked to a fresh UUID.
    expect(uuids).toContain('new-1');
    expect(uuids).not.toContain('default-1');
    expect(components).toHaveLength(2);
    expect(selectSlotOverrides(store.getState()).hero.overridden).toBe(true);
  });

  it('materializes an override directly when the slot has no default', () => {
    store = makeStore({});
    store.dispatch(
      setInitialLayoutModel({
        ...buildPerContentModel(),
        slotDefaults: { hero: null },
        updatePreview: false,
      }),
    );

    store.dispatch(
      insertNodes({
        to: [0, 0],
        useUUID: 'new-1',
        layoutModel: {
          layout: [
            {
              nodeType: 'component',
              uuid: 'new-1',
              type: 'sdc.canvas_test_all_props@1',
              slots: [],
            },
          ],
          model: { 'new-1': { resolved: {} } },
        },
      }),
    );

    const components = exposedSlotComponents(store.getState());
    expect(components.map((c) => c.uuid)).toEqual(['new-1']);
    expect(selectSlotOverrides(store.getState()).hero.overridden).toBe(true);
  });

  it('reverts an override, clearing the entity content so the default returns on save', () => {
    store.dispatch(overrideSlotDefaultContent('hero'));
    const forkedUuid = exposedSlotComponents(store.getState())[0].uuid;

    store.dispatch(revertSlotOverride('hero'));

    // The slot is cleared (no marker) => inherit the default on save.
    expect(exposedSlotComponents(store.getState())).toHaveLength(0);
    expect(selectSlotOverrides(store.getState()).hero).toEqual({
      overridden: false,
      empty: false,
    });
    // The override content's model was removed.
    expect(store.getState().layoutModel.present.model).not.toHaveProperty(
      forkedUuid,
    );
  });

  it('keeps an emptied override empty (marker), distinct from reverting', () => {
    store.dispatch(overrideSlotDefaultContent('hero'));
    const forkedUuid = exposedSlotComponents(store.getState())[0].uuid;

    store.dispatch(deleteNode(forkedUuid));

    const components = exposedSlotComponents(store.getState());
    // The last real component is replaced by exactly one empty-slot marker.
    expect(components).toHaveLength(1);
    expect(isEmptySlotMarkerNode(components[0])).toBe(true);
    expect(selectSlotOverrides(store.getState()).hero).toEqual({
      overridden: true,
      empty: true,
    });
  });

  it('drops the marker when content is added back to an empty override', () => {
    store.dispatch(overrideSlotDefaultContent('hero'));
    const forkedUuid = exposedSlotComponents(store.getState())[0].uuid;
    store.dispatch(deleteNode(forkedUuid));
    // Sanity: it is now an empty override (marker present).
    expect(
      isEmptySlotMarkerNode(exposedSlotComponents(store.getState())[0]),
    ).toBe(true);

    store.dispatch(
      insertNodes({
        to: [0, 0],
        useUUID: 'new-2',
        layoutModel: {
          layout: [
            {
              nodeType: 'component',
              uuid: 'new-2',
              type: 'sdc.canvas_test_all_props@1',
              slots: [],
            },
          ],
          model: { 'new-2': { resolved: {} } },
        },
      }),
    );

    const components = exposedSlotComponents(store.getState());
    expect(components.map((c) => c.uuid)).toEqual(['new-2']);
    expect(components.some((c) => isEmptySlotMarkerNode(c))).toBe(false);
    expect(selectSlotOverrides(store.getState()).hero).toEqual({
      overridden: true,
      empty: false,
    });
  });
});
