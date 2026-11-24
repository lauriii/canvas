---
name: canvas-ai-page-context-generation
description: Studies existing Drupal Canvas pages through Drush and writes a page guideline document for the Canvas page agent - what each component combination looks like, which prop values belong together, how many items each layout holds, the patterns for each content purpose with when to use each, and section order. Use when asked for page guidelines or AI context for the Canvas page agent, how a site's Canvas pages are built, or to refine a guideline after the page agent built a page that does not match.
---

# Canvas page guideline generator

Study existing Canvas pages on the site and write one guideline document per page type. Each document becomes one AI context item that the Drupal Canvas page agent lists and loads when it builds a page.

The page agent sees only a text catalog: component IDs, names, descriptions, props, enums and slots. It never sees a rendered page, so it cannot tell what a combination looks like, which prop values belong together, how many items a layout holds before it breaks, or which of several ways to show the same content suits a brief. You can read the templates, styles and pages; turn what they produce into words the page agent can act on.

The reference pages are evidence, not templates. Extract the patterns behind them: which component trees serve which content purpose, the patterns for each purpose, and the visual rules that hold across pages. The agent should build varied pages that fit the design system, not copies of the references.

Only read the site until the user agrees to a step in Next steps.

## Inputs

Ask only for what is missing:

- **Page IDs** — one or more Canvas page IDs, published or not. Required. Pages are listed at `/admin/content/pages`; a page's ID is in its URL, `/page/<ID>`.
- **Page types** — optional. Never ask for them and never require them.

Scope of the output:

- Default: one general document, `applies_to: ["any"]`, label `"Page guidelines"`, built from all pages together.
- Only when the user asks for per-type guidelines and says which page is which type: one document per type, `applies_to: ["<type>"]`, with the type in its label.
- When the default document mixes clearly different page types, still write one document, and suggest per-type documents in the final summary.

Fewer than three pages gives thin evidence. Say so in the final summary; do not refuse.

## Read the site

Run Drush the way this project runs it, for example `ddev drush` or `vendor/bin/drush`.

### Page structure

Run once per page:

```bash
drush php:eval '$p = \Drupal\canvas\Entity\Page::load(<ID>); $e = \Drupal::service(\Drupal\canvas\AutoSave\AutoSaveManager::class)->getAutoSaveEntity($p)->entity ?? $p; $o = []; foreach ($e->get("components") as $i) $o[] = ["inputs" => $i->getInputs()] + $i->getValue(); echo \Symfony\Component\Yaml\Yaml::dump($o, 6, 2);'
```

It prints the page as the editor shows it: the unpublished changes when there are any, otherwise the saved page. Each list item is one component, in page order. An item without `parent_uuid` is a top-level section; any other item sits in slot `slot` of the component whose `uuid` is its `parent_uuid`.

### What the page agent sees

The component catalog the page agent chooses from:

```bash
drush php:eval '\Drupal::service("account_switcher")->switchTo(\Drupal\user\Entity\User::load(1)); echo \Drupal::service("canvas_ai.component_context_helper")->getComponentCatalog();'
```

The props (type, default, enum, required) and slots of the components on the reference pages, in one call:

```bash
drush php:eval '\Drupal::service("account_switcher")->switchTo(\Drupal\user\Entity\User::load(1)); echo \Drupal::service("canvas_ai.component_context_helper")->getComponentDetails(["<component_id>", "<component_id>"]);'
```

Both commands print exactly what the page agent receives. Check every component ID, prop name, enum value and slot name you write against them. A component on the pages but not in the catalog cannot be placed by the page agent: never write it in a tree, and report it in the handover.

## Scope of the analysis

- **Only components on the reference pages.** Components that exist in the codebase but on no reference page are out of scope.
- **Image presentation, not images.** Record whether a pattern has an image and how it is placed (position, orientation, aspect). Never inspect media entities or image files, and never list media IDs or image sources.

## Inspect the components

Identify each component's source from its `component_id` prefix:

- `sdc.<extension>.<name>` — a single-directory component in a theme or module. Read its `.twig`, CSS and `.component.yml`. A component can rely on utility classes defined in the theme's global CSS; read that file then.
- `js.<name>` — a code component, stored as config: `drush config:get canvas.js_component.<name>`. `js.original` is the source, `css.original` its CSS, and imports from `@/components/<other>` are other code components. The CSS shared by all code components is in `drush config:get canvas.asset_library.global`.
- `block.<plugin_id>` — a Drupal block. Find what data it shows (a view display, a form, an entity) and the theme templates that style it.

From these sources, find for every prop value used on the pages:

- the color, spacing, radius and size it produces. Follow CSS variables to where they are set; a theme can also set them from its settings at runtime. Resolve every color to hex, writing a semi-transparent one as 8-digit hex. Mark a hex `~` when it comes from a framework's default palette rather than site CSS.
- which enum values the template renders nothing for. Never use those in a tree; write each as a rule. A value meant to add nothing, such as a `transparent` background, is not one of them.
- for every prop with `contentMediaType: text/html`, its `x-formatting-context` (`block` when absent).

Read the HTML the two rich-text formats allow on this site:

```bash
drush config:get filter.format.canvas_html_block filters.filter_html.settings.allowed_html
drush config:get filter.format.canvas_html_inline filters.filter_html.settings.allowed_html
```

