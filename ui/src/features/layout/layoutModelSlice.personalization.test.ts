import { beforeEach, describe, expect, it, vi } from 'vitest';

import {
  addVariant,
  layoutModelReducer,
  NodeType,
  personalizePage,
  promoteVariantToDefault,
  removeVariant,
  reorderVariants,
  setVariantDisabled,
} from './layoutModelSlice';
import {
  findRootSwitch,
  getCaseVariantId,
  getSwitchCases,
  getSwitchVariants,
} from './personalizationUtils';

import type {
  ComponentNode,
  LayoutModelSliceState,
  RegionNode,
} from './layoutModelSlice';

const HERO_UUID = 'hero-1111';
const CARD_UUID = 'card-2222';
const INNER_UUID = 'inner-3333';
const SWITCH_TYPE = 'p13n.switch@v1';
const CASE_TYPE = 'p13n.case@v1';

const makeBaseState = (): LayoutModelSliceState => ({
  layout: [
    {
      nodeType: NodeType.Region,
      id: 'content',
      name: 'Content',
      components: [
        {
          nodeType: NodeType.Component,
          uuid: HERO_UUID,
          type: 'sdc.hero@1',
          slots: [],
        },
        {
          nodeType: NodeType.Component,
          uuid: CARD_UUID,
          type: 'sdc.card@1',
          slots: [
            {
              nodeType: NodeType.Slot,
              id: `${CARD_UUID}/body`,
              name: 'body',
              components: [
                {
                  nodeType: NodeType.Component,
                  uuid: INNER_UUID,
                  type: 'sdc.text@1',
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
    [HERO_UUID]: { resolved: { title: 'Hello' } },
    [CARD_UUID]: { resolved: { style: 'plain' } },
    [INNER_UUID]: { resolved: { text: 'Inner' } },
  },
  updatePreview: false,
  isInitialized: true,
  translations: {},
});

const personalize = (state: LayoutModelSliceState): LayoutModelSliceState =>
  layoutModelReducer(
    state,
    personalizePage({
      switchComponentType: SWITCH_TYPE,
      caseComponentType: CASE_TYPE,
    }),
  );

const getContentRegion = (state: LayoutModelSliceState): RegionNode =>
  state.layout[0];

const getRootSwitch = (state: LayoutModelSliceState): ComponentNode => {
  const rootSwitch = findRootSwitch(getContentRegion(state));
  if (!rootSwitch) {
    throw new Error('Expected the content region to have a root switch.');
  }
  return rootSwitch;
};

const collectUuids = (node: ComponentNode): string[] => {
  const uuids = [node.uuid];
  node.slots.forEach((slot) =>
    slot.components.forEach((child) => uuids.push(...collectUuids(child))),
  );
  return uuids;
};

// Builds a personalized state with two extra variants:
// variants are ['offer', 'welcome', 'default'] in priority order.
const setupWithVariants = () => {
  const personalized = personalize(makeBaseState());
  const switchUuid = getRootSwitch(personalized).uuid;
  let state = layoutModelReducer(
    personalized,
    addVariant({
      switchUuid,
      variantId: 'offer',
      segments: ['returning'],
      sourceVariantId: 'default',
    }),
  );
  state = layoutModelReducer(
    state,
    addVariant({
      switchUuid,
      variantId: 'welcome',
      segments: ['new_visitors'],
      sourceVariantId: 'default',
    }),
  );
  return { state, switchUuid };
};

describe('layoutModelSlice personalization reducers', () => {
  beforeEach(() => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
  });

  describe('personalizePage', () => {
    it('wraps the content region in a switch with a default case', () => {
      const state = personalize(makeBaseState());
      const region = getContentRegion(state);

      expect(region.components).toHaveLength(1);
      const switchNode = region.components[0];
      expect(switchNode.type).toBe(SWITCH_TYPE);
      expect(switchNode.slots).toHaveLength(1);
      expect(switchNode.slots[0].name).toBe('content');
      expect(switchNode.slots[0].id).toBe(`${switchNode.uuid}/content`);

      const cases = getSwitchCases(switchNode);
      expect(cases).toHaveLength(1);
      const defaultCase = cases[0];
      expect(defaultCase.type).toBe(CASE_TYPE);
      expect(defaultCase.slots[0].id).toBe(`${defaultCase.uuid}/content`);

      // The region's components moved into the case with UUIDs preserved.
      const moved = defaultCase.slots[0].components;
      expect(moved.map((component) => component.uuid)).toEqual([
        HERO_UUID,
        CARD_UUID,
      ]);
      expect(moved[1].slots[0].components[0].uuid).toBe(INNER_UUID);

      // The moved components' models are untouched.
      expect(state.model[HERO_UUID]).toEqual({ resolved: { title: 'Hello' } });
      expect(state.model[CARD_UUID]).toEqual({ resolved: { style: 'plain' } });
      expect(state.model[INNER_UUID]).toEqual({ resolved: { text: 'Inner' } });

      expect(state.model[switchNode.uuid]).toEqual({
        resolved: { variants: ['default'] },
      });
      expect(state.model[defaultCase.uuid]).toEqual({
        resolved: { variant_id: 'default', segments: ['default'] },
      });
      expect(Object.keys(state.model)).toHaveLength(5);
      expect(state.updatePreview).toBe(true);
    });

    it('does nothing when the region already has a root switch', () => {
      const personalized = personalize(makeBaseState());
      const again = personalize(personalized);
      expect(again).toBe(personalized);
    });
  });

  describe('addVariant', () => {
    it('clones the source case and inserts it before the default case', () => {
      const personalized = personalize(makeBaseState());
      const switchUuid = getRootSwitch(personalized).uuid;
      const state = layoutModelReducer(
        personalized,
        addVariant({
          switchUuid,
          variantId: 'offer',
          segments: ['returning'],
          sourceVariantId: 'default',
        }),
      );

      expect(getSwitchVariants(state.model, switchUuid)).toEqual([
        'offer',
        'default',
      ]);
      const cases = getSwitchCases(getRootSwitch(state));
      expect(cases).toHaveLength(2);
      const [offerCase, defaultCase] = cases;
      expect(getCaseVariantId(state.model, offerCase)).toBe('offer');
      expect(getCaseVariantId(state.model, defaultCase)).toBe('default');
      // The clone's own model is exactly the new variant's identity.
      expect(state.model[offerCase.uuid]).toEqual({
        resolved: { variant_id: 'offer', segments: ['returning'] },
      });

      // The clone and all of its descendants received fresh UUIDs.
      const defaultUuids = collectUuids(defaultCase);
      const cloneUuids = collectUuids(offerCase);
      expect(cloneUuids.some((uuid) => defaultUuids.includes(uuid))).toBe(
        false,
      );
      expect(offerCase.slots[0].id).toBe(`${offerCase.uuid}/content`);

      // Cloned descendants got copies of the source models.
      const [cloneHero, cloneCard] = offerCase.slots[0].components;
      expect(cloneHero.uuid).not.toBe(HERO_UUID);
      expect(state.model[cloneHero.uuid]).toEqual({
        resolved: { title: 'Hello' },
      });
      const cloneInner = cloneCard.slots[0].components[0];
      expect(state.model[cloneInner.uuid]).toEqual({
        resolved: { text: 'Inner' },
      });
      // 5 original model entries plus 4 cloned ones.
      expect(Object.keys(state.model)).toHaveLength(9);
    });

    it('refuses an existing or invalid variant ID', () => {
      const { state, switchUuid } = setupWithVariants();
      const duplicate = layoutModelReducer(
        state,
        addVariant({
          switchUuid,
          variantId: 'offer',
          segments: ['returning'],
          sourceVariantId: 'default',
        }),
      );
      expect(duplicate).toBe(state);

      const invalid = layoutModelReducer(
        state,
        addVariant({
          switchUuid,
          variantId: 'Not-A-Machine-Name',
          segments: ['returning'],
          sourceVariantId: 'default',
        }),
      );
      expect(invalid).toBe(state);
    });
  });

  describe('reorderVariants', () => {
    it('applies the order to the variants list and the case nodes', () => {
      const { state, switchUuid } = setupWithVariants();
      const next = layoutModelReducer(
        state,
        reorderVariants({
          switchUuid,
          order: ['welcome', 'offer', 'default'],
        }),
      );
      expect(getSwitchVariants(next.model, switchUuid)).toEqual([
        'welcome',
        'offer',
        'default',
      ]);
      expect(
        getSwitchCases(getRootSwitch(next)).map((caseNode) =>
          getCaseVariantId(next.model, caseNode),
        ),
      ).toEqual(['welcome', 'offer', 'default']);
      // Reordering preserves the case nodes themselves.
      expect(
        getSwitchCases(getRootSwitch(next))
          .map((caseNode) => caseNode.uuid)
          .sort(),
      ).toEqual(
        getSwitchCases(getRootSwitch(state))
          .map((caseNode) => caseNode.uuid)
          .sort(),
      );
    });

    it('rejects an order that does not contain the same IDs', () => {
      const { state, switchUuid } = setupWithVariants();
      expect(
        layoutModelReducer(
          state,
          reorderVariants({ switchUuid, order: ['welcome', 'offer'] }),
        ),
      ).toBe(state);
      expect(
        layoutModelReducer(
          state,
          reorderVariants({
            switchUuid,
            order: ['welcome', 'offer', 'extra'],
          }),
        ),
      ).toBe(state);
    });
  });

  describe('promoteVariantToDefault', () => {
    it('swaps identity and position with the default case', () => {
      const { state: withVariants, switchUuid } = setupWithVariants();
      // Disable the variant that is about to be promoted to verify the flag
      // is dropped when it becomes the default.
      const state = layoutModelReducer(
        withVariants,
        setVariantDisabled({ switchUuid, variantId: 'offer', disabled: true }),
      );
      const beforeCases = getSwitchCases(getRootSwitch(state));
      const offerCaseUuid = beforeCases[0].uuid;
      const defaultCaseUuid = beforeCases[2].uuid;

      const next = layoutModelReducer(
        state,
        promoteVariantToDefault({ switchUuid, variantId: 'offer' }),
      );

      // The variants list is unchanged: the demoted case takes over the
      // promoted ID at its position and 'default' remains last.
      expect(getSwitchVariants(next.model, switchUuid)).toEqual([
        'offer',
        'welcome',
        'default',
      ]);
      const cases = getSwitchCases(getRootSwitch(next));
      // The old default case sits first and owns the promoted variant's
      // previous identity and audience.
      expect(cases[0].uuid).toBe(defaultCaseUuid);
      expect(next.model[defaultCaseUuid].resolved).toEqual({
        variant_id: 'offer',
        segments: ['returning'],
      });
      // The promoted case is now the default, sits last, and is no longer
      // disabled.
      expect(cases[2].uuid).toBe(offerCaseUuid);
      expect(next.model[offerCaseUuid].resolved).toEqual({
        variant_id: 'default',
        segments: ['default'],
      });
    });

    it('refuses to promote the default variant', () => {
      const { state, switchUuid } = setupWithVariants();
      expect(
        layoutModelReducer(
          state,
          promoteVariantToDefault({ switchUuid, variantId: 'default' }),
        ),
      ).toBe(state);
    });
  });

  describe('setVariantDisabled', () => {
    it('sets and unsets the disabled flag on the case model', () => {
      const { state, switchUuid } = setupWithVariants();
      const offerCaseUuid = getSwitchCases(getRootSwitch(state))[0].uuid;

      const disabledState = layoutModelReducer(
        state,
        setVariantDisabled({ switchUuid, variantId: 'offer', disabled: true }),
      );
      expect(disabledState.model[offerCaseUuid].resolved).toEqual({
        variant_id: 'offer',
        segments: ['returning'],
        disabled: true,
      });

      const enabledState = layoutModelReducer(
        disabledState,
        setVariantDisabled({ switchUuid, variantId: 'offer', disabled: false }),
      );
      expect(enabledState.model[offerCaseUuid].resolved).toEqual({
        variant_id: 'offer',
        segments: ['returning'],
      });
      expect('disabled' in enabledState.model[offerCaseUuid].resolved).toBe(
        false,
      );
    });

    it('refuses to disable the default variant', () => {
      const { state, switchUuid } = setupWithVariants();
      expect(
        layoutModelReducer(
          state,
          setVariantDisabled({
            switchUuid,
            variantId: 'default',
            disabled: true,
          }),
        ),
      ).toBe(state);
    });
  });

  describe('removeVariant', () => {
    it('removes the case node, its descendant models, and the variant ID', () => {
      const { state, switchUuid } = setupWithVariants();
      const offerCase = getSwitchCases(getRootSwitch(state))[0];
      const removedUuids = collectUuids(offerCase);
      const modelCountBefore = Object.keys(state.model).length;

      const next = layoutModelReducer(
        state,
        removeVariant({ switchUuid, variantId: 'offer' }),
      );

      expect(getSwitchVariants(next.model, switchUuid)).toEqual([
        'welcome',
        'default',
      ]);
      expect(
        getSwitchCases(getRootSwitch(next)).map((caseNode) =>
          getCaseVariantId(next.model, caseNode),
        ),
      ).toEqual(['welcome', 'default']);
      removedUuids.forEach((uuid) => {
        expect(next.model[uuid]).toBeUndefined();
      });
      expect(Object.keys(next.model)).toHaveLength(
        modelCountBefore - removedUuids.length,
      );
      expect(next.updatePreview).toBe(true);
    });

    it('refuses to remove the default variant', () => {
      const { state, switchUuid } = setupWithVariants();
      expect(
        layoutModelReducer(
          state,
          removeVariant({ switchUuid, variantId: 'default' }),
        ),
      ).toBe(state);
    });
  });
});
