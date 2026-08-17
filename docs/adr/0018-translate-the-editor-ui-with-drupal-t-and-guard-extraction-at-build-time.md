<!-- cspell:ignore Lingui -->

# 18. Translate the editor UI with Drupal.t(), and guard extraction at build time

Date: 2026-08-09

## Status

Accepted

## Context

The Canvas editor UI is English only. `ui/src` holds roughly 550 user-facing strings across about 160 files, none of them translatable: no `Drupal.t()` call, no i18n dependency, no message catalog. A site whose administrators work in Finnish gets a Finnish Drupal admin UI with an English page builder inside it.

Drupal already has a JavaScript translation system, and using it would mean the editor inherits string storage, the locale module, translator tooling, and interface language negotiation for free. The doubt was whether it can work here. Locale discovers translatable strings by scanning JavaScript, and Canvas ships TypeScript compiled and minified by Vite, so the assumption was that the scanner would be reading something other than what a developer wrote, and that a build-time extraction step would be needed to bridge the gap.

Separately, the editor's language was wrong regardless of mechanism. The routes that boot it do not declare themselves administrative, so a user's own administration language preference never applies to them.

## Decision

**1. Call `Drupal.t()` and `Drupal.formatPlural()` directly, with no wrapper and no extraction step.**

Locale does not read source. `LocaleHooks::jsAlter()` collects every attached asset of type `file` and `locale_js_translate()` runs `_locale_parse_js_file()` over each path. For Canvas that path is `ui/dist/assets/index.js`, the shipped bundle. The scanner is aimed at the built artifact by design, so TypeScript is irrelevant; only the shape of the emitted call matters.

The shape survives. Core's unmodified patterns, run in PHP over the real minified production bundle, find every string with its disambiguation context. Three properties of esbuild make it work, each checked rather than assumed: string literals pass through minification unchanged, substitution-free template literals are converted to plain quoted strings, and `Drupal` is a global read off `window` and so cannot be renamed. Scale is not a problem either: core's `.*?` patterns under the `s` modifier over a single 4.3 MB line take 0.06 s with no `PREG_BACKTRACK_LIMIT` error.

Delivery already suits a single-page application better than it suits ordinary pages. `_locale_rebuild_js()` writes *every* translated JavaScript string for a language into one file, not the subset a page needs, and assigns it to `window.drupalTranslations` before `drupal.js` loads. The editor has all its strings before it renders.

**2. Accept the constraint this puts on call sites.**

Because the scanner is a text match rather than a parse, three things are forbidden: a helper such as `const t = (s) => Drupal.t(s)`, which makes every call site invisible; a template literal or variable argument, which extracts nothing; and a disambiguation context passed as a shared variable, which is worse than omitting it, because the string is registered without the context while the runtime looks it up with one.

The ergonomic cost is a slightly longer call at each site. The alternative costs a UI that looks translatable and offers translators nothing.

**3. Fail the production build when a translatable string cannot reach Drupal.**

The conclusion above is contingent on toolchain behavior that nothing promises to preserve. If a future esbuild started hoisting repeated strings into variables, the build would still succeed, the editor would still run, and translators would simply stop being offered new strings until someone noticed a release had shipped untranslated.

`ui/lib/locale-extract.js` ports core's scanner to JavaScript and a Vite plugin runs it after every production build, failing on an unreadable call site, a context that is not an inline literal, or a source string missing from the bundle. The port is faithful rather than better, including core's quirk of requiring a non-word character before `Drupal`: a more permissive port could report a string as discoverable that Drupal itself would miss, which is the one error this check must not make.

**4. Mark the editor's boot routes administrative.**

`LanguageNegotiationUserAdmin::getLangcode()` returns a langcode only when `isAdminPath()` is true, which delegates to `AdminContext::isAdminRoute()`, whose whole implementation reads the `_admin_route` route option. `canvas.boot.app`, `canvas.boot.empty`, and `canvas.boot.entity` now set it. Everything downstream already worked: `AssetResolver::getJsAssets()` passes the interface language into `hook_js_alter()` and keys its cache on the langcode, and `CanvasController::buildHtml()` derives `<html lang>` and `dir` from a rendered stub.

No client-side language handling was added. The editor does not ask what language it is in; it renders the strings the page it was served on carries.

## Consequences

Translators get the editor UI in the tooling they already use. Existing site translations of common words apply to it without any import. Languages with more than two plural forms are handled, because `Drupal.formatPlural()` uses the language's plural formula from the locale database.

Developers must write calls in a shape a regular expression can read, which is unusual for a modern TypeScript codebase and will surprise people. The build check is what makes that survivable: the constraint is enforced rather than documented and hoped for. `docs/react-codebase/translation.md` states the rules.

Marking the boot routes administrative makes the active theme the admin theme. Component previews were built from the active theme's libraries and now resolve the default theme explicitly, so a preview keeps showing what a visitor would see. This was a latent bug independent of the route change.

A newly wrapped string is offered to translators only after a request has attached the bundle since the last cache flush, because locale scans lazily and caches what it has parsed in state.

Only a slice of the UI is wrapped: the publish and review flow and the page status badges, chosen to exercise plain strings, plurals, interpolation, and disambiguation context. Roughly 550 strings remain, and wrapping them is mechanical.

## Alternatives considered

**A typed `t()` wrapper.** The obvious ergonomic improvement, and the one thing that definitively breaks extraction. Verified: in a fixture of eight call sites, the two behind a wrapper and a template literal were exactly the two the scanner missed.

**A third-party i18n library** (react-i18next, FormatJS, LinguiJS). Real advantages: ICU MessageFormat with gender and select, AST-based extraction with no constraint on how calls are written. Rejected because leaving Drupal string storage means translators lose `/admin/config/regional/translate`, lose importing and exporting the site's translations as one set, lose localize.drupal.org, and lose the fact that a site's existing translations already apply. It would also mean a second translation system inside a Drupal module, with its own catalogs, build step, and answer to language negotiation, for a UI whose users are by definition already in the Drupal admin UI. The functional gap that might justify it, complex plural rules, does not exist: `Drupal.formatPlural()` already uses the language's own formula.

**A separate strings endpoint for the SPA.** Unnecessary. Locale's per-language file already contains everything up front.
