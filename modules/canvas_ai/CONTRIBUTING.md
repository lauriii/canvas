# Contributing to Drupal Canvas AI

This guide provides instructions for setting up a local development environment for the Drupal Canvas AI module.

Contributing to Drupal Canvas AI requires a full development setup for the main Drupal Canvas module. This guide assumes you have already followed the [Drupal Canvas CONTRIBUTING.md](../../CONTRIBUTING.md) and have a working local environment.

We strongly recommend using [DDEV](https://ddev.com/get-started/) (version 1.24.0 or later) for a consistent and straightforward setup.

## Useful links
1. [Issue queue](https://www.drupal.org/project/issues/canvas?text=&status=Open&priorities=All&categories=All&version=All&component=AI)

## Development Workflow & Best Practices
Once your local environment is installed, please follow these best practices during development to ensure a smooth and efficient workflow.

1. Ensuring a Clean State:- Re-install After Branch Changes. Whenever you check out a new branch or pull significant changes, it's a good practice to reinstall the canvas_ai module.
This ensures that any changes to configuration, schemas, or dependencies are correctly applied.
```shell
# Use this command to re-install the module
ddev drush pmu canvas_ai && ddev drush en -y canvas_ai && ddev drush cr
```
2. Use Canvas module in hot reload mode to dynamically load changes made in Canvas AI module's frontend. Please make sure that you have `canvas_vite` module enabled.
```shell
# Use the below commands to run Canvas in hot reload mode.
cd web/modules/contrib/canvas/ui
npm install
npm run build
npm run drupaldev
```

## Managing AI Components via the UI
The AI Agents framework provides a powerful user interface for managing and testing your AI components directly within Drupal.

1. Configure Providers: Your AI provider (e.g., OpenAI, Gemini) credentials and model settings can be managed at: Path: `/admin/config/ai/providers`.

2. Add/Edit Agents: You can create new agents or modify the configuration of existing ones (like changing their instructions or assigned tools) at: `/admin/config/ai/agents`.

3. Test Tools in Isolation: To debug or test individual tools (the functions your agent can call) without running a full prompt, you can use the Tools Explorer at: `/admin/config/ai/explorers`. This requires enabling the AI API Explorer (`ai_api_explorer`) module.

