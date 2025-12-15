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


Import the plugin in your Vite configuration:

```js
// vite.config.js
import drupalCanvas from '@drupal-canvas/vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react-swc';
import { defineConfig } from 'vite';

export default defineConfig({
  plugins: [react(), tailwindcss(), drupalCanvas()],
});
```
