# Canvas AI page context generation

A skill for coding agents, such as Claude Code or Codex. It studies existing
Canvas pages on a site and writes a page guideline: which component trees
serve each kind of section, the section order, and which backgrounds, text
and button styles go together.

The guideline is a Markdown file. Saved as an AI context item, it tells the
Canvas page agent how pages on this site are put together, so the pages the
agent builds match the rest of the site.

The skill reads the site through Drush. It changes the site only when you
agree: to create or update the context item, or to raise the AI Context token
limit.

## Requirements

- A Canvas site with a few existing pages. Three or more pages give the skill
  enough to work with.
- Drush, and a coding agent that can run shell commands from the project
  root.
- To use the guideline: the [AI Context](https://www.drupal.org/project/ai_context)
  module, connected to the page agent by the
  [canvas_ai_context](https://www.drupal.org/project/canvas_ai_context) recipe.

## Installing the skill

Copy this directory into the skills directory of your coding agent, in the
project root. For Claude Code:

```bash
mkdir -p .claude/skills
cp -R web/modules/contrib/canvas/modules/canvas_ai/.agents/skills/canvas-ai-page-context-generation .claude/skills/
```

## Generating a guideline

1. Find the IDs of the pages to study. Pages are listed at
   `/admin/content/pages`, and a page's ID is in its URL: `/page/<ID>`.
2. Start the coding agent in the project root and ask it to generate page
   guidelines, or invoke the skill by name. Give it the page IDs.
3. The agent writes the guideline to `canvas-page-guidelines/site.md`.
4. Apply the canvas_ai_context recipe if you have not. The agent offers to
   create the context item and gives you a prompt to try in the Canvas AI chat.
5. If a page the page agent builds is off, give the agent its page ID, the ID
   of a page it should look like, and what to change. The agent updates the
   guideline and the context item.

Roles that use Canvas AI need the `access published ai context` permission.
