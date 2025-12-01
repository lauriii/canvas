# Drupal Canvas ESLint Config

ESLint config for validating Drupal Canvas Code Components.

## Config variants

| Config        | Description                                                                                                                                                                                                                                                                                                                                                                                                                     |
| ------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `required`    | Base settings for parsing JS/JSX files, YAML parsing for `component.yml` files, and custom rules for Drupal Canvas Code Component validation. Automatically used by [`@drupal-canvas/cli`](https://www.npmjs.com/package/@drupal-canvas/cli) when validating, building, or uploading components.                                                                                                                                |
| `recommended` | `required` + recommended rules from [`@eslint/js`](https://www.npmjs.com/package/@eslint/js), [`eslint-plugin-react`](https://www.npmjs.com/package/eslint-plugin-react), [`eslint-plugin-react-hooks`](https://www.npmjs.com/package/eslint-plugin-react-hooks), [`eslint-plugin-jsx-a11y`](https://www.npmjs.com/package/eslint-plugin-jsx-a11y), and [`eslint-plugin-yml`](https://www.npmjs.com/package/eslint-plugin-yml). |
| `strict`      | `recommended` + strict rules from [`eslint-plugin-jsx-a11y`](https://www.npmjs.com/package/eslint-plugin-jsx-a11y).                                                                                                                                                                                                                                                                                                             |

## Usage

```bash
npm install -D @drupal-canvas/eslint-config
```

```js
// eslint.config.js
import { recommended as drupalCanvasRecommended } from "@drupal-canvas/eslint-config";
import { defineConfig } from "eslint/config";

export default defineConfig([
  ...drupalCanvasRecommended,
  // ...
]);
```

## Rules

The following custom rules are part of the `required` config and validate Drupal
Canvas Code Components:

| Rule                     | Description                                                                               |
| ------------------------ | ----------------------------------------------------------------------------------------- |
| `component-dir-name`     | Validates that component directory name matches the `machineName` in component.yml.       |
| `component-exports`      | Validates that component has a default export.                                            |
| `component-files`        | Validates that component directory contains only allowed files.                           |
| `component-imports`      | Validates that component imports only from supported import sources and patterns.         |
| `component-no-hierarchy` | Validates that all component directories are at the same level with no nesting hierarchy. |
| `component-prop-names`   | Validates that component prop IDs match the camelCase version of their titles.            |

## Development

The following scripts are available for developing this package:

| Command      | Description                                                              |
| ------------ | ------------------------------------------------------------------------ |
| `dev`        | Compile to the `dist` folder for development while watching for changes. |
| `build`      | Compile to the `dist` folder for production use.                         |
| `type-check` | Run TypeScript type checking without emitting files.                     |
| `test`       | Run tests.                                                               |
