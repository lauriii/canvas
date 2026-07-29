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

You can also provide the project name as an argument:

```bash
npx @drupal-canvas/create@latest my-project
```

Experimental features are documented in
[`docs/experimental.md`](docs/experimental.md).

### Options

| Option               | Description                                                                                                                                       |
| -------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------- |
| `--template -t`      | Template to use when scaffolding the project. One of the predefined templates in `templates.json` or a URL to a custom template's Git repository. |
| `--ref <ref>`        | Custom Git ref to use when cloning the template repository. For example, a branch name or a tag.                                                  |
| `--agents -a`        | Comma-separated list of additional agents to support, or `none` to skip compatibility symlink creation.                                           |
| `--site-name <name>` | Site name to slugify and suggest as the interactive project name.                                                                                 |
| `--site-url <url>`   | Canvas site URL to write to the generated `.env`. Takes precedence over `CANVAS_SITE_URL`.                                                        |

### Interactive and non-interactive input

A run is interactive when both standard input and output are attached to a
terminal. Non-interactive runs never prompt.

| Input        | CLI input                         | Interactive when omitted                         | Non-interactive when omitted            |
| ------------ | --------------------------------- | ------------------------------------------------ | --------------------------------------- |
| Project name | Positional `[project-name]`       | Prompted                                         | Error                                   |
| Template     | `--template`, `-t`                | Default frontend selected automatically          | Default frontend selected automatically |
| Site name    | `--site-name`                     | Used to suggest the project name                 | Ignored                                 |
| Site URL     | `--site-url` or `CANVAS_SITE_URL` | Prompted                                         | Optional; no `.env` when omitted        |
| Git ref      | `--ref`, `-r`                     | Template default applies                         | Template default applies                |
| Agents       | `--agents`, `-a`                  | Prompted when the template includes agent skills | Optional; compatibility is skipped      |

### Example

```bash
npx @drupal-canvas/create@latest my-project --template default
```

The previous `acquia-nebula` template ID remains supported as an alias.

Use a site name to suggest an editable project name when the positional project
name is omitted:

```bash
npx @drupal-canvas/create@latest --site-name 'My Drupal Site'
```

Provide the Canvas site URL with a flag:

```bash
npx @drupal-canvas/create@latest my-project \
  --site-url https://canvas.example.com
```

The `CANVAS_SITE_URL` environment variable can be used instead. The flag takes
precedence when both are provided:

```bash
CANVAS_SITE_URL='https://canvas.example.com' \
  npx @drupal-canvas/create@latest my-project
```

When neither value is provided, interactive runs prompt for the site URL.
Non-interactive runs continue without creating `.env`. When a URL is provided
and the template includes `.env.example`, the CLI copies it to `.env` and
updates or appends `CANVAS_SITE_URL`. Otherwise, it creates a new `.env`. If
`.env` already exists, the CLI adds or fills `CANVAS_SITE_URL`; when a non-empty
value is already configured, it keeps that value. The CLI prints a message for
each outcome and ensures `.env` is listed in `.gitignore`.

Non-interactive runs require a positional project name. When multiple templates
are enabled, `--template` is also required. The `--site-name` option does not
replace the positional project name in this mode. Missing site URLs and agent
selections remain optional and do not prompt.

Explicitly skip additional agent compatibility symlinks:

```bash
npx @drupal-canvas/create@latest my-project --template default --agents none
```

Provide additional agent compatibility symlinks without prompting:

```bash
npx @drupal-canvas/create@latest my-project --template default --agents claude-code,roo
```

If `--agents` is omitted, the CLI keeps the current interactive prompt on TTY
runs. On non-interactive runs, it skips compatibility setup and prints a note.

### `agents` command

Set up compatibility symlinks for additional agent skills directories in an
existing project:

```bash
npx @drupal-canvas/create@latest agents
```

You can also provide the agents as an argument:

```bash
npx @drupal-canvas/create@latest agents claude-code,roo
```

Skip compatibility symlinks:

```bash
npx @drupal-canvas/create@latest agents none
```

## Development

Drupal Canvas Create is designed to be easily extendable with new templates.

**Templates** are predefined Canvas project starter codebases. Each template
references a Git repository that will be cloned to provide the initial codebase.
To add a template, edit `templates.json` in the package root.

A repository can contain multiple templates. Set the optional repository `path`
to use a directory within the cloned repository as the project root:

```json
{
  "id": "company-starter",
  "label": "company/canvas-starter",
  "repository": {
    "url": "https://github.com/company/canvas-templates.git",
    "ref": "main",
    "path": "templates/starter"
  }
}
```

The path must identify a directory within the repository. Files outside that
directory are not included in the created project.

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

⚠️ You must use `my-canvas-project` (provided as default value) as your project
name when running the script from a local directory. (Reasons are explained in
the `.gitignore` file where we had to ignore this directory.)

### Scripts

| Command      | Description                                                              |
| ------------ | ------------------------------------------------------------------------ |
| `start`      | Run the compiled CLI tool from the `dist` folder.                        |
| `dev`        | Compile to the `dist` folder for development while watching for changes. |
| `build`      | Compile to the `dist` folder for production use.                         |
| `type-check` | Run TypeScript type checking without emitting files.                     |
