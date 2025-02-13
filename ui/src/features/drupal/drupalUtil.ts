import type { PropsValues } from '@/types/Form';

const { drupalSettings } = window;
export const setXbDrupalSetting = (
  property: 'layoutUtils' | 'navUtils',
  value: PropsValues,
) => {
  if (drupalSettings?.xb?.[property]) {
    drupalSettings.xb[property] = { ...drupalSettings.xb[property], ...value };
  }
};
