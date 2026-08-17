import { describe, expect, it } from 'vitest';

import {
  describeAllowed,
  groupSlotCandidates,
  NAMED_IN_THIS_SLOT,
  normalizeReference,
  rejectPlacement,
  resolveSlotRule,
  slotOccupancy,
} from './slot-utils';

import type { ComponentsList, SlotDefinition } from '@/types/Component';

/**
 * The client resolver must agree with \Drupal\canvas\SlotRestrictions: the
 * server is authoritative, so any divergence shows up as the UI offering a
 * placement that publishing then refuses.
 *
 * @see \Drupal\canvas\SlotRestrictions
 */

const components = {
  'sdc.my_theme.card': {
    id: 'sdc.my_theme.card',
    name: 'Card',
    tags: ['media'],
  },
  'sdc.my_theme.stat': {
    id: 'sdc.my_theme.stat',
    name: 'Stat',
    tags: ['media'],
  },
  'sdc.my_theme.hero': { id: 'sdc.my_theme.hero', name: 'Hero' },
  'js.promo': { id: 'js.promo', name: 'Promo', tags: ['media', 'promo'] },
} as unknown as ComponentsList;

const slot = (restrictions: Partial<SlotDefinition>): SlotDefinition =>
  ({ title: 'Items', ...restrictions }) as SlotDefinition;

describe('normalizeReference', () => {
  it('reads an SDC plugin ID as a component reference', () => {
    expect(normalizeReference('my_theme:card')).toBe('sdc.my_theme.card');
  });

  it('reads a Canvas component ID as a component reference', () => {
    expect(normalizeReference('sdc.my_theme.card')).toBe('sdc.my_theme.card');
    expect(normalizeReference('js.promo')).toBe('js.promo');
    expect(normalizeReference('block.system.branding.block')).toBe(
      'block.system.branding.block',
    );
  });

  it('reads anything without a delimiter as a tag', () => {
    expect(normalizeReference('media')).toBeNull();
    expect(normalizeReference('card-content')).toBeNull();
  });
});

describe('resolveSlotRule', () => {
  it('is unrestricted when the slot declares nothing', () => {
    expect(resolveSlotRule(slot({}), components).allowed).toBeNull();
  });

  it('resolves references in both spellings', () => {
    const rule = resolveSlotRule(
      slot({ expected: ['my_theme:card', 'js.promo'] }),
      components,
    );
    expect(rule.allowed?.sort()).toEqual(['js.promo', 'sdc.my_theme.card']);
    expect(rule.unresolved).toEqual([]);
  });

  it('resolves a tag to every component carrying it', () => {
    const rule = resolveSlotRule(slot({ expected: ['media'] }), components);
    expect(rule.allowed?.sort()).toEqual([
      'js.promo',
      'sdc.my_theme.card',
      'sdc.my_theme.stat',
    ]);
  });

  it('ignores an entry that resolves to nothing, keeping the rest', () => {
    const rule = resolveSlotRule(
      slot({ expected: ['my_theme:card', 'my_theme:gone', 'no-such-tag'] }),
      components,
    );
    expect(rule.allowed).toEqual(['sdc.my_theme.card']);
    expect(rule.unresolved).toEqual(['my_theme:gone', 'no-such-tag']);
  });

  it('fails open when no entry resolves at all', () => {
    const rule = resolveSlotRule(
      slot({ expected: ['my_theme:gone', 'no-such-tag'] }),
      components,
    );
    expect(rule.allowed).toBeNull();
    expect(rule.unresolved).toEqual(['my_theme:gone', 'no-such-tag']);
  });

  it('reads the cardinality bounds', () => {
    const rule = resolveSlotRule(
      slot({ minItems: 1, maxItems: 3 }),
      components,
    );
    expect(rule.minItems).toBe(1);
    expect(rule.maxItems).toBe(3);
  });
});

