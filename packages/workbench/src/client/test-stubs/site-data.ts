// Test stand-in for the `virtual:drupal-canvas/site-data` module.
export default {
  baseUrl: 'https://drupal.example',
  branding: { homeUrl: '/', siteName: 'Workbench test site', siteSlogan: '' },
  jsonapiSettings: { apiPrefix: 'jsonapi' },
  themeAssets: {
    logo: { url: 'https://drupal.example/logo.svg' },
    favicon: {
      url: 'https://drupal.example/favicon.ico',
      mimeType: 'image/x-icon',
    },
  },
};
