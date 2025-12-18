# Drupal Canvas Create

CLI to scaffold a codebase for working with Drupal Canvas Code Components.

## Usage

Create a new project interactively:

```bash
npx @drupal-canvas/create@latest
```

```bash
yarn dlx @drupal-canvas/create@latest
```

```bash
pnpm dlx @drupal-canvas/create@latest
```

```bash
bunx @drupal-canvas/create@latest
```

You can also provide the app name as an argument:

```bash
npx @drupal-canvas/create@latest my-app
```

### Options

| Option          | Description                                                                                                                                                      |
| --------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `--template -t` | Template to use when scaffolding the app. One of the predefined templates (currently available: `canvas-cc-starter`) or URL to custom template's Git repository. |
| `--ref <ref>`   | Custom Git ref to use when cloning the template repository. For example, a branch name or a tag.                                                                 |

### Example

```bash
npx @drupal-canvas/create@latest my-app --template canvas-cc-starter
```

## Development

Drupal Canvas Create is designed to be easily extendable with new templates.

**Templates** are predefined application starter codebases. Each template
references a Git repository that will be cloned to provide the initial codebase.
To add a template, edit `templates.json` in the package root.

### Working with the codebase

First, build the project:

```bash
npm run build
```

Then you can execute the script locally:

```bash
npm start
```

Alternatively, use `npm run dev` to compile and watch for changes during
development.

⚠️ You must use `my-canvas-app` (provided as default value) as your app name
when running the script from a local directory. (Reasons are explained in the
`.gitignore` file where we had to ignore this directory.)

### Scripts

| Command      | Description                                                              |
| ------------ | ------------------------------------------------------------------------ |
| `start`      | Run the compiled CLI tool from the `dist` folder.                        |
| `dev`        | Compile to the `dist` folder for development while watching for changes. |
| `build`      | Compile to the `dist` folder for production use.                         |
| `type-check` | Run TypeScript type checking without emitting files.                     |
