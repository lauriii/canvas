import type { DrupalSettings, PropsValues } from '@drupal-canvas/types';

const { Drupal, drupalSettings } = window as any;

export const getDrupal = () => Drupal;
export const getDrupalSettings = (): DrupalSettings => drupalSettings;
export const getCanvasSettings = () => drupalSettings?.canvas;
export const getBaseUrl = () => drupalSettings?.path?.baseUrl;
export const getCanvasPermissions = () =>
  drupalSettings.canvas.permissions as Record<string, boolean>;
export const getCanvasModuleBaseUrl = () =>
  `${getBaseUrl()}${drupalSettings?.canvas?.canvasModulePath}`;

export const setCanvasDrupalSetting = (
  property: 'layoutUtils' | 'navUtils',
  value: PropsValues,
) => {
  if (drupalSettings?.canvas?.[property]) {
    drupalSettings.canvas[property] = {
      ...drupalSettings.canvas[property],
      ...value,
    };
  }
};
