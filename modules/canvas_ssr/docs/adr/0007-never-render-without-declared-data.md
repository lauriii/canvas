# 7. A component never renders without the data it declared

Status: Accepted

## Context

A [component](../GLOSSARY.md#component) gets Drupal data in two ways. It can
declare keys of [site data](../GLOSSARY.md#site-data) and [page
data](../GLOSSARY.md#page-data), like the site name or the page title. And it
can call [`fetch()`](../GLOSSARY.md#same-site-fetch). Either one can be missing
on the server. No provider might give a declared key, or a fetch might be
refused or fail. The component would still run, but it would see `undefined`,
or an error, where it expected data.

## Decision

A component renders with all the data it declared, or it doesn't render on the
server at all.

- If no provider gives a declared key, the [island](../GLOSSARY.md#island)
  [bails](../GLOSSARY.md#bail). It goes to
  [CSR](../GLOSSARY.md#csr-fallback) before any JavaScript runs.
- A refused or failed fetch rejects, just as it would in the browser for a
  network error. The island falls back to CSR however the component handles
  that, since the browser's fetch may have worked. There's never a partial
  render with the fetched part missing.

## Alternatives considered

### Render with whatever's there

The component would run with `undefined` where a value should be, or an empty
response. Most would render something, but it would be wrong: markup missing
its data, which hydration has to fix, or static markup that stays wrong.

### Render an error

That's worse than CSR, which the visitor would have gotten without SSR.

## Consequences

- SSR can't handle a data key until a provider gives it. Until then, components
  that declare it render on the client, just as they did before SSR.
- The fallback is cached by the rule in ADR 2. A key with no provider is a fact
  about the site, so it's cached like a [render](../GLOSSARY.md#render). A
  fetch that failed other than by being refused or malformed might not fail
  again, so it's cached for a minute.
- Declaring a key a component doesn't need can cost the whole island its SSR, if
  nothing gives that key.

Specs: [site and page
data](../specs/semi-detailed/04-site-and-page-data/spec.md),
[same-site fetch](../specs/semi-detailed/06-same-site-fetch/spec.md).
