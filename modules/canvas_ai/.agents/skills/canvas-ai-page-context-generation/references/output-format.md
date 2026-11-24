# Guideline document format

## Contents

- File shape
- Template
- Field rules
- Checklist

## File shape

A file is Markdown front matter plus exactly one fenced `yaml` block, and nothing else. The front matter holds the context item's `label`, `description` and `purpose`. The YAML block is the item's content.

Order the YAML in three parts, as in the template: what the agent needs to plan the page, then a one-line index of every pattern, then the pattern trees. Each fact appears once: hex values only under `colors`, rich-text contexts only under `rich_text`.

## Template

~~~markdown
---
label: "Page guidelines: case study"
description: "Section patterns, section order, colors and item counts for case study pages."
purpose: "How case study pages on this site are built: section order, colors, and the component trees for every section. Load before planning or building a case study page, and load again before building if the plan was approved in an earlier message."
---
```yaml
kind: "page-guidelines"
applies_to: ["case-study"]

# Planning
structure:
  open: "Page title as large text, then an intro, outside any section"
  close: "Call to action on an accent background"
  orders:
    # Purposes, by their names in index.
    - ["intro", "challenge", "approach", "key-figures", "testimonial", "call-to-action"]
    - ["intro", "approach", "gallery", "call-to-action"]
rules:
  - "Alternate muted and default backgrounds; never two dark-tone sections in a row."
  - "A dark-tone background takes inverted text and a primary-inverted button."
  - "Wrap every section except the intro in a section component."
  - "In a tree, a child that repeats per item within one slot is shown once; repeat it for each item, within the pattern's repeat range."
  - "Never set sdc.example.card.style to \"outline\"; it renders nothing."
colors:
  # <component_id>.<prop>: { <value>: { tone, pair, hex } }
  sdc.example.section.background_color:
    muted: { tone: "light", pair: "default text, primary button", hex: "#F4F4F5" }
    accent: { tone: "dark", pair: "inverted text, primary-inverted button", hex: "#1F3A8A" }
spacing:
  sdc.example.section.padding_block_start: { "32": "32px", "64": "40-64px" }
rich_text:
  # <component_id>.<prop>: block | inline
  sdc.example.text.text: "block"
  sdc.example.heading.heading_text: "inline"
formats:
  # The tags each rich-text format allows on this site.
  block: "p, br, strong, em, u, a, ul, ol, li"
  inline: "strong, em, u, a"
typography:
  # <component_id>.<prop>: { <value>: "<px>, <role>" }
  sdc.example.text.text_size: { "xx-large": "40-60px, page title and figures", "large": "18px, intros" }
copy:
  - "Headlines are 3 to 7 words, sentence case, without a final period."
  - "Button labels are 2 to 4 words and start with a verb."

# Choosing: one line per pattern, grouped by content purpose
index:
  # One key per purpose; the template shows one.
  comparison:
    - { id: "comparison-a", use_when: "Two subjects, a short verdict each", avoid_when: "More than three subjects" }
    - { id: "comparison-b", use_when: "Three or more subjects, or long verdicts" }

# Building
patterns:
  comparison-a:
    look: "Muted (light) band with a centered H2 above equal framed cards in one row. Text only and evenly weighted, so no subject stands out; quieter than comparison-b."
    vary: { sdc.example.section.background_color: ["muted", "accent"] }
    repeat: { sdc.example.grid.main_slot: "2-3" }
    tree:
      - sdc.example.section:
          props: { background_color: "muted", section_header: true }
          slots:
            header_slot:
              - sdc.example.heading:
                  props: { level: 2, align: "center" }
            main_slot:
              - sdc.example.grid:
                  props: { columns: "3 columns" }
                  slots:
                    main_slot:
                      - sdc.example.card:
                          props: { style: "framed" }
  comparison-b:
    look: "Default background, one full-width horizontal card per subject, stacked. Room for a longer verdict beside each subject's image."
    source: "derived"
    repeat: { sdc.example.section.main_slot: "3-5" }
    tree:
      - sdc.example.section:
          props: {}
          slots:
            main_slot:
              - sdc.example.card:
                  props: { orientation: "horizontal" }
```
~~~

## Field rules

