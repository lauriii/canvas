import { describe, expect, it } from 'vitest';

import {
  nearestOffered,
  normalizeEntry,
  offeredOptions,
  toggleOption,
  withEntry,
} from '@/features/brandKit/brandKitStyles';

import type { StyleDefinition } from '@/types/Styles';

const spacing = {
  id: 'spacing',
  label: 'Spacing',
  originalLabel: 'Spacing',
  options: ['s', 'm', 'l'].map((id) => ({
    id,
    label: id.toUpperCase(),
    originalLabel: id.toUpperCase(),
  })),
} as unknown as StyleDefinition;

describe('brand kit style options', () => {
  it('lists offered options in definition order', () => {
    expect(offeredOptions(spacing, undefined)).toEqual(['s', 'm', 'l']);
    expect(offeredOptions(spacing, { options: ['l', 's'] })).toEqual([
      's',
      'l',
    ]);
  });

  it('falls back to the nearest offered option, preferring the one before', () => {
    expect(nearestOffered(spacing, 'l', ['s', 'm'])).toBe('m');
    expect(nearestOffered(spacing, 'm', ['s', 'l'])).toBe('s');
    expect(nearestOffered(spacing, 's', [])).toBeUndefined();
  });

  it('withdraws an option in use with a fallback, and one not in use without', () => {
    expect(toggleOption(spacing, undefined, 'l', false, 4)).toEqual({
      options: ['s', 'm'],
      fallbacks: { l: 'm' },
    });
    expect(toggleOption(spacing, undefined, 'l', false, 0)).toEqual({
      options: ['s', 'm'],
      fallbacks: {},
    });
    expect(
      toggleOption(
        spacing,
        { options: ['s'], fallbacks: { m: 's' } },
        'm',
        true,
        2,
      ),
    ).toEqual({ options: ['s', 'm'], fallbacks: {} });
  });

  it('omits what the defaults already say', () => {
    expect(
      normalizeEntry(spacing, {
        label: 'Spacing',
        options: ['s', 'm', 'l'],
        labels: { s: 'S', m: ' ' },
        fallbacks: { m: 's' },
      }),
    ).toBeUndefined();
    expect(
      normalizeEntry(spacing, {
        options: ['s', 'm'],
        labels: { s: 'Snug', l: 'Roomy', m: 'M' },
        fallbacks: { l: 'm', s: 'm' },
      }),
    ).toEqual({
      options: ['s', 'm'],
      // A withdrawn option keeps its name for when it is offered again.
      labels: { s: 'Snug', l: 'Roomy' },
      fallbacks: { l: 'm' },
    });
  });

  it('removes a style entry that matches the defaults', () => {
    expect(
      withEntry({ spacing: { options: ['s'] } }, spacing, {
        options: ['s', 'm', 'l'],
      }),
    ).toEqual({});
  });
});
