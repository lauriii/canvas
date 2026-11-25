---
"@drupal-canvas/cli": minor
---

Add `canvas headless:add`, `canvas headless:remove`, and `canvas headless:list` to manage a Drupal site's registered headless frontends from the CLI.

- Use `headless:add <url> --default` to register a frontend as the default or make an existing frontend the default.
- Use `headless:remove` without a URL to select frontends interactively, or pass a URL and `--yes` for noninteractive removal.
- Use `canvas login --scope <scopes>` to request an explicit, space-separated list of OAuth scopes instead of the discovered or default scopes. Request `canvas:headless:frontends` to list, add, or remove frontends.
