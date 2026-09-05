import type { BrandKitColorEntry } from '../../types/Component';

/** A server color entry for tests: Brand Red at --brand-red. */
export function remoteColor(
  overrides: Partial<BrandKitColorEntry> = {},
): BrandKitColorEntry {
  return {
    id: 'uuid-red',
    name: 'Brand Red',
    cssVariable: '--brand-red',
    value: {
      colorSpace: 'srgb',
      components: [204 / 255, 0, 0],
      alpha: null,
      hex: '#cc0000',
    },
    displayFormat: null,
    weight: 0,
    ...overrides,
  };
}

/** A second server color for tests: Brand Blue at --brand-blue. */
export function remoteBlue(
  overrides: Partial<BrandKitColorEntry> = {},
): BrandKitColorEntry {
  return remoteColor({
    id: 'uuid-blue',
    name: 'Brand Blue',
    cssVariable: '--brand-blue',
    value: {
      colorSpace: 'srgb',
      components: [0, 0, 204 / 255],
      alpha: null,
      hex: '#0000cc',
    },
    ...overrides,
  });
}