- Every pattern id in `index` has an entry in `patterns`, and the reverse.
- At most three patterns per content purpose. List the most typical first.
- `use_when` and `avoid_when` must tell the patterns of one purpose apart.
- `tree` uses the page agent's own placement shape: a YAML list; each entry `<component_id>:` with `props:` and `slots:`; each slot a list of the same shape. Write full component IDs. Write `props: {}` when no prop differs from its default.
- Write each observed tree from one section of one page: copy its components and props, drop the content. A tree that combines sections from several pages appears on no page. When the pages always use two consecutive top-level sections together (for example a heading container above the content container), the tree holds both.
- In `tree`, keep only props whose value differs from the default and carries a design decision. Omit text, URLs, image and media props, UUIDs and labels.
- A child that repeats per item within one slot (cards in a grid, figures in a band) is one entry in the tree.
- `repeat` gives the item count range for every slot in the tree where a child repeats, keyed `<component_id>.<slot>` of the component that owns the slot: `"2-4"`, or `"3"` when every page uses the same count. The range is what the pages use, narrowed to any hard limit in the component's source.
- Items in separate slots are not repeats: when a layout puts one card in each column slot, every slot keeps its entry.
- When one item differs from the others, such as an accordion whose first item is open, write that item first, then one entry for the rest. When items alternate, such as image left then right, write one of each and say in `look` that they alternate.
- `orders` lists purposes by their names in `index`.
- `typography` lists every text size value that the trees and rules use, with its px and its role. `copy` describes length, case and wording in a few lines; never quote the pages.
- `vary` keys are prop names. When more than one component in the tree has that prop, write `<component_id>.<prop>`. Each value list holds the values the pages use.
- Quote every string value. Leave numbers and `true`/`false` unquoted.
- `colors` is the only place a hex appears. It lists every surface prop that a tree or `vary` uses, and every value of it they use. Every value has `tone` (`light` or `dark`: how the surface reads), `pair` (the text and button prop values the pages use on it) and `hex`. Text, heading and button color values are not listed there; they appear in `pair` and `rules`.
- Write each pairing the pages follow as a rule in `rules`, stated by tone ("A dark-tone background takes…").
- Write each enum value that renders nothing, for a component in a tree, as a rule in `rules`: "Never set <component_id>.<prop> to \"<value>\"; it renders nothing."
- `rich_text` lists the rich-text props of the components in the trees. `formats` lists the tags each format allows on this site.
- `look` is one or two sentences describing the rendered result: layout, density, emphasis, image weight, and how it differs from the other patterns of its purpose. Name each surface color by its prop value with its tone in brackets (`muted (light) band`), never by hex. A surface a component always draws, with no prop for it, gets its tone in words (`dark card`). In `tree`, write only the prop value.
- `source: "derived"` marks a pattern not seen on any page; omit `source` for observed patterns.
- Never write page IDs, titles or copied content.
- Block patterns describe the block's job in `look` (for example "Latest 6 articles as a 3-column card grid").
- To stay under 10,000 tokens, cut patterns before trees: first those seen on one page, largest tree first, keeping at least one pattern per purpose. A site with few pages needs far fewer tokens; never pad a document.

## Checklist

Go through every item before handing over. Fix what fails and check again.

1. The file is front matter with `label`, `description` and `purpose`, then exactly one `yaml` block.
2. The YAML has `kind`, `applies_to`, `structure`, `rules`, `colors`, `index` and `patterns`.
3. Every `index` entry has a `use_when` and a matching pattern, and every pattern is in `index`.
4. Every pattern has `look` and `tree`.
5. Every component in a tree is in the page agent's catalog. Every prop and slot name exists in its details, every enum value is in its enum, and no prop equals its default.
6. Every key under `vary`, `colors`, `spacing`, `rich_text` and `typography` is a real prop, and every value listed for an enum prop is in its enum.
7. Every surface prop that a tree or `vary` uses has its key under `colors`, with every value used.
8. Every slot in a tree where a child repeats has a `repeat` range, and no range exceeds the largest count the pages use.
9. Every `orders` entry names purposes in `index`, and follows the top-level sections of a page, or the part several pages share.
10. Every observed tree matches one section of one page, with one entry per filled slot.
11. Every rule holds on every page. Go through the pages one by one. Where a page breaks a rule, say when the rule applies, or drop it.
12. No hex value appears outside `colors`.
13. The file holds no page IDs, titles or copied content.
