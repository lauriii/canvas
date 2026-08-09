# Translating the editor UI

The Canvas editor UI is translated with Drupal's own string translation system.
There is no separate message catalog, no i18n library, and no translation files
in this repository. Strings you wrap show up on
`/admin/config/regional/translate` alongside every other string on the site, and
translators use the workflow they already have.

## Adding a translatable string

Call `Drupal.t()` directly. It is a global that core's `drupal.js` provides, and
the `canvas-ui` asset library depends on `core/drupal`, so it is always defined
before the editor bundle runs.

```tsx
<Button>{Drupal.t('Publish page')}</Button>
```

For a count, use `Drupal.formatPlural()`. The `@count` placeholder is filled in
for you, and the language's own plural rules pick the form, which is why this
replaces hand-written `item{n !== 1 ? 's' : ''}` suffixes.

```tsx
{Drupal.formatPlural(count, '1 conflict to resolve', '@count conflicts to resolve')}
```

For a value inside a sentence, pass placeholders rather than building the
sentence with concatenation or a template literal. Translators need the whole
sentence, and word order differs between languages.

```tsx
{Drupal.t('@selected of @total changes selected', {
  '@selected': selected.length,
  '@total': total,
})}
```

For a short or ambiguous label, add a disambiguation context, so a word like
"Published" can be translated one way as a status and another as a report that
something finished.

```tsx
{Drupal.t('Published', {}, { context: 'Canvas page status' })}
```

Placeholder prefixes behave as they do in PHP: `@` escapes the value, `%` also
wraps it in `<em>`, and `:` is for URLs. The editor UI renders the result as
text, so use `@`.

## Rules the extraction step imposes

Drupal finds translatable strings by running two regular expressions over the
built JavaScript file, `ui/dist/assets/index.js`, the first time a page attaches
it. It reads the shipped bundle, not the TypeScript source, and it is a text
scan rather than a parse. That puts four hard requirements on how calls are
written.

1. **Call `Drupal.t()` and `Drupal.formatPlural()` by name.** A helper such as
   `const t = (s) => Drupal.t(s)` makes every call site invisible to the
   scanner. This is the single most tempting mistake, because it looks like
   ordinary refactoring.
2. **Pass quoted string literals.** A template literal, a variable, or a
   constant defined elsewhere cannot be read. `Drupal.t(label)` extracts
   nothing.
3. **Write the disambiguation context as an inline object literal.** Sharing one
   `const context = { context: '…' }` across call sites is worse than useless:
   the string is still registered, but without its context, so the translation
   the UI looks up at runtime is one no translator was ever offered.
4. **Keep placeholder values in the arguments object, not in the source
   string.** The source string is the translation key, so it has to be identical
   on every render.

The build enforces all of this. `ui/lib/locale-extract.js` is a port of core's
scanner, and a Vite plugin runs it over both the source and the emitted bundle
after every production build. It fails the build when a call site cannot be
read, when a context is not inline, or when a string present in the source does
not survive into the bundle. Minification is safe today, verified against the
real output, but a toolchain upgrade that started rewriting these calls would
otherwise ship a release that silently offers translators nothing.

## How a translation reaches the browser

1. A page attaches the `canvas-ui` library. Locale's `hook_js_alter()`
   implementation passes every attached JavaScript file to
   `locale_js_translate()`, which scans files it has not seen before and records
   what it finds in the `locales_source` table.
2. Locale writes one JavaScript file per language containing every translated
   string of type `javascript`, and substitutes it for the placeholder asset in
   the `locale/translations` library. It assigns `window.drupalTranslations`.
3. `Drupal.t()` reads that global. Because the file holds every string for the
   language rather than only the ones a given page needs, a single-page
   application has all of its strings before it renders.

The file is regenerated when translations change and when new strings are
discovered. A newly wrapped string appears on the translation UI only after a
request has attached the bundle since the last cache flush, so run `drush cr`
and load the editor once if a string you just added is missing.

## Which language the editor uses

The editor renders in the **interface** language, negotiated exactly as the rest
of the admin UI is, including the "Account administration pages" method that
honors a user's own admin language preference. That method only applies to admin
routes, so the three routes that boot the editor are marked `_admin_route: TRUE`.

This is deliberately not the content language. A site whose content is German
still shows a Finnish-speaking editor a Finnish editor UI, while the page being
edited stays German. It is also not the browser language, which the interface
language negotiation may or may not consult depending on how the site is
configured.

## Scope

Only part of the editor is wrapped so far. The mechanism, the build check, and a
slice covering the publish and review flow and the page status badges are in
place; roughly 550 further strings across about 160 files still need wrapping,
which is mechanical follow-up work.
