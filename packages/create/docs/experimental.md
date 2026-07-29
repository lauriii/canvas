# Experimental features

Experimental features may change or be removed without notice.

## Headless frontend templates

Without `--experimental-headless`, the CLI automatically uses the default
frontend template when `--template` is omitted.

Pass `--experimental-headless` to show all frontend templates in one interactive
list:

```bash
npx @drupal-canvas/create@latest --experimental-headless
```

```text
Select a template
  Default frontend
  Next.js
  Astro
  Nuxt
  TanStack Start
```

The available experimental template IDs are:

| Template ID      | Label          |
| ---------------- | -------------- |
| `nextjs`         | Next.js        |
| `astro`          | Astro          |
| `nuxt`           | Nuxt           |
| `tanstack-start` | TanStack Start |

The experimental flag is only needed for interactive discovery. An experimental
template ID can be provided directly without it:

```bash
npx @drupal-canvas/create@latest my-project \
  --template nextjs
```

Non-interactive runs using `--experimental-headless` require both the positional
project name and `--template`, so they do not use the template prompt. Without
the flag, the default frontend is selected automatically.

Experimental templates set `experimental` in `templates.json`. They can share a
repository by setting a repository-relative `path`:

```json
{
  "id": "nextjs",
  "label": "Next.js",
  "experimental": true,
  "repository": {
    "url": "https://github.com/drupal-canvas/headless-templates.git",
    "ref": "main",
    "path": "nextjs"
  }
}
```
