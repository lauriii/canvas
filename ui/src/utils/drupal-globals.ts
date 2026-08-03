import type {
  DrupalSettings,
  HeadlessSettings,
  Language,
  PropsValues,
} from '@drupal-canvas/types';
import type { WorkspacesSettings } from '@/types/Workspaces';

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
export const getSiteName = (): string | undefined =>
  drupalSettings?.canvas?.siteName;
export const getSiteUrl = (): string | undefined =>
  drupalSettings?.canvas?.siteUrl;

export const CANVAS_HEADLESS_SETTINGS_CHANGE =
  'canvas:headless-settings-change';
export const CANVAS_HEADLESS_FRONTEND_STORAGE_KEY =
  'canvas-headless-active-frontend';

const normalizeFrontendUrl = (frontendUrl: string) => {
  const frontend = new URL(frontendUrl);
  return `${frontend.origin}${frontend.pathname === '/' ? '' : frontend.pathname}`;
};

export const setCanvasHeadlessFrontend = (
  frontendUrl?: string,
  configuredFrontends?: string[],
) => {
  const canvasSettings = getCanvasSettings();
  if (!frontendUrl) {
    delete canvasSettings?.headless;
    window.localStorage.removeItem(CANVAS_HEADLESS_FRONTEND_STORAGE_KEY);
    window.dispatchEvent(new Event(CANVAS_HEADLESS_SETTINGS_CHANGE));
    return;
  }

  const frontend = new URL(frontendUrl);
  const normalizedFrontendUrl = normalizeFrontendUrl(frontendUrl);
  canvasSettings.headless = {
    frontendUrl: normalizedFrontendUrl,
    frontends: configuredFrontends?.map(normalizeFrontendUrl) ??
      canvasSettings.headless?.frontends ?? [normalizedFrontendUrl],
    frontendOrigin: frontend.origin,
    draftUrl: `${normalizedFrontendUrl}/api/draft`,
    assertionUrl: `${getBaseUrl()}canvas-headless/assertion`,
  };
  window.localStorage.setItem(
    CANVAS_HEADLESS_FRONTEND_STORAGE_KEY,
    normalizedFrontendUrl,
  );
  window.dispatchEvent(new Event(CANVAS_HEADLESS_SETTINGS_CHANGE));
};

export const setCanvasHeadlessFrontends = (configuredFrontends: string[]) => {
  const normalizedFrontends = configuredFrontends.map(normalizeFrontendUrl);
  const activeFrontend = getCanvasHeadlessSettings()?.frontendUrl;
  const nextActiveFrontend =
    activeFrontend && normalizedFrontends.includes(activeFrontend)
      ? activeFrontend
      : normalizedFrontends[0];
  setCanvasHeadlessFrontend(nextActiveFrontend, normalizedFrontends);
};

export const restoreCanvasHeadlessFrontend = () => {
  const settings = getCanvasHeadlessSettings();
  const storedFrontend = window.localStorage.getItem(
    CANVAS_HEADLESS_FRONTEND_STORAGE_KEY,
  );
  if (!settings || !storedFrontend) {
    return;
  }

  let normalizedStoredFrontend: string;
  try {
    normalizedStoredFrontend = normalizeFrontendUrl(storedFrontend);
  } catch {
    window.localStorage.removeItem(CANVAS_HEADLESS_FRONTEND_STORAGE_KEY);
    return;
  }

  if (!settings.frontends.includes(normalizedStoredFrontend)) {
    window.localStorage.removeItem(CANVAS_HEADLESS_FRONTEND_STORAGE_KEY);
    return;
  }
  if (settings.frontendUrl !== normalizedStoredFrontend) {
    setCanvasHeadlessFrontend(normalizedStoredFrontend);
  }
};
export const getWorkspacesSettings = (): WorkspacesSettings | undefined =>
  drupalSettings?.canvas?.workspaces;

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