describe('rejectPlacement', () => {
  const restricted = resolveSlotRule(
    slot({ expected: ['media'], maxItems: 2 }),
    components,
  );

  it('accepts a component the slot expects', () => {
    expect(
      rejectPlacement(restricted, 'sdc.my_theme.card', 'Items', 0, components),
    ).toBeNull();
  });

  it('refuses a component the slot does not expect, naming what fits', () => {
    expect(
      rejectPlacement(restricted, 'sdc.my_theme.hero', 'Items', 0, components)
        ?.reason,
    ).toBe('Items accepts Card, Stat and Promo');
  });

  it('refuses once the slot is full', () => {
    expect(
      rejectPlacement(restricted, 'sdc.my_theme.card', 'Items', 2, components)
        ?.reason,
    ).toBe('Items is full (2 of 2)');
  });

  it('still allows reordering inside a full slot', () => {
    expect(
      rejectPlacement(
        restricted,
        'sdc.my_theme.card',
        'Items',
        2,
        components,
        true,
      ),
    ).toBeNull();
  });

  it('accepts anything when the slot declares nothing', () => {
    const unrestricted = resolveSlotRule(slot({}), components);
    expect(
      rejectPlacement(
        unrestricted,
        'sdc.my_theme.hero',
        'Items',
        99,
        components,
      ),
    ).toBeNull();
  });
});

describe('describeAllowed', () => {
  it('summarizes a long candidate set', () => {
    const rule = resolveSlotRule(
      slot({ expected: ['media', 'my_theme:hero'] }),
      components,
    );
    expect(describeAllowed(rule, components, 2)).toBe('Card, Stat and 2 more');
  });
});

describe('groupSlotCandidates', () => {
  it('gives each tag its own heading, read as language', () => {
    expect(
      groupSlotCandidates(slot({ expected: ['media'] }), components),
    ).toEqual([
      {
        label: 'Media',
        componentIds: ['sdc.my_theme.card', 'sdc.my_theme.stat', 'js.promo'],
      },
    ]);
  });

  it('collects directly named components under one heading', () => {
    expect(
      groupSlotCandidates(
        slot({ expected: ['my_theme:hero', 'js.promo'] }),
        components,
      ),
    ).toEqual([
      {
        label: NAMED_IN_THIS_SLOT,
        componentIds: ['sdc.my_theme.hero', 'js.promo'],
      },
    ]);
  });

  it('keeps the order the slot declares, and lists a component once', () => {
    expect(
      groupSlotCandidates(
        // `js.promo` carries the `media` tag as well as being named.
        slot({ expected: ['js.promo', 'media'] }),
        components,
      ),
    ).toEqual([
      { label: NAMED_IN_THIS_SLOT, componentIds: ['js.promo'] },
      {
        label: 'Media',
        componentIds: ['sdc.my_theme.card', 'sdc.my_theme.stat'],
      },
    ]);
  });

  it('drops entries that resolve to nothing', () => {
    expect(
      groupSlotCandidates(
        slot({ expected: ['my_theme:gone', 'no-such-tag'] }),
        components,
      ),
    ).toEqual([]);
  });

  it('offers nothing for a slot that restricts nothing', () => {
    // An unrestricted slot has no candidate set to narrow to, so there is no
    // menu to build: the component library already offers everything.
    expect(groupSlotCandidates(slot({}), components)).toEqual([]);
  });
});

describe('slotOccupancy', () => {
  const rule = (restrictions: Partial<SlotDefinition>) =>
    resolveSlotRule(slot(restrictions), components);

  it('says nothing for a slot with no bounds', () => {
    // The presence of a counter is itself the signal that a slot is governed,
    // so a slot that governs only *what* it accepts must not grow one.
    expect(slotOccupancy(rule({ expected: ['media'] }), 2)).toBeNull();
  });

  it('stays quiet while there is nothing to act on', () => {
    expect(slotOccupancy(rule({ maxItems: 3 }), 1)).toEqual({
      label: '1 of 3',
      tone: 'muted',
    });
  });

  it('speaks up at the limit', () => {
    expect(slotOccupancy(rule({ maxItems: 3 }), 3)).toEqual({
      label: '3 of 3',
      tone: 'full',
    });
  });

  it('warns while the slot still owes the author components', () => {
    expect(slotOccupancy(rule({ minItems: 2, maxItems: 4 }), 1)).toEqual({
      label: '1 of 4, needs 2',
      tone: 'under',
    });
  });

  it('warns below a minimum even with no maximum to count towards', () => {
    expect(slotOccupancy(rule({ minItems: 1 }), 0)).toEqual({
      label: '0, needs 1',
      tone: 'under',
    });
  });
});
