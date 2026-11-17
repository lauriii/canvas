# Canvas SSR

## Purpose

Canvas SSR renders Canvas code [components](../../GLOSSARY.md#component) on the
server. Their HTML is then in the page the visitor first receives. Everything
Canvas's client-side rendering already does keeps working.

This spec is the product-level contract. It's the behavior you'd specify before
any implementation exists, and changing it would need an announcement. Each
requirement names the detailed spec that owns it. The detail lives there.

## Requirements

### Requirement: Islands render on the server when they can, and on the client as before when they can't

An [island](../../GLOSSARY.md#island) SHALL render on the server whenever its
component can run there. Otherwise it SHALL fall back to client-side rendering,
exactly as Canvas renders it without SSR. Turning on SSR SHALL NOT make a page
worse than having none.

#### Scenario: A component renders on the server

- **GIVEN** a published component of a supported
  [framework](../../GLOSSARY.md#framework)
- **WHEN** a visitor requests a page with an island of it
- **THEN** the response carries the component's markup

#### Scenario: Without a runner, every island renders on the client as before

- **GIVEN** a site with no JavaScript [runner](../../GLOSSARY.md#runner) that
  can run
- **WHEN** any island renders
- **THEN** it's exactly the island Canvas renders without SSR

#### Scenario: A component that fails to render falls back to the client

- **GIVEN** a component that throws while loading or rendering
- **WHEN** an island of it renders
- **THEN** it's exactly the island Canvas renders without SSR

Details: [01-island-rendering](../semi-detailed/01-island-rendering/spec.md).

### Requirement: Components are written for the browser, with no API of SSR's own

A component SHALL render on the server from the code it runs in the browser.
Its imports, asset URLs and `import.meta` SHALL mean what they mean in the
browser on the same page. The supported [web platform
APIs](../../GLOSSARY.md#web-platform-api) SHALL be present. No server-only API
SHALL be offered in their place. Code SSR refuses, though it works in the
browser, SHALL render on the client, with a warning that says what to change.
[ADR 12](../../adr/0012-ssr-may-refuse-code-that-works-in-the-browser.md) says
what that is, and why. Components of frameworks SSR has no renderer for SHALL
render on the client. Today SSR renders React and Preact.

#### Scenario: A component's imports and assets resolve as in the browser

- **GIVEN** a component that imports a pushed package, another component, and an
  image asset
- **WHEN** it renders on the server
- **THEN** it renders with both imports, and its markup has the image URL the
  browser would load

#### Scenario: A component of an unsupported framework renders on the client

- **WHEN** an island declares a framework SSR has no renderer for
- **THEN** it falls back to client-side rendering

Details: [02-module-resolution](../semi-detailed/02-module-resolution/spec.md),
[01-island-rendering](../semi-detailed/01-island-rendering/spec.md).

### Requirement: Components that need the browser hydrate; the rest ship no code

An island SHALL hydrate in the browser when any component in it marks itself as
needing the browser. Each framework has its own convention for that mark.
Otherwise the island SHALL be final markup, with no component code or script.

#### Scenario: A component that needs the browser hydrates

- **GIVEN** an island whose component, or a dependency of it, is a [client
  boundary](../../GLOSSARY.md#client-boundary)
- **WHEN** it renders
- **THEN** its server-rendered markup hydrates on load

#### Scenario: A static component ships no code

- **GIVEN** an island with no client boundary
- **WHEN** it renders
- **THEN** the page carries its markup alone

Details: [01-island-rendering](../semi-detailed/01-island-rendering/spec.md).

### Requirement: Server-rendered markup is what the browser would have rendered

The markup SSR produces for an island SHALL be what the component renders in the
browser from the same props, slots and data. So a static island looks as it
would client-rendered, and a hydrating one finds the DOM it expects. Text stays
text in both. A string given as a slot or a prop SHALL be escaped, never parsed
as markup. Markup that the browser would parse differently from what the
component renders there SHALL NOT be sent. The island SHALL fall back to CSR
instead, and a warning SHALL name the component.

#### Scenario: Text content is escaped

- **GIVEN** a slot or a prop whose value is a string of HTML
- **WHEN** the component prints it
- **THEN** it appears as escaped text, not as markup, as it would in the browser

#### Scenario: A script a prop would end early falls back to CSR

- **GIVEN** a component that writes a prop into a JSON-LD script, unescaped
- **WHEN** an island of it renders with a prop containing `</script>`
- **THEN** it falls back to CSR, where the script's content stays text

#### Scenario: A component that renders nothing renders nothing

- **GIVEN** a component whose render returns nothing
- **WHEN** an island of it renders
- **THEN** the island is empty, and it doesn't fall back to the client

Details: [01-island-rendering](../semi-detailed/01-island-rendering/spec.md).

### Requirement: Editors preview their drafts; visitors never see them

A [preview](../../GLOSSARY.md#preview) in the Canvas editor SHALL render each
component's unsaved [draft](../../GLOSSARY.md#draft), including a dependency's
draft. Every other render SHALL use published components only.

#### Scenario: A preview shows the draft, a live render the saved component

- **GIVEN** a component with an unsaved draft
- **WHEN** an editor previews it, and a visitor requests a page with it
- **THEN** the preview carries the draft's markup, and the page carries the
  published component's

Details: [01-island-rendering](../semi-detailed/01-island-rendering/spec.md).

### Requirement: Components reach Drupal data and the site itself, as the current user, and nothing else

A component SHALL read the Drupal data it declares, site-wide and page-specific,
and its strings' interface translations, through the same calls it uses in the
browser. Its `fetch()` SHALL reach only
the current site, and only by GET or HEAD. It SHALL reach only allowlisted
paths: JSON:API and menu linksets. It SHALL make requests as the current user.
Any other request SHALL be refused. The island SHALL then fall back to
client-side rendering, and never render without its data.

#### Scenario: Declared Drupal data renders

- **GIVEN** a component that declares the site name and the page title as data
  dependencies
- **WHEN** it renders on a page
- **THEN** the markup carries the site's name and that page's title

#### Scenario: Translated strings render translated

- **GIVEN** a component that translates its text with `useTranslation()`
- **WHEN** it renders where the interface language has translations of it
- **THEN** the markup carries the translations

#### Scenario: A fetch beyond the site is refused

- **GIVEN** a component that fetches another host, or a path no policy allows
- **WHEN** it renders
- **THEN** no request is made, and the island renders on the client

Details:
[04-site-and-page-data](../semi-detailed/04-site-and-page-data/spec.md),
[05-interface-translations](../semi-detailed/05-interface-translations/spec.md),
[06-same-site-fetch](../semi-detailed/06-same-site-fetch/spec.md).

### Requirement: Editor-written code cannot reach the server

SSR runs code the site's editors wrote, on the web server. That code SHALL NOT
be able to read any file, open any network connection, or load any module the
browser couldn't load. It SHALL have no Node.js API.

#### Scenario: An escape attempt gets nothing out

- **GIVEN** a component that tries to read a server file or reach the network,
  by any route
- **WHEN** it renders
- **THEN** it fails or renders without what it reached for, and no error message
  carries it either

Details: [10-deno-runner](../semi-detailed/10-deno-runner/spec.md).

### Requirement: A failing component never takes the page down

A component that fails SHALL fall back to client-side rendering. It SHALL leave
every other island on the page untouched. A failure in the component's own code
recurs, so its fallback SHALL be cached as a [render](../../GLOSSARY.md#render)
would be. A failure of the [runner](../../GLOSSARY.md#runner) may not recur. So
its fallback SHALL be cached for one minute, and server rendering tried again
after that.

#### Scenario: One failed island leaves the rest of the page as it was

- **GIVEN** a component that throws, followed on the page by one that works
- **WHEN** the page renders
- **THEN** the second island renders on the server in full

#### Scenario: A runner failure is retried within a minute

- **WHEN** the runner fails during a render
- **THEN** the island renders on the client for a minute, and on the server
  again after that

Details: [01-island-rendering](../semi-detailed/01-island-rendering/spec.md),
[03-realm-sharing](../semi-detailed/03-realm-sharing/spec.md).

### Requirement: What visitors see is current, and cached correctly

A saved change to a component, a pushed package, site configuration or fetched
content SHALL show in the next render of every island it affects. An island
SHALL carry the cacheability of everything it depended on. That way Drupal's
page caching invalidates it when any of that changes. No request SHALL see
another request's data, whatever is kept between them.

#### Scenario: An edited component shows on the next render

- **GIVEN** an island rendered from a component and its dependency
- **WHEN** the dependency is saved with new output
- **THEN** the next render of the island carries the new output

#### Scenario: Fetched content invalidates the island

- **WHEN** a component renders content it fetched from the site
- **THEN** the island carries that content's cache tags, so editing the content
  invalidates the island

#### Scenario: A request on another hostname gets its own site data

- **GIVEN** a site served under two hostnames
- **WHEN** a component reads the site URL on each
- **THEN** each render carries its own hostname

Details:
[09-render-performance](../semi-detailed/09-render-performance/spec.md),
[01-island-rendering](../semi-detailed/01-island-rendering/spec.md).

### Requirement: Once warm, server rendering costs little

Take a site that has rendered since its last change. There, a render SHALL read
no module source and no component's code, and SHALL find the base it needs in
one cache lookup. What the runner reads from its own caches is up to it. The
runner SHALL keep [evaluated](../../GLOSSARY.md#evaluate) modules from one
island to the next. None of this caching SHALL change what a render outputs.

#### Scenario: A warm render reads no module source

- **GIVEN** a site that has rendered since its last change
- **WHEN** an island renders in a later request
- **THEN** no module source or component code is read

Details:
[09-render-performance](../semi-detailed/09-render-performance/spec.md).

### Requirement: The runner is pluggable, and Deno is the default

Any Drupal module MAY supply a runner, and several MAY be enabled at once. The
highest-priority one that can run on this system SHALL render. One render SHALL
NOT span two runners. A Deno-based runner SHALL ship with Canvas SSR. It SHALL
need no service or extension beyond a Deno binary, by default at
`vendor/bin/deno`. [10-deno-runner](../semi-detailed/10-deno-runner/spec.md)
says what it needs of PHP and the file system.

#### Scenario: A runner module outranks the included runner

- **GIVEN** a [runner module](../../GLOSSARY.md#runner-module) with a higher
  priority, whose runner can run here
- **WHEN** a runner is selected
- **THEN** it's that runner module's runner

#### Scenario: Deno alone is enough

- **GIVEN** only the included runner and a usable Deno binary
- **WHEN** a runner is selected
- **THEN** it's the included runner

Details: [08-runner-selection](../semi-detailed/08-runner-selection/spec.md),
[07-runner-contract](../semi-detailed/07-runner-contract/spec.md),
[10-deno-runner](../semi-detailed/10-deno-runner/spec.md).
