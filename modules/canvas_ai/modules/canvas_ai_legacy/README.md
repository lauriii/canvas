## Drupal Canvas AI Legacy

> **Deprecated.** This module receives bug fixes only. It will not receive new
> features. Switch to the new Canvas AI chat by following the
> [change record](https://www.drupal.org/node/3628747).

This module contains the orchestrator-based Canvas AI chat that Drupal Canvas
AI shipped before the client-side orchestrated chat. Sites that had Canvas AI
installed before the switch get this module enabled by `drush updb`, so their
chat keeps working unchanged.

It provides:

- The orchestrator agent `canvas_ai_orchestrator` and the agents it calls:
  `canvas_page_builder_agent`, `canvas_template_builder_agent`,
  `canvas_title_generation_agent` and `canvas_metadata_generation_agent`.
- The function call tools those agents use.
- The chat backend at `/admin/api/canvas/ai-legacy`.

While this module is installed, Canvas shows the legacy chat. Editing one of
its agents shows a deprecation warning.

## Switching to the new chat

1. Uninstall this module: `drush pm:uninstall canvas_ai_legacy`. Canvas then
   shows the new chat, which is provided by Drupal Canvas AI.
2. Uninstalling deletes the five agents listed above, including any changes
   made to them.
3. On sites that deploy configuration, export it after `drush updb` and again
   after uninstalling this module. Otherwise the next configuration import
   reverts the change.

See the [change record](https://www.drupal.org/node/3628747) for details.
