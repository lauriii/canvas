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

Settings can be configured using:

1. Command-line arguments;
1. Environment variables;
1. A project `.env` file;
1. A global `.canvasrc` file in your home directory.

These are applied in order of precedence from highest to lowest. You can copy
the
[`.env.example` file](https://git.drupalcode.org/project/canvas/-/blob/1.x/cli/.env.example)
to get started.

| CLI argument      | Environment variable   | Description                                                   |
| ----------------- | ---------------------- | ------------------------------------------------------------- |
| `--site-url`      | `CANVAS_SITE_URL`      | Base URL of your Drupal site.                                 |
| `--client-id`     | `CANVAS_CLIENT_ID`     | OAuth client ID.                                              |
| `--client-secret` | `CANVAS_CLIENT_SECRET` | OAuth client secret.                                          |
| `--dir`           | `CANVAS_COMPONENT_DIR` | Directory where code components are stored in the filesystem. |
| `--scope`         | `CANVAS_SCOPE`         | (Optional) Space-separated list of OAuth scopes to request.   |

**Note:** The `--scope` parameter defaults to
`"canvas:js_component canvas:asset_library"`, which are the default scopes
provided by the Drupal Canvas OAuth module (`canvas_oauth`).

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

Build local components and Tailwind CSS assets.

**Usage:**

```bash
npx canvas build [options]
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
