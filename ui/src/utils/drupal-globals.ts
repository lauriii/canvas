import type {
  DrupalSettings,
  HeadlessSettings,
  Language,
  PropsValues,
} from '@drupal-canvas/types';

export type { Language };

const { Drupal, drupalSettings } = window as any;

export const getDrupal = () => Drupal;
export const getDrupalSettings = (): DrupalSettings => drupalSettings;
export const getCanvasSettings = () => drupalSettings?.canvas;
export const getBaseUrl = () => drupalSettings?.path?.baseUrl;
export const getLanguages = (): Language[] =>
  drupalSettings?.canvas?.languages ?? [];
export const getCanvasPermissions = () =>
  drupalSettings.canvas.permissions as Record<string, boolean>;
export const getCanvasModuleBaseUrl = () =>
  `${getBaseUrl()}${drupalSettings?.canvas?.canvasModulePath}`;
export const getCanvasHeadlessSettings = (): HeadlessSettings | undefined =>
  drupalSettings?.canvas?.headless;

// Native prop form settings. Absent settings (e.g. in tests) mean native
// rendering is enabled with no widgets disabled, matching the server default.
export const getPropFormsSettings = (): {
  native: boolean;
  disabledWidgets: string[];
} => ({
  native: drupalSettings?.canvas?.propForms?.native ?? true,
  disabledWidgets: drupalSettings?.canvas?.propForms?.disabledWidgets ?? [],
});

export interface TextFormatSummary {
  id: string;
  label: string;
  editor: string | null;
}

// The text formats the current user may use, each with its associated editor
// plugin id. Delivered with the boot settings so formatted text props resolve
// to a native widget or the escape hatch synchronously at render time.
// @see \Drupal\canvas\Controller\ApiTextEditorSettingsController
export const getTextFormats = (): TextFormatSummary[] =>
  drupalSettings?.canvas?.propForms?.textFormats ?? [];

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
