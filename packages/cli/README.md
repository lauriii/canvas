# Drupal Canvas CLI

A command-line interface for managing Drupal Canvas code components, which are
built with standard React and JavaScript. While Drupal Canvas includes a
built-in browser-based code editor for working with these components, this CLI
tool makes it possible to create, build, and manage components outside of that
UI environment.

## Installation

```bash
npm install @drupal-canvas/cli
```

## Setup

1. Install the Drupal Canvas OAuth module (`canvas_oauth`), which is shipped as
   a submodule of Drupal Canvas.
2. Choose an authentication flow:
   - **Interactive login (recommended for individual developers):** Follow the
     [authorization code setup](https://git.drupalcode.org/project/canvas/-/tree/1.x/modules/canvas_oauth#23-interactive-login-with-canvas-login)
     and run `npx canvas login`. Tokens are stored in
     `~/.config/drupal-canvas/oauth.json` and used automatically — no
     environment variables needed.
   - **Client credentials (for CI/CD or service accounts):** Follow the
     [client credentials setup](https://git.drupalcode.org/project/canvas/-/tree/1.x/modules/canvas_oauth#22-configuration)
     and configure `CANVAS_CLIENT_ID` and `CANVAS_CLIENT_SECRET`.

### Configuration

The Canvas CLI uses three types of configuration:

- **canvas.config.json** - Repository-committed configuration for values tied to
  your codebase structure (where files are stored, build output locations)
- **canvas.brand-kit.json** - Optional Brand Kit (font) configuration. When
  Brand Kit sync is enabled, `canvas push` and `canvas pull` use it to sync
  fonts with the global Brand Kit. See
  [Font push (Brand Kit)](#font-push-brand-kit).
- **.env** - Environmental configuration and secrets that should not be tracked
  in version control (site URLs, OAuth credentials)

#### canvas.config.json (Optional)

This file is an optional configuration file that contains values tied to how
your codebase is structured and should be the same for all developers working on
the project. These values are committed to version control.

Create a `canvas.config.json` file in your project root with any of these
properties:

```json
{
  "componentDir": "src/components",
  "pagesDir": "pages",
  "contentTemplatesDir": "content-templates",
  "aliasBaseDir": "src",
  "outputDir": "dist",
  "globalCssPath": "src/global.css",
  "sync": {
    "pages": true,
    "contentTemplates": true,
    "regions": true
  }
}
```

**Properties:**

| Property                | Default               | Description                                                                                                               |
| ----------------------- | --------------------- | ------------------------------------------------------------------------------------------------------------------------- |
| `componentDir`          | `"src/components"`    | Directory where Code Components are stored in the filesystem. It must be inside `aliasBaseDir` for local builds.          |
| `pagesDir`              | `"pages"`             | Directory where pages are stored in the filesystem.                                                                       |
| `contentTemplatesDir`   | `"content-templates"` | Directory where content templates are stored in the filesystem.                                                           |
| `aliasBaseDir`          | `"src"`               | Base directory for module resolution when using path aliases in your components. Tied to your project's import structure. |
| `outputDir`             | `"dist"`              | Build output directory (similar to Vite's `build.outDir`). Defines where compiled assets are generated.                   |
| `globalCssPath`         | `"src/global.css"`    | Path to the global CSS file.                                                                                              |
| `sync.pages`            | `true`                | Include pages in `pull` and `push`. Set to `false` to exclude pages by default.                                           |
| `sync.contentTemplates` | `true`                | Include content templates in `pull` and `push`. Set to `false` to exclude content templates by default.                   |
| `sync.regions`          | `true`                | Include global regions in `pull` and `push`. Set to `false` to exclude global regions by default.                         |

If `canvas.config.json` is not present, the CLI will use the default values
shown above. For existing projects, if `globalCssPath` is not set and
`src/global.css` is missing, the CLI temporarily falls back to
`src/components/global.css` when that file exists. Move the file to
`src/global.css`, or set `globalCssPath` explicitly to keep the legacy location.

#### canvas.brand-kit.json (Optional)

Brand Kit (font) configuration lives in `canvas.brand-kit.json` in the project
root. When Brand Kit sync is enabled, `canvas push` and `canvas pull` use it to
sync fonts with the global Brand Kit. Example:

```json
{
  "fonts": {
    "defaults": {
      "weights": ["400"],
      "styles": ["normal"],
      "subsets": ["latin"]
    },
    "families": [
      {
        "name": "Inter",
        "provider": "google",
        "weights": ["400", "700"],
        "styles": ["normal", "italic"]
      },
      {
        "name": "My Font",
        "src": "fonts/MyFont-Regular.woff2",
        "weights": ["400"],
        "styles": ["normal"]
      }
    ]
  }
}
```

Font configuration lives in `canvas.brand-kit.json`. See
[Font push (Brand Kit)](#font-push-brand-kit) for the full schema.

#### Font push (Brand Kit)

When `canvas.brand-kit.json` is present and Brand Kit sync is enabled, the
`push` command will resolve each family (via a provider or a local file), upload
the font files to the site, and sync the font list to the global Brand Kit. Push
replaces the remote font set with the set from config; an empty `families` list
clears all fonts on the global Brand Kit. Fonts are stored on the Brand Kit
entity and generate `@font-face` CSS for the Canvas editor and front end. This
uses [unifont](https://github.com/unjs/unifont) for provider-based families.

For user-facing Brand Kit docs, see
[Code Components - Brand Kit](../../docs/user/src/content/docs/code-components/brand-kit.mdx).

**canvas.brand-kit.json shape:** The file has a top-level **`fonts`** key (other
brand kit keys may be added later). Under `fonts`:

- **`defaults`** (optional): Default `weights`, `styles`, and `subsets` applied
  to provider-based families when not overridden per family.
- **`families`**: Array of font family entries. Each entry is either:
  - **Provider-based:** `name` (required), `provider` (optional: `google`,
    `bunny`, `fontshare`, `fontsource`, `npm`, `adobe`), and optionally
    `weights` (array of strings, e.g. `["400", "700"]` or `["100 900"]` for a
    variable font range) and `styles` (array of strings, e.g.
    `["normal", "italic"]`). Aligns with [Nuxt Fonts](https://fonts.nuxt.com)
    (same unifont backend). Also optionally `subsets` and `axisDefaults` (for
    variable fonts, see below). When a family does not set `subsets`, only the
    `latin` subset is used (to avoid large variant counts).
  - **Local file:** `name` (required), `src` (path relative to project root,
    e.g. `fonts/MyFont.woff2`), and optionally `weights`, `styles` (arrays of
    one value each for a single variant), and `axisDefaults`.
  - **`axisDefaults`** (optional): For variable fonts, overrides the default
    value for an axis (e.g. `"axisDefaults": { "wght": 500 }`). Values are
    clamped to the axis min/max; omitted axes keep the font file’s default.
- **`providers`** (optional): Provider-specific options (e.g.
  `adobe: { id: ["your-kit-id"] }` for Adobe Fonts).

`fontsource` is the Fontsource CDN API; `npm` resolves `@fontsource/*` and
`@fontsource-variable/*` from `node_modules`. Variable font axes are extracted
when possible (e.g. from the font file) and stored on the Brand Kit. The CLI
adds human-readable axis names (e.g. "Weight", "Optical size") for common
OpenType axis tags so the Brand Kit UI shows the same CSS axes sliders and
labels as for fonts uploaded via the UI.

If you still have `CANVAS_COMPONENT_DIR` set in your shell, `.env`, or
`.canvasrc`, the CLI will warn you and offer to create or update
`canvas.config.json` with `componentDir`.

#### .env

This file contains environmental configuration that varies between environments
(local development, staging, production) and secrets that must never be
committed to version control.

Configuration sources are applied in order of precedence from highest to lowest:

1. Command-line arguments
2. Environment variables
3. Project `.env` file
4. Global `.canvasrc` file in your home directory

You can copy the
[`.env.example` file](https://git.drupalcode.org/project/canvas/-/blob/1.x/cli/.env.example)
to get started.

| CLI argument             | Environment variable               | Description                                                                                                                                                                                           |
| ------------------------ | ---------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `--site-url`             | `CANVAS_SITE_URL`                  | Base URL of your Drupal site. Can point to different environments (local dev, staging, production).                                                                                                   |
| `--client-id`            | `CANVAS_CLIENT_ID`                 | OAuth client ID. Different environments may have different OAuth clients with different permissions.                                                                                                  |
| `--client-secret`        | `CANVAS_CLIENT_SECRET`             | OAuth client secret. This is a secret credential that must never be committed to version control.                                                                                                     |
| `--scope`                | `CANVAS_SCOPE`                     | (Optional) Space-separated list of OAuth scopes to request. Tied to your specific Drupal site's OAuth configuration. Defaults to standard scopes.                                                     |
| _(none)_                 | `CANVAS_ACCESS_TOKEN`              | (Optional) Pre-issued Bearer token. When set, skips the OAuth client credentials flow entirely. `CANVAS_CLIENT_ID`, `CANVAS_CLIENT_SECRET`, and `CANVAS_SCOPE` are ignored. Must not be empty if set. |
| _(none)_                 | _(none)_                           | User tokens from `canvas auth login` are stored in `~/.config/drupal-canvas/oauth.json` (keyed by site URL) and used automatically. No environment variable is needed.                                |
| `--no-pages`             | `CANVAS_INCLUDE_PAGES`             | (Optional) Exclude pages from `pull` and `push`. `CANVAS_INCLUDE_PAGES` is deprecated; use `sync.pages` in `canvas.config.json` instead.                                                              |
| `--no-content-templates` | `CANVAS_INCLUDE_CONTENT_TEMPLATES` | (Optional) Exclude content templates from `pull` and `push`. `CANVAS_INCLUDE_CONTENT_TEMPLATES` is deprecated; use `sync.contentTemplates` in `canvas.config.json` instead.                           |
| `--include-brand-kit`    | `CANVAS_INCLUDE_BRAND_KIT`         | (Optional) Include brand kit (fonts) in `pull` and `push`. Defaults to `false`. Accepts `true`/`false`, `1`/`0`, or `yes`/`no`.                                                                       |
| `--no-regions`           | `CANVAS_INCLUDE_REGIONS`           | (Optional) Exclude global regions from `pull` and `push`. `CANVAS_INCLUDE_REGIONS` is deprecated; use `sync.regions` in `canvas.config.json` instead.                                                 |

**Note:** When `CANVAS_SCOPE` is unset, the CLI uses the `canvas_oauth`
defaults. With `--include-brand-kit` or `CANVAS_INCLUDE_BRAND_KIT`, it adds the
`canvas:brand_kit` scope. When pages, content templates, or global regions are
enabled through `sync` config, defaults, or deprecated env vars, it adds the
corresponding `canvas:page:*`, `canvas:content_template`, and
`canvas:page_region` scopes.

#### Configuration Precedence

The CLI uses different precedence rules depending on the type of configuration:

**For canvas.config.json path and build properties** (`componentDir`,
`pagesDir`, `contentTemplatesDir`, `aliasBaseDir`, `outputDir`,
`globalCssPath`):

Configuration sources are applied in order of precedence from highest to lowest:

1. **Command-line arguments** (e.g., `--dir`, `--alias-base-dir`,
   `--output-dir`) - Highest priority
2. **canvas.config.json** - Values defined in your project's config file
3. **Default values** - Built-in defaults if nothing else is specified

**For canvas.config.json sync properties** (`sync.pages`,
`sync.contentTemplates`, and `sync.regions`):

Configuration sources are applied in order of precedence from highest to lowest:

1. **Command-line arguments** (`--no-pages`, `--no-content-templates`, and
   `--no-regions`) - Highest priority
2. **canvas.config.json** - Values defined in your project's config file
3. **Deprecated sync environment variables** (`CANVAS_INCLUDE_PAGES`,
   `CANVAS_INCLUDE_CONTENT_TEMPLATES`, and `CANVAS_INCLUDE_REGIONS`) - Used only
   when the matching `sync.*` key is omitted from `canvas.config.json`
4. **Default values** - Built-in defaults if nothing else is specified

Example: If you have `"componentDir": "components"` in `canvas.config.json` but
run `npx canvas build --dir ./my-components`, the CLI will use
`./my-components`.

**For .env properties** (`siteUrl`, `clientId`, `clientSecret`, `scope`):

Configuration sources are applied in order of precedence from highest to lowest:

1. **Command-line arguments** (e.g., `--site-url`, `--client-id`) - Highest
   priority
2. **Environment variables** (e.g., `CANVAS_SITE_URL`, `CANVAS_CLIENT_ID`) - Set
   in your shell or CI/CD environment
3. **Project `.env` file** - Values defined in your project's `.env` file
4. **Global `.canvasrc` file** - Values in your home directory's `.canvasrc`
5. **Default values** - Built-in defaults if nothing else is specified

Example: If you have `CANVAS_SITE_URL=https://dev.example.com` in your `.env`
file but run `npx canvas download --site-url https://prod.example.com`, the CLI
will use `https://prod.example.com`.

## Supported Imports in Canvas Code Components

Canvas Code Components support the following import patterns. Unsupported
patterns are caught by the `drupal-canvas/component-imports` ESLint rule during
[`npx canvas validate`](#validate). See the
[imports and assets documentation](../../docs/user/src/content/docs/code-components/imports-and-assets.mdx)
for the full list of supported and unsupported patterns.

### Third-Party npm Packages

Any npm package installed in your project can be imported:

```js
import { motion } from 'motion/react';
import * as Accordion from '@radix-ui/react-accordion';
```

> **Important:** Third-party packages are bundled and uploaded as vendor
> artifacts. This requires using [`npx canvas push`](#push) — the deprecated
> `upload` command does not support third party imports.

### Shared Local Modules via `@/` Alias

Utilities and helpers can be imported from shared locations **outside** of any
component directory using the `@/` alias:

```js
import { formatPrice } from '@/lib/helpers';
```

> **Important:** Shared local imports are bundled and uploaded as artifacts.
> This requires using [`npx canvas push`](#push) — the deprecated `upload`
> command does not support local import bundling.

> **Note:** Importing from _within_ another component's directory (e.g.
> `@/components/pricing/helpers`) is not supported. Move shared code to a
> non-component location such as `@/lib/`.

### Other Canvas Code Components

Other Canvas Code Components can be imported using the `@/` alias:

```js
import Button from '@/components/button';
```

---

## Commands

### `download`

> 🚨 DEPRECATED: This command is deprecated. Please use the new
> `npx canvas pull` command instead. [See pull command here.](#pull)

Download components to your local filesystem.

**Usage:**

```bash
npx canvas download [options]
```

**Options:**

- `-c, --components <names>`: Download specific component(s) by machine name
  (comma-separated for multiple)
- `--all`: Download all components
- `-y, --yes`: Skip all confirmation prompts (non-interactive mode)
- `--skip-overwrite`: Skip downloading components that already exist locally
- `--skip-css`: Skip global CSS download
- `--css-only`: Download only global CSS (skip components)

**Notes:**

- `--components` and `--all` cannot be used together
- `--skip-css` and `--css-only` cannot be used together

**About prompts:**

- Without flags: Interactive mode with all prompts (component selection,
  download confirmation, overwrite confirmation)
- With `--yes`: Fully non-interactive - skips all prompts and overwrites
  existing components (suitable for CI/CD)
- With `--skip-overwrite`: Downloads only new components; skips existing ones
  without overwriting
- With both `--yes --skip-overwrite`: Fully non-interactive and only downloads
  new components

**Examples:**

Interactive mode - select components from a list:

```bash
npx canvas download
```

Download specific components:

```bash
npx canvas download --components button,card,hero
```

Download all components:

```bash
npx canvas download --all
```

Fully non-interactive mode for CI/CD (overwrites existing):

```bash
npx canvas download --all --yes
```

Download only new components (skip existing):

```bash
npx canvas download --all --skip-overwrite
```

Fully non-interactive, only download new components:

```bash
npx canvas download --all --yes --skip-overwrite
```

Download components without global CSS:

```bash
npx canvas download --all --skip-css
```

Download only global CSS (skip components):

```bash
npx canvas download --css-only
```

Downloads one or more components from your site. You can select components
interactively, specify them with `--components`, or use `--all` to download
everything. By default, existing component directories will be overwritten after
confirmation. Use `--yes` for non-interactive mode (suitable for CI/CD), or
`--skip-overwrite` to preserve existing components. Global CSS assets are
downloaded by default and can be controlled with `--skip-css` to exclude them or
`--css-only` to download only CSS without components.

---

### `pull`

Pull code components, global CSS, pages, content templates, and global regions
from Drupal to your local filesystem. Brand Kit fonts are only included when
explicitly enabled.

**Usage:**

```bash
npx canvas pull [options]
```

**Options:**

- `-d, --dir <directory>`: Component directory (defaults to `componentDir` from
  `canvas.config.json` or current working directory)
- `--no-pages`: Exclude pages from the pull operation
- `--no-content-templates`: Exclude content templates from the pull operation
- `--include-brand-kit [enabled]`: Include Brand Kit fonts in the pull operation
- `--no-regions`: Exclude global regions from the pull operation
- `-y, --yes`: Skip all confirmation prompts (non-interactive mode)
- `--skip-overwrite`: Skip items that already exist locally

**About prompts:**

- Without flags: Prompts for confirmation before pulling
- With `--yes`: Fully non-interactive (suitable for CI/CD)
- With `--skip-overwrite`: Skips items that already exist locally
- With both `--yes --skip-overwrite`: Fully non-interactive and only pulls new
  items

**Examples:**

Pull Code Components and global CSS:

```bash
npx canvas pull
```

Pull Code Components and global CSS without pages or content templates:

```bash
npx canvas pull --no-pages --no-content-templates
```

Pull Brand Kit fonts:

```bash
npx canvas pull --include-brand-kit
```

Pull only new items (skip existing):

```bash
npx canvas pull --skip-overwrite
```

Fully non-interactive, only pull new items:

```bash
npx canvas pull --yes --skip-overwrite
```

Pulls Code Components, global CSS, pages, content templates, and global regions
from your site by default. Use `--no-pages`, `--no-content-templates`, or
`--no-regions` to exclude those resources for a single run, or set `sync.*` in
`canvas.config.json` to change project defaults. Use `--include-brand-kit` or
`CANVAS_INCLUDE_BRAND_KIT=true` to include Brand Kit fonts. Use
`--skip-overwrite` to skip items that already exist locally.

**Fonts:** The pull command fetches fonts from the global Brand Kit, downloads
font files into a `fonts/` directory, and adds local `src` entries to
`canvas.brand-kit.json`. Matching is done at the variant level (family +
weight + style). Variants already present in your config (e.g., from a previous
push) are skipped, so push-then-pull is idempotent. New variants added via the
Canvas UI for a family you already have in config are downloaded and appended to
`families`. Requires `--include-brand-kit` or `CANVAS_INCLUDE_BRAND_KIT` which
will add the `canvas:brand_kit` OAuth scope.

---

### `scaffold`

Create a new code component scaffold for Drupal Canvas.

```bash
npx canvas scaffold [options]
```

**Options:**

- `-n, --name <n>`: Machine name for the new component

Creates a new component directory with example files (`component.yml`,
`index.jsx`, `index.css`).

---

### `build`

Build local components, vendor dependencies, and Tailwind CSS assets using
automatic component discovery.

**Usage:**

```bash
npx canvas build [options]
```

**Options:**

- `-d, --dir <directory>`: Directory to scan for components (defaults to
  `componentDir` from `canvas.config.json` or current working directory)
- `--alias-base-dir <directory>`: Base directory for module resolution (defaults
  to `"src"` from `canvas.config.json`)
- `--output-dir <directory>`: Build output directory (defaults to `"dist"` from
  `canvas.config.json`)
- `--no-tailwind`: Skip Tailwind CSS build
- `-y, --yes`: Skip confirmation prompts (non-interactive mode)

**Examples:**

Build all discovered components:

```bash
npx canvas build
```

Build components in a specific directory:

```bash
npx canvas build --dir ./my-components
```

Build with custom output directory:

```bash
npx canvas build --output-dir ./build
```

Build with custom alias base directory:

```bash
npx canvas build --alias-base-dir lib
```

Build without Tailwind CSS:

```bash
npx canvas build --no-tailwind
```

Non-interactive mode for CI/CD:

```bash
npx canvas build --yes
```

CI/CD without Tailwind:

```bash
npx canvas build --yes --no-tailwind
```

This command automatically discovers all components in the specified directory
(or `componentDir` from `canvas.config.json`) and builds them with Vite-powered
optimized bundling:

1. **Component Discovery** - Automatically finds all valid components using the
   discovery package
2. **Component Build** For each component, a `dist` directory will be created
   containing the compiled output. Additionally, a top-level `dist` directory
   (or configured `outputDir`) will be created, which will be used for the
   generated Tailwind CSS assets.
3. **Import Analysis** - Analyzes and categorizes third-party packages and local
   alias imports
4. **Vendor Bundling** - Uses Vite to create optimized bundles for third-party
   dependencies in `dist/vendor/` with proper code splitting and minification
5. **Local Import Bundling** - Uses Vite to bundle local alias imports (e.g.,
   `@/utils`) into `dist/local/`
6. **Tailwind CSS** - Generates Tailwind CSS for all components
7. **Manifest Generation** - Creates `canvas-manifest.json` with import maps for
   all bundled dependencies

The build output is optimized for production use with Vite's code splitting,
tree-shaking, and dependency management.

---

### `build-d`

> 🚨 DEPRECATED: This command is deprecated. Please use the new
> `npx canvas build` command instead. [See build command here.](#build)

Build local components and Tailwind CSS assets.

**Usage:**

```bash
npx canvas build-d [options]
```

**Options:**

- `-c, --components <names>`: Build specific component(s) by machine name
  (comma-separated for multiple)
- `--all`: Build all components
- `-y, --yes`: Skip confirmation prompts (non-interactive mode)
- `--no-tailwind`: Skip Tailwind CSS build

**Note:** `--components` and `--all` cannot be used together.

**Examples:**

Interactive mode - select components from a list:

```bash
npx canvas build
```

Build specific components:

```bash
npx canvas build --components button,card,hero
```

Build all components:

```bash
npx canvas build --all
```

Build without Tailwind CSS:

```bash
npx canvas build --components button --no-tailwind
```

Non-interactive mode for CI/CD:

```bash
npx canvas build --all --yes
```

CI/CD without Tailwind:

```bash
npx canvas build --all --yes --no-tailwind
```

Builds the selected (or all) local components, compiling their source files.
Also builds Tailwind CSS assets for all components (can be skipped with
`--no-tailwind`). For each component, a `dist` directory will be created
containing the compiled output. Additionally, a top-level `dist` directory will
be created, which will be used for the generated Tailwind CSS assets.

---

### `upload`

> 🚨 DEPRECATED: This command is deprecated. Please use the new
> `npx canvas push` command instead. [See push command here.](#push)

Build and upload local components and global CSS assets.

**Usage:**

```bash
npx canvas upload [options]
```

**Options:**

- `-c, --components <names>`: Upload specific component(s) by machine name
  (comma-separated for multiple)
- `--all`: Upload all components in the directory
- `-y, --yes`: Skip confirmation prompts (non-interactive mode)
- `--no-tailwind`: Skip Tailwind CSS build and global asset upload
- `--skip-css`: Skip global CSS upload
- `--css-only`: Upload only global CSS (skip components)

**Notes:**

- `--components` and `--all` cannot be used together
- `--skip-css` and `--css-only` cannot be used together

**Examples:**

Interactive mode - select components from a list:

```bash
npx canvas upload
```

Upload specific components:

```bash
npx canvas upload --components button,card,hero
```

Upload all components:

```bash
npx canvas upload --all
```

Upload without Tailwind CSS build:

```bash
npx canvas upload --components button,card --no-tailwind
```

Non-interactive mode for CI/CD:

```bash
npx canvas upload --all --yes
```

CI/CD without Tailwind:

```bash
npx canvas upload --all --yes --no-tailwind
```

Upload components without global CSS:

```bash
npx canvas upload --all --skip-css
```

Upload only global CSS (skip components):

```bash
npx canvas upload --css-only
```

Builds and uploads the selected (or all) local components to your site. Also
builds and uploads global Tailwind CSS assets unless `--no-tailwind` is
specified. Global CSS upload can be controlled with `--skip-css` to exclude it
or `--css-only` to upload only CSS without components. Existing components on
the site will be updated if they already exist.

---

### `push`

Build and push local components, global CSS, build artifacts, pages, content
templates, and global regions to Drupal. Brand Kit fonts are only included when
explicitly enabled.

**Usage:**

```bash
npx canvas push [options]
```

**Options:**

- `-d, --dir <directory>`: Directory to scan for components (defaults to
  `componentDir` from `canvas.config.json` or current working directory)
- `--no-pages`: Exclude pages from the push operation
- `--no-content-templates`: Exclude content templates from the push operation
- `--include-brand-kit [enabled]`: Include Brand Kit fonts in the push operation
- `--no-regions`: Exclude global regions from the push operation
- `-y, --yes`: Skip confirmation prompts (non-interactive mode)

**Examples:**

Push all discovered components:

```bash
npx canvas push
```

Push components without pages or content templates:

```bash
npx canvas push --no-pages --no-content-templates
```

Push Brand Kit fonts:

```bash
npx canvas push --include-brand-kit
```

Push components in a specific directory:

```bash
npx canvas push --dir ./my-components
```

Non-interactive mode for CI/CD:

```bash
npx canvas push --yes
```

This command discovers components, analyzes and bundles dependencies, builds
Tailwind CSS, and uploads the selected content to your Drupal site including:

1. **Components** - Built and uploaded as js_component config entities
2. **Global CSS** - Tailwind CSS assets uploaded as asset_library
3. **Fonts** - If `canvas.brand-kit.json` is present, fonts are resolved (via
   unifont or local `src`), uploaded, and synced to the global Brand Kit.
   Requires `--include-brand-kit` or `CANVAS_INCLUDE_BRAND_KIT` which will add
   the `canvas:brand_kit` OAuth scope. See
   [Font push (Brand Kit)](#font-push-brand-kit).
4. **Vendor artifacts** - Bundled third-party dependencies
5. **Local artifacts** - Bundled local imports (e.g., `@/utils`)
6. **Shared chunks** - Common code shared between vendor bundles
7. **Pages** - Canvas pages built from components, unless excluded with
   `--no-pages` or `sync.pages: false`.
8. **Content Templates** - Content templates that define component layouts for
   entity view modes, unless excluded with `--no-content-templates` or
   `sync.contentTemplates: false`.
9. **Global regions** - Theme global regions, unless excluded with
   `--no-regions` or `sync.regions: false`.

---

### `reconcile-media`

Upload external media referenced in local page specs and content templates to
Drupal and store provenance metadata so that pages and content templates can be
pushed.

When page specs or content templates contain image props with external URLs
(e.g. `https://example.com/photo.jpg`), they cannot be pushed directly because
Drupal expects a media entity reference. This command downloads each external
image, uploads it to Drupal as a media entity, and updates the local spec with
the resolved image data and provenance (`target_id`).

**Usage:**

```bash
npx canvas reconcile-media [options]
```

**Options:**

- `-y, --yes`: Skip confirmation prompts (non-interactive mode)

**Examples:**

Reconcile all external media in local pages:

```bash
npx canvas reconcile-media
```

Non-interactive mode for CI/CD:

```bash
npx canvas reconcile-media --yes
```

---

### `agents-context`

> **Experimental:** This command is experimental and may change in future
> releases.

Pull context for AI agents working on content templates and write it to
`.agents/drupal-canvas/`. The directory contains:

- `prop-sources.json` — for each entity bundle and component, the available
  field bindings agents can use as prop sources.
- `view-modes.json` — view modes available per entity type and bundle.
- `.gitignore` — ignores the generated files; the directory should not be
  committed.

**Usage:**

```bash
npx canvas agents-context [options]
```

**Options:**

- `--site-url <url>`: Site URL
- `--client-id <id>`: Client ID
- `--client-secret <secret>`: Client Secret
- `--scope <scope>`: Scope

**Example:**

```bash
npx canvas agents-context
```

---

### `login`

Log in to a Canvas site via browser using the OAuth 2.0 authorization code flow
with PKCE. Stores the resulting access and refresh tokens in
`~/.config/drupal-canvas/oauth.json` (keyed by site URL). After logging in,
`canvas push` and `canvas pull` use the stored token automatically.

**Usage:**

```bash
npx canvas login [options]
```

**Options:**

- `--site-url <url>`: Canvas site URL (prompted if not provided)
- `--client-id <id>`: OAuth client ID for the consumer configured in Drupal
  admin
- `--port <number>`: Local callback port (default: `4444`). The consumer's
  redirect URI must match: `http://localhost:<port>/callback`.

**Example:**

```bash
npx canvas login --site-url https://example.com --client-id my-cli-client
```

The CLI opens your browser to the Drupal login page, waits for authorization,
then saves your tokens locally. Requires the consumer to be configured for the
Authorization Code grant with `http://localhost:4444/callback` (or the port you
specify) as a redirect URI — see the
[canvas_oauth setup guide](https://git.drupalcode.org/project/canvas/-/tree/1.x/modules/canvas_oauth#23-interactive-login-with-canvas-login).

---

### `logout`

Remove stored credentials for a Canvas site from
`~/.config/drupal-canvas/oauth.json`.

**Usage:**

```bash
npx canvas logout [options]
```

**Options:**

- `--site-url <url>`: Canvas site URL to log out of (prompted if not provided)

**Example:**

```bash
npx canvas logout --site-url https://example.com
```

---

### `validate`

Validate local components using ESLint.

**Usage:**

```bash
npx canvas validate [options]
```

**Options:**

- `-c, --components <names>`: Validate specific component(s) by machine name
  (comma-separated for multiple)
- `--all`: Validate all components
- `-y, --yes`: Skip confirmation prompts (non-interactive mode)
- `--fix`: Apply available automatic fixes for linting issues

**Note:** `--components` and `--all` cannot be used together.

**Examples:**

Interactive mode - select components from a list:

```bash
npx canvas validate
```

Validate specific components:

```bash
npx canvas validate --components button,card,hero
```

Validate all components:

```bash
npx canvas validate --all
```

Validate and auto-fix issues:

```bash
npx canvas validate --components button --fix
```

Non-interactive mode for CI/CD:

```bash
npx canvas validate --all --yes
```

CI/CD with auto-fix:

```bash
npx canvas validate --all --yes --fix
```

Validates local components using ESLint with `required` configuration from
[@drupal-canvas/eslint-config](https://www.npmjs.com/package/@drupal-canvas/eslint-config).
With `--fix` option specified, also applies automatic fixes available for some
validation rules.