## Analyze

Read each page's inputs this way:

- A value equal to the prop's default is not a design decision; leave it out.
- A string prop without an enum, rich text, a URL or a media value is content. Read it only to judge the content purpose and the copy style; never copy it into the output.
- A number without an enum can still be content, such as a key figure.
- CSS classes inside rich text, such as `text-align-center`, are design decisions.

Then:

A *section* is one top-level component and everything inside it. Its *purpose* is what the section does for the reader. A *pattern* is one way to build a purpose, and its *tree* is the component structure of that pattern.

1. **Purposes.** For each section, name its purpose in plain words (comparison, key figures, testimonial, call to action, article list…). Name the purpose of the content, not the component.
2. **Group by purpose across all pages.** Every distinct tree serving the same purpose is a pattern. Two trees with the same components that differ only in prop values are one pattern; note the other values in `vary`.
3. **Derived patterns.** You may add a pattern not seen on any page only by recombining components already on the reference pages, with prop values from their enums. Mark it `source: derived`. Never introduce an unseen component.
4. **Item counts.** For every slot where a child repeats per item (cards in a grid, items in a list, logos in a strip), record the smallest and largest count the pages use. Then check the source for a hard limit: a layout with a fixed number of column slots, a grid whose CSS sets the column count so extra items wrap, an item count prop. The observed range is the limit the page agent keeps to; when the source allows fewer than the pages show, the source wins.
5. **Page rules.** Opening and closing sections, typical orders, background rhythm between consecutive sections, spacing habits, and limits that hold on every page. A rule states what every page does. When some pages differ, say when the rule applies or leave it out.
6. **Visual tokens.** Every surface value on the pages: a value of a prop that sets what text sits on, such as a background color or an image overlay. Give its hex, its tone (`light` or `dark`), and the text and button prop values the pages pair with it. Take the pairing from the pages, not from judging the hex. Every spacing value used, resolved to px; when a value changes with the screen width, give the range from mobile to desktop (`"40-64px"`).
7. **Type scale.** Every value of a prop that sets a text size used on the pages, resolved to px, with the role it plays: page title, section heading, intro, figure, label.
8. **Copy style.** Without copying any of it: how long headlines, intros and button labels are in words, sentence or title case, and how labels and calls to action are worded.

## Write the document

Write to `canvas-page-guidelines/` in the project root, one file per context item: `site.md` by default, or `<type-slug>.md` per type. Follow the format in [references/output-format.md](references/output-format.md), then go through its checklist.

Keep each document under 10,000 tokens, estimated as characters divided by four.

## Summarize

End the analysis with:

- the files written, each with its label;
- what the evidence did not cover: purposes seen only once, derived patterns, item counts seen on only one page, hex values marked approximate, and enum values that render nothing;
- the page sections that no pattern covers, each by purpose and look, with the reason: cut to stay under 10,000 tokens, beyond the three patterns of its purpose, or built around a component missing from the catalog;
- the components on the pages that are not in the page agent's catalog, with the sections that use them. The page agent cannot place them until their component source is turned on for AI at `/admin/config/ai/canvas-ai-component-description-settings`.

## Next steps

Offer these one at a time. Change the site only after the user agrees to that step.

1. **Connect the page agent to AI context.** If this prints `null`, ask the user to apply the `canvas_ai_context` recipe, and wait until they have:

   ```bash
   drush config:get ai_context.consumers consumers.drupal_canvas_page_agent
   ```

2. **Create the context item.** Offer to create one item per file. Run this from the project root; it updates the item with the same label when one exists:

   ```bash
   cat canvas-page-guidelines/<file>.md | drush php:eval '\Drupal::service("account_switcher")->switchTo(\Drupal\user\Entity\User::load(1)); preg_match("/^---\n(.+?)\n---\n\x60{3}yaml\n(.+)\n\x60{3}/s", stream_get_contents(STDIN), $m); $f = \Symfony\Component\Yaml\Yaml::parse($m[1]); $s = \Drupal::entityTypeManager()->getStorage("ai_context_item"); $i = current($s->loadByProperties(["label" => $f["label"]])) ?: $s->create(); $i->set("label", $f["label"])->set("description", $f["description"])->set("purpose", $f["purpose"])->set("content", $m[2])->setPublished(); if ($i->hasField("moderation_state")) $i->set("moderation_state", "published"); $i->save(); echo "Context item ", $i->id(), ": ", $i->get("token_count")->value, " tokens\n";'
   ```

   Roles that use Canvas AI need the `access published ai context` permission.

3. **Raise the token limit.** When an item's token count is above `max_tokens` (`drush config:get ai_context.settings max_tokens`), the page agent receives the guideline cut short. Offer to raise it: `drush config:set ai_context.settings max_tokens <count> -y`.
4. **Try it.** Give the user one prompt per page type the guideline covers, to run in the Canvas AI chat on a new page. Build each from the purposes and orders the guideline found, with a made-up subject that fits the site, for example: "Build a case study page about a rebrand for a regional bakery: open with the challenge, show three key figures, and close with a call to action."
5. **Refine.** Tell the user: if the built page is off, share its page ID, the ID of a reference page it should look like, and what to change. Then follow [references/refine.md](references/refine.md).
