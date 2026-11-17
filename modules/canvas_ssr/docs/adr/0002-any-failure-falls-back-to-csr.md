# 2. Any failure falls back to CSR, and the fallback is cached by why it failed

Status: Accepted

## Context

Canvas already renders every [island](../GLOSSARY.md#island) in the browser.
SSR improves on that, and there are lots of ways it can fail to happen:

- no [runner](../GLOSSARY.md#runner) is
  [available](../GLOSSARY.md#available)
- the [framework](../GLOSSARY.md#framework) has no [boundary
  checker](../GLOSSARY.md#boundary-checker)
- a declared key of [site](../GLOSSARY.md#site-data) or [page
  data](../GLOSSARY.md#page-data) has no provider
- the [component](../GLOSSARY.md#component) throws
- the [runner](../GLOSSARY.md#runner) fails
- a [`fetch()`](../GLOSSARY.md#same-site-fetch) is refused

Each case needs an answer, and they should all agree.

## Decision

Whatever goes wrong, the island becomes the [CSR
fallback](../GLOSSARY.md#csr-fallback). That's exactly the island Canvas
renders without SSR. Nothing else on the page changes. So turning SSR on never
makes a page worse than having no SSR.

How long the fallback is cached depends on why SSR failed:

- A [script failure](../GLOSSARY.md#script-failure) means the component's own
  code threw. It would fail again with the same inputs. So its fallback is
  cached the way a [render](../GLOSSARY.md#render) would be, on the same
  site and page data, fetches and import map.
- A [runner failure](../GLOSSARY.md#runner-failure), a fetch that failed other
  than by being refused or malformed, or a server error a fetch got, might not
  happen again. That's a [transient error](../GLOSSARY.md#transient-error), so
  its result is cached for a minute, or as briefly as the server error says, and
  SSR tries again after that. Drupal's page cache ignores max-age, so a page
  with one is made to expire with it, from the page cache and every other cache.
  A runner failure also [resets](../GLOSSARY.md#reset) the runner, so the next
  render starts clean.
- A refused or malformed fetch, a response whose cacheability isn't known, a
  component that can't be parsed within the parser's own limits, or a base a
  provider's module makes impossible, fails the same way every time. So does
  an island that goes past the memory limit with a
  [realm](../GLOSSARY.md#realm) to itself, where a retry would only fill the
  realm again. So each is cached the way a render would be. Only what may
  pass is a transient error: a parse that ran out of time, or of what PHP's
  `memory_limit` left the request, is one.

A render never spans two runners. If the selected runner fails, that render
falls back. It isn't retried on another runner.

## Alternatives considered

### Show something else

Like an error, or an empty island. Both are worse than what Canvas does without
SSR.

### Don't cache fallbacks

Then a buggy component would run and fail on every request that reached it, and
might kill a process each time.

### Cache every fallback the same way

Script failures recur and runner failures might not, so any single duration is
wrong for one of them. If a failed runner's fallback were cached as long as a
render, SSR would stay off long after the runner recovered.

### Retry on the next runner

We didn't, to keep selection simple: one render, one runner, one answer.

## Consequences

- A site with no runner is exactly Canvas without SSR. The tests check that the
  fallback matches byte for byte.
- A failing component costs one failed [invoke](../GLOSSARY.md#invoke) per
  cache miss, not one per request.
- The minute is a judgment call, not a measurement. It's long enough that a
  broken runner isn't retried on every request, and short enough that a
  recovered one is back soon.
- Runners have to tell the two kinds of failure apart. They throw
  `ScriptException` when the component's code fails, and anything else when they
  fail themselves.

Specs: [island rendering](../specs/semi-detailed/01-island-rendering/spec.md),
[runner selection](../specs/semi-detailed/08-runner-selection/spec.md).
