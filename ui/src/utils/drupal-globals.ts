// @todo Refactor codebase to use these methods in https://drupal.org/i/3521811.
const { Drupal, drupalSettings } = window as any;

export const getDrupal = () => Drupal;
export const getDrupalSettings = () => drupalSettings;
export const getXbSettings = () => drupalSettings.xb;
export const getBaseUrl = () => drupalSettings.path.baseUrl;
