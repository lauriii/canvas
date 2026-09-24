# ADR 21: Structured translation links in the headless content API

<!-- cspell:words hreflang contacto -->

Date: 2026-09-21

## Status

Accepted

## Context

A multilingual headless frontend needs language-switcher data. Drupal owns translation availability, access, aliases, and language negotiation; the frontend owns public URLs (ADR 18). HTML alternate links alone cannot describe availability or distinguish the negotiated language from the rendered fallback.

`fetchPage()` returns `route.translations`; `getPageData()` from `drupal-canvas` returns `mainEntity.translations`. Their translation entries share a response format, with an additional `external` field and different URL processing in `fetchPage()`.

## Decision

The routed page data returned by `fetchPage()` from `@drupal-canvas/headless/server` includes required `route.negotiatedLanguage` and `route.translations` fields. The former is the negotiated content-language ID; `route.entity.langcode` remains the rendered entity language. The translations list is empty on monolingual sites and routes without a canonical content entity. Otherwise it lists every enabled language, using the translation fields and switcher behavior of `getPageData()` from `drupal-canvas`:

- `langcode`, `name`, and `nativeName` identify the language and provide its localized and native display names.
- `translationAvailable` is true only when the translation exists and the requester may view it. Missing and denied translations both report false.
- `current` follows the requested/negotiated language, even when Drupal renders another language.
- `url` uses the available translation, or the supplied rendered entity when unavailable, in the entry's language URL form. This follows `getPageData()`'s fallback behavior; an unavailable entry's URL is not a guarantee of viewable content.

Headless entries also include `external`. Non-external URLs are site-relative Drupal request URIs with the installation base path removed, preserving language prefixes and query parameters. These are inputs to `fetchPage`. External URLs remain absolute and are not valid `fetchPage` input; this flag does not imply SDK domain-negotiation support.

For example, a French request that renders English can return these language fields:

```json
{
  "negotiatedLanguage": "fr",
  "translations": [
    { "langcode": "en", "name": "English", "nativeName": "English", "url": "/contact", "translationAvailable": true, "current": false, "external": false },
    { "langcode": "fr", "name": "French", "nativeName": "Français", "url": "/fr/page/1", "translationAvailable": false, "current": true, "external": false },
    { "langcode": "es", "name": "Spanish", "nativeName": "Español", "url": "/es/contacto", "translationAvailable": true, "current": false, "external": false }
  ]
}
```

The frontend maps Drupal URIs to public URLs: `/es/contacto` might become `https://example.es/contacto` or `/es-ES/contacto`. The original `/es/contacto` remains the input for fetching that translation from Drupal. On a Drupal site configured to select language with the `language` query parameter, the English headless link is `/contact?language=en`. The parameter name depends on the site's configuration.

`getPageData()` exposes canonical entity URLs generated with each entry's language option. Headless URL generation also applies Drupal's language-switch options in configured content-negotiation order, following interface negotiation where content delegates to it. It explicitly sets the configured language query parameter when session negotiation applies and removes the content API's editor-only preview settings for view mode, component, page variant, and language. These additional steps are headless-specific; ordinary language-selection parameters remain.

Every translation access decision, including denials, and every generated headless URL contributes cacheability. Responses also depend on language configuration and display names, negotiated content/interface/URL languages, request URI, and preview account access. Authorized previews can report translations as available that public requests cannot view.

Providing structured translations does not generate HTML `hreflang` links. Adding translation data to `fetchEntity()` and providing SDK public-URL mapping helpers are outside this decision.

## Consequences

Frontends can show all languages or filter by `translationAvailable`. After filtering, no entry is current when the requested language is unavailable. A frontend that instead highlights the rendered language compares entries with `route.entity.langcode`.

Using the same translation fields and switcher behavior as `getPageData()` also preserves its fallback URL behavior; unavailable links are not guaranteed to be viewable. Frontends still map Drupal request URIs to their own public URLs.
