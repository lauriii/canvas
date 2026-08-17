<!-- cspell:ignore smartling -->

# Translating Canvas content with TMGMT

Canvas ships a TMGMT integration that exposes the translatable component inputs
in a component tree as separate translatable strings. This document records how
to set that up against a real translation provider, and what does and does not
round-trip.

Verified on Drupal 11.4.4 / PHP 8.4 with the Smartling provider.

## What Canvas provides

Two hook implementations in `\Drupal\canvas\Hook\TmgmtHooks` wire Canvas into
TMGMT:

- `field_info_alter()` registers
  `\Drupal\canvas\Tmgmt\ComponentTreeFieldProcessor` for `component_tree` fields
  when `tmgmt_content` is enabled. This covers content entities such as
  `canvas_page`.
- `config_schema_info_alter()` registers
  `\Drupal\canvas\Tmgmt\ComponentInputsConfigProcessor` for
  `canvas.content_template.*.*.*` and `canvas.page_region.*`. Patterns are
  deliberately excluded because they are not translatable.

Both delegate to `\Drupal\canvas\Tmgmt\ComponentInputsTranslatablesExtractor`,
which walks a component instance's `inputs` as typed config and yields a
translatable string for every translatable leaf, at arbitrary nesting depth. A
single-value prop yields one string; a multi-cardinality prop yields one string
per item, for example `components|0|tags|0`, `components|0|tags|1`, and so on.

The two paths differ on write-back. For content entities,
`ComponentTreeFieldProcessor::setTranslations()` merges translated leaves back
into the raw `inputs` array at the exact nested position they were extracted
from, preserving untranslated sibling keys and the prop order dictated by the
component source's schema generator. For config entities,
`ComponentInputsConfigProcessor` only extracts; TMGMT saves the translation
through the prop's `form_element_class` and its `::setConfig()` method.

## Which props are sent to the translator

For component sources built on JSON Schema props — SDC and JS code components,
via `JsonSchemaPropsComponentSourceBase` — a prop is translatable when
`isExplicitInputTranslatable()` returns TRUE, which requires both:

- the prop's retained `string_shape` says the shape holds translatable text, and
- the prop is not populated by an entity reference.

Other component sources do not use that rule. Block inputs, for example, get
their translatability from config schema instead.

Only static prop sources are translatable. A prop populated by a non-static
source such as `EntityFieldPropSource` is translated at its source (the entity
field it evaluates), so `refineForInstance()` strips its `translatable` flag.

This is a shape-level rule, not a per-prop opt-in. Every value of a string-shaped
prop is sent, which includes values that are machine identifiers rather than
prose. See the "Known rough edges" section below and
[#3584178](https://www.drupal.org/i/3584178).

## Setup

Install the provider and its dependencies. For Smartling:

```
composer require 'drupal/tmgmt_smartling:^9.26'
drush en -y language content_translation tmgmt tmgmt_content \
  tmgmt_file tmgmt_extension_suit tmgmt_smartling
```

`tmgmt_content` covers content entities such as `canvas_page`. Add `tmgmt_config`
as well to translate the component trees in Content Templates and Page Regions —
without it, only the content-entity half of the integration is active.

Add a second language, then enable content translation for the entity type that
holds the component tree, for example:

```
drush language:add fi
drush eval '\Drupal::service("content_translation.manager")
  ->setEnabled("canvas_page", "canvas_page", TRUE);'
```

### Keep provider credentials out of exported config

`tmgmt.translator.smartling` stores `settings.user_id` and
`settings.token_secret` as plain config keys, so they are written into a config
export. Set them as config overrides in `settings.php` (or `settings.local.php`)
instead of typing them into the provider form:

```php
$config['tmgmt.translator.smartling']['settings']['user_id'] = getenv('SMARTLING_USER_ID');
$config['tmgmt.translator.smartling']['settings']['token_secret'] = getenv('SMARTLING_TOKEN_SECRET');
```

The stored config entity keeps empty strings while the plugin reads the real
values at runtime. A side effect worth knowing: the provider settings form reads
the un-overridden entity, so both fields render empty. That is intended — it
also means the secret never reaches the rendered HTML. Do not fill them in and
save, or the secret will be persisted into config.

### Visual context uploads

The Smartling connector can also upload a rendered view of the page so
translators see where each string appears. It does this by having Drupal fetch
its own page over HTTP — a one-time login URL followed by the page itself, with
assets inlined — and uploading that HTML.

Two settings matter, and neither has a usable default:

- `contextUsername` must name a real Drupal user with permission to view the
  content. When it is empty the connector falls back to the current account,
  which is anonymous under drush and cron, and the upload fails with
  `User with username "" was not found`.
- `context_skip_host_verifying` must be TRUE wherever the site's certificate is
  not trusted by the container doing the fetch, which includes most local
  development setups.

Context upload is optional. Translation works without it; only the translator's
visual reference is lost.

## Sending content

1. Go to `/admin/tmgmt/sources/content/canvas_page`.
2. Tick the pages to translate, pick a target language, and select
   "Request translation".
3. On the job checkout form, choose the provider and select
   "Submit to provider".
4. When the provider reports the translation is ready, open the job and select
   "Download".
5. Open the job item and select "Save as completed" to write the translation
   into the entity.

## What round-trips

Verified against a real Smartling project using a `canvas_page` with 21
component instances:

- Extraction produced 48 translatable strings from one page: the entity title,
  the path alias, and the translatable leaves of each component instance's
  inputs. That page happened to use only single-value props; a multi-cardinality
  prop would contribute one string per item.
- Nested props are extracted and written back at the correct depth, including
  props inside a single input key such as `description.value`.
- Rich text (`text/html`) props round-trip with their markup intact — `<p>`
  wrappers survive extraction, upload, download, and write-back.
- Non-translatable inputs are left untouched in the translation, and Canvas's
  symmetrical translation synchronizer keeps them equal to the default
  translation.

## Known rough edges

These are properties of the shape-level translatability rule, not bugs in the
provider integration.

**String-shaped machine identifiers are sent to translators.** A prop typed as a
plain string is translatable even when its value is an identifier the component
uses for lookup. On the page tested, nine of 48 strings were Lucide icon names
(`pen-tool`, `message-circle`, `shield-check`, …). A translator who renders those
into the target language breaks icon rendering. Tracked in
[#3584178](https://www.drupal.org/i/3584178), which proposes an alter hook so a
specific prop can opt out rather than every prop of that shape opting in.

**Link URIs are translatable.** `buttonLink.uri` and similar link props are sent
as strings, for example `internal:/register`. This is intentional — localized
link targets are a real requirement — but it does put internal paths in the
translator's hands.

**The path alias is translatable.** `path.0.alias` is extracted, so the
translated alias becomes the live URL for that language. Useful for localized
URLs, but a translator that mangles it changes where the page lives. Confirmed by
running a pseudo-translation, which turned `/about` into `[/~ábó~út]` and made
the Finnish page 404.

Together, roughly a third of the strings extracted from the page tested were
identifiers or URIs rather than prose. Budget for glossary or "do not translate"
rules on the provider side.

## Automated coverage

- `tests/src/Functional/ContentWithComponentTreeTmgmtUiTest.php` — full TMGMT UI
  flow for a content entity with a component tree.
- `tests/src/Functional/ConfigWithComponentTreeTmgmtUiTest.php` — the equivalent
  for config entities with component trees.
