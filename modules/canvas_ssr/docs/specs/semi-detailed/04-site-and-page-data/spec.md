# Site and Page Data

## Purpose

[Components](../../../GLOSSARY.md#component) read Drupal data through
[site data](../../../GLOSSARY.md#site-data) and [page
data](../../../GLOSSARY.md#page-data), which Canvas's `getSiteData()`,
`getPageData()` and `JsonApiClient` read from `drupalSettings.canvasData`, and
which Canvas's hooks `useSiteContext()`, `usePageContext()`,
`useJsonApiClient()` and `useDateFormatter()` get from it. A
component declares each key it reads among its [data
dependencies](../../../GLOSSARY.md#data-dependency), and in the browser Canvas
sets the value of each declared key. SSR sets the same values, in the same
place, for the same keys.

Canvas also puts [interface
translations](../../../GLOSSARY.md#interface-translations) there, which
components don't declare. [Their own
spec](../05-interface-translations/spec.md) covers them.

This spec says what a component can expect to find there, and when, and what its
[island](../../../GLOSSARY.md#island) carries as a result. It's for component
authors, and for anyone supplying site or page data from another module.

## Requirements

### Requirement: A component gets the site and page data its graph declares, and no more

A [render](../../../GLOSSARY.md#render) SHALL set the value of each key some
component in the graph declares, at the path Canvas uses in the browser:
`v0.branding` at `drupalSettings.canvasData.v0.branding`. It SHALL set no other
key of site or page data, so a component never reads data it didn't declare,
even data an earlier island was given. For a graph that declares nothing and
gets no interface translations, `drupalSettings` SHALL be `undefined`, and it
SHALL still render.

`drupalSettings` SHALL be the only [global](../../../GLOSSARY.md#global) SSR
sets for components, besides `__drupalCanvasRuntime`. As Canvas's island
script does on the page, SSR SHALL set it to `{ environment: 'drupal' }`
before any component's module evaluates, since Canvas's functions that read
the data throw without it.

#### Scenario: A graph that declares nothing renders

- **GIVEN** a component with no data dependencies
- **WHEN** an island of it renders
- **THEN** it renders, and its island carries none of the data's cacheability

#### Scenario: Canvas's functions know they're on a Drupal page

- **WHEN** a component reads `__drupalCanvasRuntime` at module scope and as it
  renders
- **THEN** it's `{ environment: 'drupal' }` both times

#### Scenario: Only the declared keys are set

- **GIVEN** three components: one declares a site data key, one declares a page
  data key, and one declares one of each
- **WHEN** each renders
- **THEN** each gets the keys it declared, and no others, even ones from the
  same group, such as another key `getSiteData()` reads

#### Scenario: A dependency's declaration counts for the whole graph

- **GIVEN** a root that declares nothing and imports a dependency that declares
  a page data key
- **WHEN** an island of the root renders
- **THEN** that key is set

#### Scenario: An undeclared read gets nothing, not the last island's data

- **GIVEN** two components with the same code that reads the site name, where
  only one declares the key
- **WHEN** an island of the declaring one renders, then an island of the other
- **THEN** the second renders an empty value, not the site name the first island
  saw
- **AND** its island carries none of the site data's cacheability

### Requirement: A declared key that no provider gives is a bail

If any component in the graph declares a key that no site or page data provider
gives, the render SHALL [bail](../../../GLOSSARY.md#bail) to CSR before invoking
anything. It doesn't render with the value missing.

#### Scenario: The root declares a key no provider gives

- **GIVEN** no provider gives a key the root declares
- **WHEN** an island of it renders
- **THEN** it falls back to CSR

### Requirement: The values belong to the current request, as the browser would get them

Site data SHALL reflect the site's configuration, theme and request. Page data
SHALL reflect the page the request is for. Each value SHALL have the same shape
the browser gets, not a stand-in. By default the values come from Canvas's own
`CodeComponentDataProvider`, as the browser's do.

A module that changes a value in the browser, through `hook_js_settings_alter`,
SHALL be able to give SSR the same value, with a provider for that key. Among
providers of one kind, the one with the highest priority SHALL win a key. A key
a page data provider gives SHALL be page data, even one Canvas treats as site
data.

#### Scenario: getSiteData() renders the site's data

- **GIVEN** a component that renders `getSiteData().branding.siteName`
- **WHEN** an island of it renders
- **THEN** the markup has the configured site name

#### Scenario: getPageData() renders the page

- **GIVEN** a request for a Canvas page, and a component that renders
  `getPageData().pageTitle`
- **WHEN** an island of it renders
- **THEN** the markup has that page's title

### Requirement: Canvas's hooks get what the browser gives them

In the browser, Canvas's island script gives `useSiteContext()`,
`usePageContext()` and `useJsonApiClient()` their values, built from
`drupalSettings.canvasData`. SSR SHALL give each hook the same value, built the
same way from the island's site and page data. So a hook whose data the
component didn't declare SHALL give `null`, as it does in the browser when
nothing on the page declared it. A preview island's JSON:API client SHALL read
working copies, as the browser's does. Each island SHALL get a client of its
own, since a client keeps what it learns from the responses it gets.

#### Scenario: The context hooks give the page and site data

- **GIVEN** a request for a Canvas page, and a component that renders
  `usePageContext().pageTitle` and `useSiteContext().branding.siteName`
- **WHEN** an island of it renders
- **THEN** the markup has that page's title and the configured site name

#### Scenario: The site context has the interface language

- **GIVEN** a Canvas that gives `v0.langcode`, and a component that declares it
- **WHEN** it renders `useSiteContext().langcode`, and a date with
  `useDateFormatter()`
- **THEN** the markup has the interface language's code, and the date in that
  language

#### Scenario: A hook gives null for data the component didn't declare

- **GIVEN** a component that declares only `v0.branding`
- **WHEN** it calls all three hooks
- **THEN** `useSiteContext()` gives the site context, and `usePageContext()`
  and `useJsonApiClient()` give `null`

#### Scenario: useJsonApiClient() fetches as the component's own client would

- **GIVEN** a component that fetches articles through `useJsonApiClient()`
- **WHEN** an island of it renders
- **THEN** the markup has an article's title, and the island carries the node
  list's tag

#### Scenario: A preview island's client reads working copies

- **WHEN** a preview island renders `useJsonApiClient().resourceVersion`
- **THEN** the markup has `rel:working-copy`

### Requirement: Module scope sees site data, and page data arrives with the render

While modules [evaluate](../../../GLOSSARY.md#evaluate) before a render, as a
base's and an invoke's static imports do, only site data SHALL be set, and it
SHALL be the current request's. Page data SHALL be set for the render alone,
because a module evaluates once in a realm, and what it keeps reaches later
islands in it, which may not declare page data. See
[render-performance](../09-render-performance/spec.md) for how that holds across
requests. A module that first evaluates during a render, as one imported with
`import()` can, SHALL get the island's site data alone too. A read of page data
while it evaluates SHALL throw rather than give `undefined`, whatever code reads
it, since other code that runs while the module awaits can't be told from the
module's own. The island then falls back to CSR, and its author SHALL be warned,
since the component works in the browser.

#### Scenario: Module scope sees the site data, and only a render sees the page data

- **GIVEN** a component that reads the site name and the page title at module
  scope, and reads the page title again while rendering
- **WHEN** an island of it renders on a Canvas page
- **THEN** module scope saw the site name but no page title, and the render saw
  the page title

#### Scenario: A module imported during the render sees only site data

- **GIVEN** a component that declares a site and a page data key, and imports a
  module while it renders that reads the site data key at module scope
- **WHEN** an island of it renders
- **THEN** the module reads the site data, and the render reads the page data

#### Scenario: Reading page data while such a module evaluates falls back to CSR

- **GIVEN** a component that imports a module while it renders that reads a page
  data key at module scope, and one that reads a page data key while a module it
  imported awaits at the top level
- **WHEN** an island of each renders
- **THEN** each falls back to CSR, with a warning naming the component
- **AND** the next island reads its page data

### Requirement: An island carries the cacheability of the data it was given

The island SHALL carry the cache tags and contexts of each value set for its
render, and none from the ones it wasn't given. That way, a change to the data
invalidates the islands that read it, and no others.

#### Scenario: Page data carries the page's cacheability

- **WHEN** an island that declares a page data key renders
- **THEN** its cacheability includes the page's cache tag, and the route and
  content language contexts

#### Scenario: The main entity carries who may view its translations

- **GIVEN** an article with an unpublished translation, and a component that
  imports one declaring `v0.mainEntity`
- **WHEN** it renders on the article's page for the translation's author
- **THEN** the markup says the translation is available
- **AND** its cacheability includes the `user` context of that access check,
  the content language context, and the cache tags of the language list and
  of language negotiation

#### Scenario: The interface language carries its own cache context

- **WHEN** an island that declares `v0.langcode` renders
- **THEN** its cacheability includes the interface language context

#### Scenario: Site data carries the site's and the theme's cacheability

- **WHEN** an island that declares a site data key renders
- **THEN** its cacheability includes the cache tags of the site configuration
  and of the active theme's settings
