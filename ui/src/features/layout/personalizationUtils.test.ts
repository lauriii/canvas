import { describe, expect, it } from 'vitest';

import { NodeType } from './layoutModelSlice';
import {
  filterSlotComponentsForPreview,
  findRootSwitch,
  findSwitchNodes,
  getCaseSegmentIds,
  getPreviewedVariant,
  humanizeVariantId,
} from './personalizationUtils';

import type {
  ComponentModels,
  ComponentNode,
  RegionNode,
  SlotNode,
} from './layoutModelSlice';

const makeComponent = (
  uuid: string,
  type: string,
  slots: SlotNode[] = [],
): ComponentNode => ({
  nodeType: NodeType.Component,
  uuid,
  type,
  slots,
});

const makeCase = (uuid: string): ComponentNode =>
  makeComponent(uuid, 'p13n.case@v1', [
    {
      nodeType: NodeType.Slot,
      id: `${uuid}/content`,
      name: 'content',
      components: [],
    },
  ]);

const caseA = makeCase('case-a');
const caseB = makeCase('case-b');
const defaultCase = makeCase('case-default');

const switchSlot: SlotNode = {
  nodeType: NodeType.Slot,
  id: 'switch-1/content',
  name: 'content',
  components: [caseA, caseB, defaultCase],
};

const switchNode = makeComponent('switch-1', 'p13n.switch@v1', [switchSlot]);

const model: ComponentModels = {
  'switch-1': { resolved: { variants: ['a', 'b', 'default'] } },
  'case-a': { resolved: { variant_id: 'a', segments: ['seg_a'] } },
  'case-b': { resolved: { variant_id: 'b', segments: ['seg_b'] } },
  'case-default': {
    resolved: { variant_id: 'default', segments: ['default'] },
  },
};

const region: RegionNode = {
  nodeType: NodeType.Region,
  id: 'content',
  name: 'Content',
  components: [switchNode],
};

describe('personalizationUtils', () => {
  describe('humanizeVariantId', () => {
    it('formats machine names as sentence case', () => {
      expect(humanizeVariantId('coupon_campaign')).toBe('Coupon campaign');
      expect(humanizeVariantId('offer')).toBe('Offer');
      expect(humanizeVariantId('summer_sale_2024')).toBe('Summer sale 2024');
      expect(humanizeVariantId('__spaced__out__')).toBe('Spaced out');
    });

    it('labels the default variant', () => {
      expect(humanizeVariantId('default')).toBe('Default');
    });

    it('passes through IDs without word characters', () => {
      expect(humanizeVariantId('')).toBe('');
      expect(humanizeVariantId('___')).toBe('___');
    });
  });

  describe('getCaseSegmentIds', () => {
    it('returns the segment IDs of a case', () => {
      expect(getCaseSegmentIds(model, caseA)).toEqual(['seg_a']);
    });

    it('returns an empty list for cases without segments', () => {
      expect(getCaseSegmentIds(model, makeCase('unknown'))).toEqual([]);
    });
  });

  it('returns the default variant when no preview choice was made', () => {
    expect(getPreviewedVariant({}, 'switch-1')).toBe('default');
    expect(getPreviewedVariant({ 'switch-1': 'a' }, 'switch-1')).toBe('a');
  });

  it('finds the root switch of a region', () => {
    expect(findRootSwitch(region)).toBe(switchNode);
    expect(findRootSwitch(undefined)).toBeNull();
    expect(
      findRootSwitch({
        nodeType: NodeType.Region,
        id: 'content',
        name: 'Content',
        components: [makeComponent('hero', 'sdc.hero@1')],
      }),
    ).toBeNull();
  });

  it('finds switches at any depth of the layout', () => {
    const nestedSwitch = makeComponent('switch-2', 'p13n.switch@v1', [
      {
        nodeType: NodeType.Slot,
        id: 'switch-2/content',
        name: 'content',
        components: [],
      },
    ]);
    const wrapper = makeComponent('wrapper', 'sdc.section@1', [
      {
        nodeType: NodeType.Slot,
        id: 'wrapper/body',
        name: 'body',
        components: [nestedSwitch],
      },
    ]);
    const layout: RegionNode[] = [
      region,
      {
        nodeType: NodeType.Region,
        id: 'sidebar',
        name: 'Sidebar',
        components: [wrapper],
      },
    ];
    expect(findSwitchNodes(layout)).toEqual([switchNode, nestedSwitch]);
  });

  describe('filterSlotComponentsForPreview', () => {
    it('passes children of non-switch parents through unchanged', () => {
      const plainSlot: SlotNode = {
        nodeType: NodeType.Slot,
        id: 'wrapper/body',
        name: 'body',
        components: [makeComponent('hero', 'sdc.hero@1')],
      };
      const parent = makeComponent('wrapper', 'sdc.section@1', [plainSlot]);
      expect(filterSlotComponentsForPreview(plainSlot, parent, model, {})).toBe(
        plainSlot.components,
      );
      expect(
        filterSlotComponentsForPreview(plainSlot, undefined, model, {}),
      ).toBe(plainSlot.components);
    });

    it('keeps only the previewed case inside a switch', () => {
      expect(
        filterSlotComponentsForPreview(switchSlot, switchNode, model, {
          'switch-1': 'b',
        }),
      ).toEqual([caseB]);
    });

    it('defaults to the default case when nothing is previewed', () => {
      expect(
        filterSlotComponentsForPreview(switchSlot, switchNode, model, {}),
      ).toEqual([defaultCase]);
    });
  });
});
