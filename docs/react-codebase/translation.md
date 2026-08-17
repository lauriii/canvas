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
{Drupal.t('!selected of !total changes selected', {
  '!selected': selected.length,
  '!total': total,
})}
```

**Use the `!` prefix, not `@`.** This is the opposite of the advice you will
find for Drupal's PHP and jQuery code, and the reason is React. `@` runs the
value through `Drupal.checkPlain()` and `%` additionally wraps it in
`<em class="placeholder">`. Both are correct when the result is written into
the DOM as HTML, which is how Drupal's own JavaScript uses it. The editor
instead renders every translated string as a React text node, and React escapes
that on its own, so the entities arrive on screen literally: a page called
`Bob's` shows up as `Bob&#39;s`. `!` passes the value through unchanged and is
safe here precisely because React does the escaping.

The build fails on any `@` or `%` placeholder key for this reason. Inside
`Drupal.formatPlural()` the `@count` in the source string is exempt: core
injects it itself, and a count is a number, so nothing is escaped either way.

For a short or ambiguous label, add a disambiguation context, so a word like
"Published" can be translated one way as a status and another as a report that
something finished.

```tsx
{Drupal.t('Published', {}, { context: 'Canvas page status' })}
```

Note that `:` has no URL behavior in JavaScript, unlike PHP's `t()`. A `:url`
placeholder is treated like any other unrecognized prefix: escaped and wrapped
in `<em class="placeholder">`. Render links as JSX around the translated text
instead.

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
5. **Do not translate through a local `Drupal` binding.** A few modules do
   `const Drupal = getDrupal()` for other parts of the API. That binding is a
   local variable, so the minifier renames it, and the call disappears from the
   bundle even though the source looks right. Call the global directly, or move
   the string to a file without the alias.

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
of the admin UI is. This is deliberately not the content language: a site whose
content is German still shows a Finnish-speaking editor a Finnish editor UI,
while the page being edited stays German.

### Why URL negotiation cannot drive it

Drupal's default interface negotiation is URL prefixes only, and that method
cannot set the editor's language. Canvas redirects `/fi/canvas` to `/canvas`,
stripping the prefix, because the React router owns everything after `/canvas`
and Drupal's router cannot match those paths.

```
GET /fi/canvas  →  302  →  /canvas
```

That redirect predates this feature and is tracked in
[#3546597](https://git.drupalcode.org/project/canvas/-/work_items/3546597).
Until it is removed, the editor always receives a prefix-free path, so URL
negotiation resolves to the site default and the editor would only ever render
in that one language.

The practical consequence: a site must enable at least one interface
negotiation method that does not depend on the URL. "Account administration
pages" is the natural one, because the editor is an administrative UI and the
method exists precisely so administrators can work in their own language while
the site serves visitors in another.

### Configuring it

1. Install the **Language** and **Interface Translation** (`locale`) modules and
   add a language at `/admin/config/regional/language`.
2. At `/admin/config/regional/language/detection`, enable **Account
   administration pages** for *Interface text* and order it **above** URL. This
   is the step people miss; it is off by default.
3. Each user picks their language on their own profile, under **Administration
   pages language**. Users need the `access administration pages` or `view the
   administration theme` permission for the method to apply to them.
4. Translate at `/admin/config/regional/translate`. Editor strings appear there
   as ordinary JavaScript strings once the editor has been loaded at least once
   since the last cache rebuild.

Without step 2 the editor renders in the site default language, which is correct
behavior rather than a bug: no negotiation method has anything else to say.

Because the strings live in Drupal's own storage, a language you install already
translates much of the editor with no work at all. Words Drupal itself ships
translations for, such as "Components", "Cancel", "Save", and "Delete", arrive
translated the moment the language is added.

### Other methods that work

Anything that does not depend on the URL will do, and they compose in the order
set on the detection page:

- **Account administration pages** — the user's administration language.
  Recommended, and the only one scoped to administrative pages.
- **User** — the account's own preferred language, applied everywhere.
- **Session**, **Browser**, **Selected language** — also usable, though a
  browser-driven admin UI is rarely what a multilingual team wants.

## Scope

Most of the editor is wrapped. What is deliberately left:

- `getTimeAgo()` in `ui/src/components/review/utils.ts`, which builds relative
  times by string-replacing English `date-fns` output. That cannot be fixed by
  wrapping and needs a real date-formatting solution
  ([#3493779](https://git.drupalcode.org/project/canvas/-/work_items/3493779)).
- Developer-facing `throw new Error()` and `console.*` messages.
- Text the server sends already translated, and values a user typed.
- Strings in code the bundler drops, which the build check reports rather than
  letting them look translatable.
