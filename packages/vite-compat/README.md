# @drupal-canvas/vite-compat

Shared Vite compatibility helpers for Canvas Code Component runtimes (e.g.
`@drupal-canvas/workbench`) and build pipelines (e.g. `@drupal-canvas/cli`).

## Usage

Use this package in any Vite-powered Canvas runtime or build tool that needs
consistent **host import compatibility**. Here, _host_ means the project
directory where discovered Canvas components live.

```ts
import { defineConfig } from 'vite';
import {
  drupalCanvasCompat,
  drupalCanvasCompatServer,
} from '@drupal-canvas/vite-compat';

const hostRoot = process.cwd();

export default defineConfig({
  plugins: [
    ...drupalCanvasCompat({
      hostRoot,
      hostAliasBaseDir: 'src', // Optional. Already defaults to 'src'.
    }),
  ],
});
```

# The `drupalCanvasCompat` plugin factory

- Uses `@/` as the host alias prefix.
- Supports third-party imports such as `motion/react`.
- Supports host alias local imports such as `@/lib/utils`.
- Supports host alias image imports such as `@/components/hero/hero.jpg`.
- Supports host alias SVG imports.
- Supports side-effect CSS imports, including host styles such as
  `@/utils/styles/carousel.css`, and package styles such as `@fontsource/inter`.
- Enables SVG component imports through SVGR.
