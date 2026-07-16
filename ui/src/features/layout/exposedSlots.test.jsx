import { Provider } from 'react-redux';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it } from 'vitest';
import { Theme } from '@radix-ui/themes';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { makeStore } from '@/app/store';
import {
  CANVAS_SLOT_EMPTY_MARKER_ID,
  CANVAS_SLOT_EMPTY_MARKER_TYPE,
  countExposedSlots,
  exposedSlotsFromServer,
  exposedSlotsToServer,
  filterNonMarkerComponents,
  findEnclosingExposedSlotAlias,
  findExposedSlotEntry,
  findExposedSlotsInSubtree,
  isEmptySlotMarkerNode,
  isExposedSlotTarget,
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
import { getNodeAtPath, isNodeEditable } from '@/features/layout/layoutUtils';
import { setDialogWithDataOpen } from '@/features/ui/dialogSlice';
import {
  deriveSlotFieldName,
  validateSlotFieldName,
} from '@/features/validation/validation';

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

// --- Phase 8: per-content (locked) editing --------------------------------

// A per-content merged tree: a locked template host component (editable:false)
// with an exposed slot holding locked default content, plus a locked non-exposed
// (template chrome) slot. Passing slotOverrides puts the store in per-content
// mode. @see ApiLayoutController per-content GET.
const buildPerContentModel = () => ({
  layout: [
    {
      name: 'Content',
      id: 'content',
      nodeType: 'region',
      components: [
        {
          nodeType: 'component',
          uuid: 'host-1',
          type: 'sdc.canvas_test_all_props@1',
          editable: false,
          slots: [
            {
              nodeType: 'slot',
              id: 'host-1/exposed_slot',
              name: 'exposed_slot',
              components: [
                {
                  nodeType: 'component',
                  uuid: 'default-1',
                  type: 'sdc.canvas_test_all_props@1',
                  editable: false,
                  slots: [],
                },
              ],
            },
            {
              nodeType: 'slot',
              id: 'host-1/locked_slot',
              name: 'locked_slot',
              components: [
                {
                  nodeType: 'component',
                  uuid: 'chrome-1',
                  type: 'sdc.canvas_test_all_props@1',
                  editable: false,
                  slots: [],
                },
              ],
            },
          ],
        },
      ],
    },
  ],
  model: {
    'host-1': { resolved: {} },
    'default-1': { resolved: {} },
    'chrome-1': { resolved: {} },
  },
  exposedSlots: {
    hero: {
      label: 'Hero',
      slotName: 'exposed_slot',
      componentUuid: 'host-1',
    },
  },
  slotOverrides: { hero: { overridden: false, empty: false } },
});

// The components of the exposed slot in the current store state.
const exposedSlotComponents = (state) =>
  selectLayout(state)[0].components[0].slots[0].components;

describe('per-content mode helpers', () => {
  it('detects locked (template-owned) components via editable', () => {
    const host = buildPerContentModel().layout[0].components[0];
    // Locked component is non-interactive: isNodeEditable drives disabling the
    // draggable, hiding the context menu and skipping its drop zones.
    expect(isNodeEditable(host)).toBe(false);
    expect(isNodeEditable(host.slots[0].components[0])).toBe(false);
    // Absent flag (page/template editing) means editable.
    expect(
      isNodeEditable({ nodeType: 'component', uuid: 'x', type: 't' }),
    ).toBe(true);
    expect(isNodeEditable({ editable: true })).toBe(true);
  });

  it('accepts drops into an exposed slot, even nested in locked chrome', () => {
    const { layout, exposedSlots } = buildPerContentModel();
    const host = layout[0].components[0];
    // Exposed slot nested inside the locked host accepts drops.
    expect(isExposedSlotTarget(host.slots[0], exposedSlots)).toBe(true);
    // A non-exposed (template chrome) slot rejects drops.
    expect(isExposedSlotTarget(host.slots[1], exposedSlots)).toBe(false);
  });

  it('resolves the enclosing exposed slot alias for a component', () => {
    const { layout, exposedSlots } = buildPerContentModel();
    expect(
      findEnclosingExposedSlotAlias(layout, exposedSlots, 'default-1')?.alias,
    ).toBe('hero');
    // A component in locked chrome has no enclosing exposed slot.
    expect(
      findEnclosingExposedSlotAlias(layout, exposedSlots, 'chrome-1'),
    ).toBe(null);
  });

  it('resolves a node at a layout path', () => {
    const { layout } = buildPerContentModel();
    const exposedSlot = layout[0].components[0].slots[0];
    expect(getNodeAtPath(layout, [0, 0, 0])).toBe(exposedSlot);
    expect(getNodeAtPath(layout, [0, 0, 0, 0])).toBe(exposedSlot.components[0]);
    expect(getNodeAtPath(layout, [0, 5])).toBe(null);
  });

  it('recognizes and filters the empty-slot marker', () => {
    const host = buildPerContentModel().layout[0].components[0];
    expect(CANVAS_SLOT_EMPTY_MARKER_TYPE.split('@')[0]).toBe(
      CANVAS_SLOT_EMPTY_MARKER_ID,
    );
    const marker = {
      nodeType: 'component',
      uuid: 'm',
      type: CANVAS_SLOT_EMPTY_MARKER_TYPE,
      slots: [],
    };
    expect(isEmptySlotMarkerNode(marker)).toBe(true);
    expect(isEmptySlotMarkerNode(host)).toBe(false);
    expect(
      filterNonMarkerComponents([marker, host]).map((c) => c.uuid),
    ).toEqual(['host-1']);
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

  it('enters per-content mode from the merged GET', () => {
    expect(selectIsPerContentMode(store.getState())).toBe(true);
    expect(selectSlotOverrides(store.getState()).hero).toEqual({
      overridden: false,
      empty: false,
    });
  });

  it('forks the default to fresh, editable UUIDs and marks it overridden', () => {
    store.dispatch(overrideSlotDefaultContent('hero'));

    const components = exposedSlotComponents(store.getState());
    expect(components).toHaveLength(1);
    // Fresh UUID: no longer the template default's UUID.
    expect(components[0].uuid).not.toBe('default-1');
    // Forked copy is entity-owned (editable) so the server write guard accepts it.
    expect(components[0].editable).toBe(true);
    // Its model was copied to the new UUID.
    expect(store.getState().layoutModel.present.model).toHaveProperty(
      components[0].uuid,
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
        to: [0, 0, 0, 1],
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
    // The forked default is explicitly editable; the inserted node carries no
    // editable:false flag, so none of the slot content is locked.
    expect(components.every((c) => isNodeEditable(c))).toBe(true);
    expect(components.find((c) => c.uuid !== 'new-1').editable).toBe(true);
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

  it('refuses to delete locked template-owned components', () => {
    store.dispatch(deleteNode('host-1'));
    store.dispatch(deleteNode('chrome-1'));

    // The locked chrome is untouched, models included.
    const layout = selectLayout(store.getState());
    expect(layout[0].components).toHaveLength(1);
    expect(layout[0].components[0].uuid).toBe('host-1');
    expect(layout[0].components[0].slots[1].components[0].uuid).toBe(
      'chrome-1',
    );
    expect(store.getState().layoutModel.present.model).toHaveProperty('host-1');
    expect(store.getState().layoutModel.present.model).toHaveProperty(
      'chrome-1',
    );
  });

  it('refuses to duplicate locked template-owned components', () => {
    store.dispatch(duplicateNode({ uuid: 'chrome-1' }));

    const layout = selectLayout(store.getState());
    expect(layout[0].components[0].slots[1].components).toHaveLength(1);
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
        to: [0, 0, 0, 0],
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

  it('leaves template chrome untouched when editing an exposed slot', () => {
    store.dispatch(overrideSlotDefaultContent('hero'));
    // The locked host and its non-exposed slot content are unchanged.
    const host = selectLayout(store.getState())[0].components[0];
    expect(host.uuid).toBe('host-1');
    expect(host.editable).toBe(false);
    expect(host.slots[1].components[0].uuid).toBe('chrome-1');
  });
});
