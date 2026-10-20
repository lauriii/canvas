import { describe, expect, it } from 'vitest';

import { filterAndRankPacks } from '@/components/icons/iconSearch';

import type { IconPack } from '@/types/Icons';

const packs: IconPack[] = [
  {
    id: 'starpack',
    label: 'Star pack',
    description: '',
    iconCount: 4,
    icons: [
      { id: 'starpack:unstarred', name: 'unstarred', label: 'Unstarred' },
      { id: 'starpack:star-half', name: 'star-half', label: 'Star Half' },
      { id: 'starpack:star', name: 'star', label: 'Star' },
      { id: 'starpack:circle', name: 'circle', label: 'Circle' },
    ],
  },
  {
    id: 'arrows',
    label: 'Arrows',
    description: '',
    iconCount: 1,
    icons: [{ id: 'arrows:up', name: 'up', label: 'Up' }],
  },
];

describe('filterAndRankPacks', () => {
  it('returns the packs unchanged for an empty term', () => {
    expect(filterAndRankPacks(packs, '  ')).toBe(packs);
  });

  it('ranks exact before prefix before substring, within each pack', () => {
    const [pack] = filterAndRankPacks(packs, 'star');
    expect(pack.icons.map((icon) => icon.name)).toEqual([
      'star',
      'star-half',
      'unstarred',
    ]);
  });

  it('drops packs left without matches', () => {
    const result = filterAndRankPacks(packs, 'circle');
    expect(result).toHaveLength(1);
    expect(result[0].id).toBe('starpack');
  });

  it('matches labels case-insensitively', () => {
    const [pack] = filterAndRankPacks(packs, 'STAR HALF');
    expect(pack.icons.map((icon) => icon.name)).toEqual(['star-half']);
  });

  it('never matches the pack id', () => {
    // "starpack" appears in every icon's full id and in the pack id, but
    // matching considers only names and labels.
    expect(filterAndRankPacks(packs, 'starpack')).toHaveLength(0);
  });
});
