# 18. Per-page original language

Date: 2026-07-18

Issue: <https://www.drupal.org/project/canvas/issues/TBD>

## Status

Proposed

Composes with [17. Per-translation component tree forks (asymmetric translations)](0017-per-translation-component-tree-forks.md)

## Context

Canvas pages were always created in the site default language: both creation paths (`ApiContentControllers::post()`,
`AddPageController`) called `$storage->create()` without a `langcode`, and Drupal core filled in the site default.
Because the Canvas editor only edits the default translation (other languages are read-only previews) and the
symmetric translation model synchronizes from the default translation, this pinned authorable content to the site
default language. On an English-default site you could not author a page whose original language is German, and you
could not fix a page created in the wrong language.

Drupal core already provides the needed mechanics: `ContentLanguageSettings` (`default_langcode` drives creation
defaults through `DefaultLanguageItem`; `language_alterable` gates langcode field edit access through
`hook_entity_field_access()`), and `ContentEntityBase::onChange()` (retag of the default translation to any language
without an existing translation; `updateFieldLangcodes()` retags field data in place; throws for translations or
occupied target languages).

The editor loaded `/canvas/api/v0/layout/{type}/{id}` unprefixed, and core's `EntityConverter` upcasts via
`getTranslationFromContext()` (the negotiated content language). That is only correct while original language and site
default coincide; a non-default-original page with a site-default translation would silently edit the wrong
translation. Auto-save entries are keyed `{entity_type}:{id}:{langcode}`, so a wrongly-upcast write also produces
drafts under the wrong key.

## Decision

- Creation: the existing `POST /canvas/api/v0/content/{entity_type}` accepts an optional `langcode` body key,
  validated against the configured languages (400 on unknown). Omitted means core decides, honoring
  `ContentLanguageSettings.default_langcode`. Duplication ignores `langcode` (duplicates keep the source language).
  In the editor, the language switcher selection is the creation language. `AddPageController` stays unchanged (its
  default is site-builder-controlled via `ContentLanguageSettings`).
- Change: the existing `PATCH /canvas/api/v0/content/canvas_page/{id}` accepts `langcode`, delegating to core's retag
  semantics with guards running before the generic field loop so core's exceptions surface as structured responses:
  422 on non-default translations, 400 on unknown langcodes, 409 when a translation exists in the target language
  (delete it first; no "swap" — core forbids it), and 409 while any auto-save drafts exist in the translation group
  (publish or discard first; auto-save entries are intentionally not rekeyed). After the guards, `langcode` flows
  through the same per-field `access('edit')` check, `validate()`, and `save()` as other PATCH fields. No new route.
- Access: core's `ContentLanguageSettings.language_alterable` is the single gate, enforced through the langcode
  field's `edit` access. Canvas ships `config/optional/language.content_settings.canvas_page.canvas_page.yml`
  (`language_alterable: true`, `default_langcode: site_default`) plus an update hook guarded by `isNew()` so
  customized settings are never clobbered.
- Layout API: `translations.defaultLangcode` states the original language explicitly (clients must not infer it from
  ordering), and a per-language `set-default-language` link (the entity's PATCH URL, generated against the original
  language so it carries the right URL prefix) advertises eligibility: configurable, not the current original, no
  existing translation, langcode field `edit` access. The client renders the affordance purely from link presence.
  Forked translations (ADR 17) are still translations, so the no-existing-translation rule already excludes forked
  siblings; fork links and the `set-default-language` link coexist in the same per-language links structure.
- Editor correctness: the client pins entity-scoped editor API requests (layout GET/POST/PATCH, entity form, content
  auto-save, entity PATCH) to the entity's original language by prepending its configured URL language prefix whenever
  the original differs from the site default. This is applied centrally in the RTK base query against anchored
  relative-URL patterns — not per call site — because a single missed construction site would produce auto-save
  entries keyed to the wrong langcode. A per-entity registry of original langcodes is seeded at boot from
  `drupalSettings.canvas.entityDefaultLangcode` and updated from every layout response, so it stays correct across
  client-side navigation; the layout query includes the known original language in its cache key, so learning it
  after an unprefixed first fetch triggers a prefixed refetch. Only languages with a configured URL prefix are
  prefixed, and prefixes are only exposed when URL-path-prefix negotiation is active, so the client never builds URLs
  the router cannot resolve. Server-generated links are self-contained (generated with an explicit language) and are
  never re-prefixed.
- Language switcher: the "(Default)" marker and the editor-vs-preview routing derive from
  `translations.defaultLangcode` (falling back to the site default), not the site-level `isDefault` flag. "Set as
  default language" appears where the link exists, confirms via dialog, PATCHes `{langcode}`, surfaces the server's
  409 message in the dialog, and fully reloads the editor on success (the editable language, links, and auto-save
  keys all changed).

## Consequences

- Pages can be authored in, and retagged to, any configured language; the editor stays correct for pages whose
  original language differs from the site default regardless of how they were created (JSON:API and migrations
  included).
- `language_alterable: true` exposes core's language selector on `canvas_page` entity forms that render the langcode
  widget. Accepted: administrative surface, site-configurable, and the same switch disables the Canvas affordance and
  API capability (config-level rollback).
- `content_translation_source` on pre-existing translations goes cosmetically stale after a retag (core behavior).
- Consumers holding per-langcode state outside entity storage must reconcile in their own entity update hooks; a
  language change is a normal entity save (no new event). canvas_translate (separate repo) discards its draft and
  approval entries for the language that became the source.
- Sites without URL-path-prefix language negotiation cannot pin editor requests by URL; there the editor remains
  correct only while the negotiated content language matches the original language. A server-side 409 on unprefixed
  writes against non-default-original pages is a candidate follow-up.
- The OpenAPI PATCH request schema became a dedicated partial-update schema (`PatchContentRequestBody`): the previous
  reuse of the creation schema required all creation fields on every PATCH, which a `langcode`-only PATCH (and the
  controller's actual partial-update semantics) contradicts.
