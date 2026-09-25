# Drupal Canvas Vite Plugin

Vite plugin for developing Drupal Canvas Code Components.

## Usage

```sh
npm install -D @drupal-canvas/vite-plugin
```

Configure the following environment variables. You can place them in a `.env`
file.

| Environment variable    | Description                                                                       |
| ----------------------- | --------------------------------------------------------------------------------- |
| `CANVAS_COMPONENT_DIR`  | Directory where Code Components are stored in the filesystem.                     |
| `CANVAS_SITE_URL`       | Base URL of your Drupal site.                                                     |
| `CANVAS_JSONAPI_PREFIX` | Optional custom prefix for JSON:API requests. Drupal core defaults to `/jsonapi`. |

The plugin loads the site's public `/canvas/api/v0/site-data` endpoint once per
server (sending an OAuth token when `CANVAS_ACCESS_TOKEN` or the Canvas CLI
token store provides one) and exposes the result two ways: the
`virtual:drupal-canvas/site-data` module, which Canvas Workbench imports to
supply the `drupal-canvas` context providers, and `window.drupalSettings`
injected into the page for the legacy `getSiteData()`, `getPageData()`, and
`new JsonApiClient()` APIs. Build tooling can read the same data from the plugin
instance's `api.getCanvasSiteData()`.

Import the plugin in your Vite configuration:

```js
// vite.config.js
import { defineConfig } from 'vite';
import drupalCanvas from '@drupal-canvas/vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react-swc';

export default defineConfig({
  plugins: [react(), tailwindcss(), drupalCanvas()],
});
```
