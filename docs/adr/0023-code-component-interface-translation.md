# 23. Code Component interface translation

Date: 2026-11-04

## Status

Accepted

## Context

Code Components translate interface text with `t()` and `formatPlural()` from `useTranslation()`. Drupal must know each
component's source strings and contexts to register them with Locale and to deliver their translations. The strings can
only be found in the component's source code, and Drupal does not parse JavaScript: it stores the source code of the
code components that it renders, but not of the external components that a headless application renders. Locale stores a
context as ASCII text of at most 255 characters and joins plural forms with U+0003.

## Decision

### Extraction

The tools that have the source code extract the strings: the code editor and the Canvas CLI for the code components that
Drupal stores, and the Headless SDK when a headless application builds the external components that it renders.
Workbench extracts them to translate its previews. They extract the strings with one shared extractor using Babel's
scope analysis: the private `@drupal-canvas/translatable-strings` package, which their builds bundle. It runs in
browsers and Node.js, and reads no files: callers pass source code, and resolve imports. It follows the bindings that
`useTranslation()` from `drupal-canvas/react` returns, including aliases and member access, and respects shadowing. Only
quoted string literals are extracted, with a context only from a static string literal in the options object. Calls with
other expressions are skipped silently. Invalid literal values are errors: an empty string, U+0003, or a context that is
not ASCII or exceeds 255 characters.

The code editor and the CLI analyze the component's own source code only. Local modules such as helpers are not
analyzed: components pass them already translated text rather than `t`. Drupal delivers the strings of the components
that a component imports with it, from their own configuration. The Headless SDK and Workbench follow the registered
components that a component imports statically, directly or through other components, including cycles. The Headless SDK
does so because an external component renders the components that it imports itself, and Workbench because its previews
render them. The Headless SDK and Workbench supply component-import resolution to the shared analysis: they resolve each
import to the runtime file that it loads, and follow it only when that file is the entry file of a discovered component.
Helpers and other files are not followed, also when they shadow a component. Both resolve relative imports; the Headless
SDK also resolves the `paths` and `baseUrl` of the nearest `tsconfig.json` or `jsconfig.json` in the project, and
Workbench the `@/` aliases of the Canvas configuration that its previews use. Aliases that only a bundler defines, and
custom extension orders, are not resolved. Relative and config-path directory imports containing `package.json` are not
followed. Workbench’s Canvas aliases retain Canvas’s resolution behavior. When a component's entry file cannot be
parsed, or is not JavaScript or TypeScript, its strings are unknown, not empty. The analysis also tells whether the code
refers to `useTranslation()` at all, whatever its literals; this is unknown when a component cannot be parsed.

- The code editor reports invalid values, and saves the strings as unknown until they are fixed.
- The CLI fails to build a component with invalid values, and reports their file, line, and column.
- The Headless SDK extracts the strings of each component when it builds the component metadata, and fails the build
  with the same diagnostics. Entry files that it cannot parse, such as framework single-file components, have unknown
  strings.

### Storage

Every code component stores its strings in one `translatableStrings` property, a list of `{source, plural?, context?}`
items, deduplicated and sorted, whether Drupal renders the component or an external application does. An empty list
means the component has no strings, and `null` that they are unknown. Drupal validates the property against Locale's
storage rules wherever it receives it.

- Source code saved without the property makes the strings unknown.
- An external component's metadata replaces the strings when it includes the property, keeps the stored strings when it
  omits it, as older Headless SDKs do, and makes them unknown when it is `null`. Invalid strings reject the component's
  metadata. External components still cannot receive source code.
- An external component that retains a fallback implementation translates it with the strings of the external
  application's implementation, which can differ from the fallback's strings.

Configuration carries the property, so imported components keep their strings.

### Locale

Saving a code component registers its strings with Locale, so they can be translated before any page renders them.
Strings that no code component uses anymore are removed, unless they are translated or Drupal recorded another use of
them, so translators' work is never lost. Unknown strings change nothing in Locale.

### Delivery

Translations are delivered in the language of the rendered page or preview, with the language's plural formula, for the
strings of the rendered components and of the components they import. Untranslated strings show their source string.

#### Drupal-rendered pages

Drupal delivers the translations in the `translations` key of the Canvas context in `drupalSettings`, in the page's
interface language. It includes the translations of the code components that the rendered code components import,
because Drupal stores each component's strings separately. The page varies by interface language and is invalidated with
Locale's translations and the components' configuration.

#### Headless pages

The headless content API delivers the translations in the `translations` key of the page context, in the page's
interface language, for the rendered code components and external components. The response varies and is invalidated
like a Drupal-rendered page.

#### Workbench previews

Workbench looks up existing translations in Drupal for the strings of the previewed components, with its own
credentials, into an interface language of the site. The lookup only reads translations: it never registers strings with
Locale, so previews of unpublished components leave no trace in Drupal. Previews that cannot look up translations show
source strings, and tell why.

#### In components

`useTranslation()` uses the nearest `TranslationsProvider`, otherwise the Canvas context's translations.
`TranslationsProvider` replaces the context's translations without merging, so applications can use their own
dictionary; `null` means none. Only `@` placeholders are replaced, and the functions return text, not HTML.

## Consequences

- The editor, the CLI, the Headless SDK, and configuration import produce the same Locale strings without a server-side
  JavaScript parser.
- Aliases and shadowing are handled correctly; dynamic strings and contexts, the strings of helpers and other local
  modules, and calls of translation functions imported from other modules, are not translatable. Components translate
  text before they pass it to helpers.
- The editor bundle grows by Babel's traversal library, and the Headless SDK depends on Babel at build time.
- Writers that change source code outside these tools must update `translatableStrings`; components saved by writers
  that do not send it, such as older CLIs, are not translated, and older Headless SDKs keep the stored strings.
