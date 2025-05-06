const { drupalSettings } = window as any;

export const getBaseUrl = () => drupalSettings.path.baseUrl;
