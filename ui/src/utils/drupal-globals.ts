import type { PropsValues } from '@/types/Form';
import type { DrupalSettings } from '@/types/DrupalSettings';

const { Drupal, drupalSettings } = window as any;

export const getDrupal = () => Drupal;
export const getDrupalSettings = (): DrupalSettings => drupalSettings;
export const getXbSettings = () => drupalSettings?.xb;
export const getBaseUrl = () => drupalSettings?.path?.baseUrl;
export const getXbPermissions = () =>
  drupalSettings.xb.permissions as Record<string, boolean>;
export const getXbModuleBaseUrl = () =>
  `${getBaseUrl()}${drupalSettings?.xb?.xbModulePath}`;

export const setXbDrupalSetting = (
  property: 'layoutUtils' | 'navUtils',
  value: PropsValues,
) => {
  if (drupalSettings?.xb?.[property]) {
    drupalSettings.xb[property] = { ...drupalSettings.xb[property], ...value };
  }
};
