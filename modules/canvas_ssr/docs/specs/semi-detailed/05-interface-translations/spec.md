# Interface Translations

## Purpose

A [component](../../../GLOSSARY.md#component) translates its text with
Canvas's `useTranslation()`, whose `t()` and `formatPlural()` work like
Drupal's. In the browser, Canvas gives each
[island](../../../GLOSSARY.md#island) the [interface
translations](../../../GLOSSARY.md#interface-translations) of the strings its
graph's components use, at `drupalSettings.canvasData.v0.translations`. A
component doesn't declare them, as it does [site and page
data](../04-site-and-page-data/spec.md). SSR gives an island the same
translations, so its markup is in the same language as the browser's.

This spec says what an island gets, and when. It's for component authors.

## Requirements

### Requirement: An island gets its graph's translations, as the browser does

On a Canvas that gives interface translations, a
[render](../../../GLOSSARY.md#render) SHALL set
`drupalSettings.canvasData.v0.translations` to what Canvas gives the browser:
the interface language's translations of the strings the graph's components
use, and the language's plural formula. A string with no translation renders
as written. Where none has one, the key SHALL be left unset, as Canvas leaves
it.

An island SHALL get no other graph's translations, even ones an earlier
island was given. A preview island SHALL get the translations of its drafts'
strings, since its drafts are what it renders.

#### Scenario: A translated string renders translated

- **GIVEN** a component that renders `t('Read more')`, and a translation of it
  in the interface language
- **WHEN** an island of it renders
- **THEN** the markup has the translation

#### Scenario: A plural renders by the language's formula

- **GIVEN** a component that renders `formatPlural(count, '1 file', '@count
  files')` for several counts, translated into a language with three plural
  forms
- **WHEN** an island of it renders
- **THEN** the markup has the form the language's formula picks for each count

#### Scenario: An untranslated string renders as written

- **WHEN** an island renders a string with no translation
- **THEN** the markup has the string as written

#### Scenario: A dependency's strings count for the whole graph

- **GIVEN** a root with no strings of its own that imports a component with a
  translated string
- **WHEN** an island of the root renders
- **THEN** the dependency's string renders translated

#### Scenario: An island gets no other graph's translations

- **GIVEN** a component whose string is translated, and a second component
  that renders the same string but doesn't list it among its strings
- **WHEN** an island of each renders, the first one first
- **THEN** the second renders the string as written

#### Scenario: A preview island gets its drafts' translations

- **GIVEN** a component with a draft that renders a different translated
  string
- **WHEN** an editor previews it
- **THEN** the markup has the draft's string, translated

### Requirement: Translations arrive with the render, like page data

The translations SHALL be set for the render alone, never while modules
[evaluate](../../../GLOSSARY.md#evaluate). A module evaluates once in a realm
and is shared by every graph that imports it, so what it kept would reach
islands of other graphs. As with page data, a module that first evaluates
during a render SHALL get the island's site data alone, and a read of the
translations while it evaluates SHALL throw. The island then falls back to
CSR, and its author SHALL be warned. `useTranslation()` reads them only while
a component renders, so this doesn't affect it.

#### Scenario: Reading them while such a module evaluates falls back to CSR

- **GIVEN** a component with a translated string that imports a module while it
  renders, which reads `v0.translations` at module scope
- **WHEN** an island of it renders
- **THEN** it falls back to CSR, with a warning naming the component

### Requirement: An island carries the translations' cacheability

An island whose graph has strings SHALL carry the `locale` cache tag and the
interface language cache context, as Canvas's island does, even when none of
the strings is translated, so that a translation added later reaches it. An
island whose graph has no strings SHALL carry neither on their account.

#### Scenario: A translated string carries the locale tag

- **WHEN** an island with a translated string renders
- **THEN** its cacheability includes the `locale` tag and the interface
  language context

#### Scenario: An untranslated string carries it too

- **WHEN** an island whose only string has no translation renders
- **THEN** its cacheability includes the `locale` tag and the interface
  language context

#### Scenario: A graph without strings doesn't

- **GIVEN** a component with no strings and no data dependencies
- **WHEN** an island of it renders
- **THEN** `drupalSettings` is `undefined`, and its cacheability doesn't include
  the `locale` tag
