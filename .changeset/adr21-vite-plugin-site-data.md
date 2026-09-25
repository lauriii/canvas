---
'@drupal-canvas/vite-plugin': minor
---

Load the site-data endpoint once (it is public; a token is sent when
available) and expose the resulting site data through the
`virtual:drupal-canvas/site-data` module and the plugin's `api.getCanvasSiteData()`,
in addition to the existing `drupalSettings` injection.
