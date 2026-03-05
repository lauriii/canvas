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
2. Follow the
   [configuration steps of the module](https://git.drupalcode.org/project/canvas/-/tree/1.x/modules/canvas_oauth#22-configuration)
   to set up a client with an ID and secret.

### Configuration

The Canvas CLI uses two types of configuration:

- **canvas.config.json** - Repository-committed configuration for values tied to
  your codebase structure (where files are stored, build output locations)
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
  "componentDir": "./components",
  "aliasBaseDir": "src",
  "outputDir": "dist"
}
```

**Properties:**

| Property       | Default         | Description                                                                                                               |
| -------------- | --------------- | ------------------------------------------------------------------------------------------------------------------------- |
| `componentDir` | `process.cwd()` | Directory where Code Components are stored in the filesystem.                                                             |
| `aliasBaseDir` | `"src"`         | Base directory for module resolution when using path aliases in your components. Tied to your project's import structure. |
| `outputDir`    | `"dist"`        | Build output directory (similar to Vite's `build.outDir`). Defines where compiled assets are generated.                   |

If `canvas.config.json` is not present, the CLI will use the default values
shown above.

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

| CLI argument      | Environment variable   | Description                                                                                                                                       |
| ----------------- | ---------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------- |
| `--site-url`      | `CANVAS_SITE_URL`      | Base URL of your Drupal site. Can point to different environments (local dev, staging, production).                                               |
| `--client-id`     | `CANVAS_CLIENT_ID`     | OAuth client ID. Different environments may have different OAuth clients with different permissions.                                              |
| `--client-secret` | `CANVAS_CLIENT_SECRET` | OAuth client secret. This is a secret credential that must never be committed to version control.                                                 |
| `--scope`         | `CANVAS_SCOPE`         | (Optional) Space-separated list of OAuth scopes to request. Tied to your specific Drupal site's OAuth configuration. Defaults to standard scopes. |

**Note:** The `--scope` parameter defaults to
`"canvas:js_component canvas:asset_library"`, which are the default scopes
provided by the Drupal Canvas OAuth module (`canvas_oauth`).

#### Configuration Precedence

The CLI uses different precedence rules depending on the type of configuration:

**For canvas.config.json properties** (`componentDir`, `aliasBaseDir`,
`outputDir`):

Configuration sources are applied in order of precedence from highest to lowest:

1. **Command-line arguments** (e.g., `--dir`, `--alias-base-dir`,
   `--output-dir`) - Highest priority
2. **canvas.config.json** - Values defined in your project's config file
3. **Default values** - Built-in defaults if nothing else is specified

Example: If you have `"componentDir": "./components"` in `canvas.config.json`
but run `npx canvas build --dir ./my-components`, the CLI will use
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

## Commands

### `download`

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

### @deprecated `build-d`

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
