# Drupal Canvas ESLint Config

ESLint config for validating Drupal Canvas Code Components.

## Config variants

| Config               | Description                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            |
| -------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `required`           | Base settings for parsing JavaScript and TypeScript files, YAML parsing for component metadata files, and custom rules for Drupal Canvas Code Component validation. Automatically used by [`@drupal-canvas/cli`](https://www.npmjs.com/package/@drupal-canvas/cli) when validating, building, or uploading components.                                                                                                                                                                                 |
| `requiredDeprecated` | @deprecated Legacy version of the `required` config used by `build-d` command of [`@drupal-canvas/cli`](https://www.npmjs.com/package/@drupal-canvas/cli#deprecated-build-d).                                                                                                                                                                                                                                                                                                                          |
| `recommended`        | `required` + recommended rules from [`@eslint/js`](https://www.npmjs.com/package/@eslint/js), [`eslint-plugin-react`](https://www.npmjs.com/package/eslint-plugin-react), [`eslint-plugin-react-hooks`](https://www.npmjs.com/package/eslint-plugin-react-hooks), [`eslint-plugin-jsx-a11y`](https://www.npmjs.com/package/eslint-plugin-jsx-a11y), [`eslint-plugin-yml`](https://www.npmjs.com/package/eslint-plugin-yml) and [`typescript-eslint`](https://www.npmjs.com/package/typescript-eslint). |
| `strict`             | `recommended` + strict rules from [`eslint-plugin-jsx-a11y`](https://www.npmjs.com/package/eslint-plugin-jsx-a11y) and [`typescript-eslint`](https://www.npmjs.com/package/typescript-eslint).                                                                                                                                                                                                                                                                                                         |

## Usage

```bash
npm install -D @drupal-canvas/eslint-config
```

```js
// eslint.config.js
import { defineConfig } from 'eslint/config';
import { recommended as drupalCanvasRecommended } from '@drupal-canvas/eslint-config';

export default defineConfig([
  ...drupalCanvasRecommended,
  // ...
]);
```

## Rules

The following custom rules are part of the `required` config and validate Drupal
Canvas Code Components:

| Rule                   | Description                                                                                             |
| ---------------------- | ------------------------------------------------------------------------------------------------------- |
| `component-dir-name`   | Validates that `machineName` matches the directory name (index-style) or filename prefix (named-style). |
| `component-exports`    | Validates that component has a default export.                                                          |
| `component-prop-names` | Validates that component prop IDs match the camelCase version of their titles.                          |

### Deprecated rules

The following rules are deprecated and only used in the `requiredDeprecated`
config:

| Rule                     | Description                                                                               |
| ------------------------ | ----------------------------------------------------------------------------------------- |
| `component-files`        | Validates that component directory contains only allowed files.                           |
| `component-imports`      | Validates that component imports only from supported import sources and patterns.         |
| `component-no-hierarchy` | Validates that all component directories are at the same level with no nesting hierarchy. |

## Development

The following scripts are available for developing this package:

| Command      | Description                                                              |
| ------------ | ------------------------------------------------------------------------ |
| `dev`        | Compile to the `dist` folder for development while watching for changes. |
| `build`      | Compile to the `dist` folder for production use.                         |
| `type-check` | Run TypeScript type checking without emitting files.                     |
| `test`       | Run tests.                                                               |
