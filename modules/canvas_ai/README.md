## Drupal Canvas AI
Drupal Canvas AI is an experimental module (hidden by default) that integrates a suite of AI-powered agents directly into the Drupal Canvas UI.
It acts as an "assistant" for front-end developers and site builders, allowing you to create/edit components based on a textual prompt or/and an image, create/edit page, create title and metadata.

The UI is powered by [Deepchat](https://deepchat.dev/), providing an intuitive and interactive chat interface for your commands. The backend leverages the powerful and extensible AI Agents framework for Drupal.

## Requirements
  1. [AI Agents](https://www.drupal.org/project/ai_agents) framework (^1.2@beta or higher).
  2. An AI Provider module that supports function calling. You can find the list of providers [here](https://www.drupal.org/project/ai/)
  3. A valid API key for your chosen AI provider, configured within Drupal.

## Installation
1. Setup Canvas according to the [Contribution Guide](../../CONTRIBUTING.md)
2. Install the Drupal Canvas AI module `drush pm:en canvas_ai`.
3. Configure providers at `/admin/config/ai/providers`.
4. Configure core AI settings at `/admin/config/ai/settings`.

## Contribution
See the [CONTRIBUTING.md](CONTRIBUTING.md) for contribution.
