# 5. Bindings are synchronous and carry strings only

Status: Accepted

## Context

A [component](../GLOSSARY.md#component) sometimes needs PHP in the middle of
a [render](../GLOSSARY.md#render). Its
[`fetch()`](../GLOSSARY.md#same-site-fetch) calls, for example, are answered
by Drupal. So a [runner](../GLOSSARY.md#runner) has to let PHP install a
function in the [realm](../GLOSSARY.md#realm). Calling it has to cross
whatever boundary the runner has. That might be a function call into an embedded
engine, or a round trip over a pipe to another process.

Over a pipe, it's natural for a bound function to return a promise. An embedded
engine returns values directly. If runners did both, components could tell them
apart. For example, an unhandled rejection would fail the
[invoke](../GLOSSARY.md#invoke) on one runner and not on the other.

## Decision

A [binding](../GLOSSARY.md#binding) is synchronous. The bound function
returns its value right where it's called. If the PHP callback throws, the call
throws there too. `fetch()` still returns a promise, because its deserializer
wraps the answer in one. That's how `fetch()` works, not how bindings work.

Only strings cross between PHP and JavaScript. `bindFunction()` takes a name, a
PHP callback and two JavaScript function expressions:

- One turns the call's `this` and arguments into the string PHP receives.
- The other turns the string PHP returns into the result.

`canvas_ssr` writes these functions once, and every runner uses them. So the
order of the arguments is part of the contract.

Binding a name that's already bound does nothing. So the renderer binds before
every render, and it only costs something after a
[reset](../GLOSSARY.md#reset).

## Alternatives considered

### Bindings that return promises

They'd let the runner's design leak into how components behave. They'd also need
a table of pending calls and in-flight bookkeeping in the runner. Synchronous
calls need none of that. A call writes, waits on the one reader, and returns.

### Structured values across the boundary

Then every runner would need its own converter for each type, and they'd drift
apart. A string is the one thing every boundary already carries. The conversions
live in JavaScript that every runner runs unchanged.

### Let each runner define its bindings

Each runner would then have its own `fetch()`. But a runner owns the realm, its
limits and how it loads modules, and knows nothing about Drupal. `fetch()`
belongs to the render pipeline, next to the cacheability it produces.

## Consequences

- A slow binding blocks the realm while it runs. A runner bounds the whole call,
  bindings included, so a slow `fetch()` uses up the component's time. The Deno
  runner counts it only toward its wall-clock limit, not toward what the JS may
  spend.
- A runner that talks over a pipe needs exactly one synchronous reader of its
  input. While a call is pending, PHP must send nothing but answers.
- A new binding is two small JavaScript functions and a PHP callback, and it
  works on every runner at once.

Specs: [runner contract](../specs/semi-detailed/07-runner-contract/spec.md).
