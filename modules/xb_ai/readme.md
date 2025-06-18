# Experience Builder AI

## What is this
The Experience Builder AI is a collection of agents that can work together with Experience Builder to change components layout, JS and CSS based on a textual prompt or/and an image.

The UI is based on Deepchat (https://deepchat.dev/).

## Requirements
This is built on the [AI Agents](https://www.drupal.org/project/ai_agents) framework 1.1.x in Drupal. It also needs an provider to be installed, that utilizes function calling (most 1.1.x branches does).

## Installation
1. Setup XB according to this: https://git.drupalcode.org/project/experience_builder/-/blob/0.x/CONTRIBUTING.md
2. Install Vite `drush pm:en xb_vite`
3. Run the Experience Builder UI in hot load mode:
   ```shell
   npm run drupaldev
   ```
4. Install the Experience Builder AI module `drush pm:en xb_ai`
