# 4. Runners are pluggable, the conformance suite defines them, and one ships

Status: Accepted

## Context

SSR needs a JavaScript engine, and no engine suits everyone:

- Shared PHP hosting usually allows neither persistent processes nor custom PHP
  extensions.
- A platform that allows extensions can embed an engine in PHP.
- Some people want Node or Bun, even though their sandboxing is weaker or
  missing.
- Some want a persistent process instead of one per request.
- Some want something more unusual, like [celld](https://celld.dev/).

## Decision

A [runner](../GLOSSARY.md#runner) is anything that implements `RunnerInterface`.
A Drupal module tags its runner `canvas_ssr.runner` with a priority. The
highest-priority runner that's [available](../GLOSSARY.md#available) on this
system renders. Several can be enabled at once.

A [runner module](../GLOSSARY.md#runner-module) depends on `canvas_ssr`, never
the other way round. Whatever its runner needs, like an extension, a binary or a
daemon, is the runner module's requirement to declare.

The conformance suite, `RunnerConformanceTestBase`, defines the contract.
Everything the interface requires is a test there, written in terms of behavior
only. A runner module extends it to run the whole suite against its runner.

`canvas_ssr` ships exactly one runner, Deno. Its priority is 0, the default, so
any runner can outrank it. It lives inside canvas_ssr, not as a Drupal module of
its own. A site that installs `canvas_ssr` wants SSR, and a runner it can always
fall back to is worth more than the option to leave it out. Apart from Deno,
nothing in canvas_ssr names a runner.

## Alternatives considered

### One fixed engine

It would shut out any hosting that can't run it.

### Ship Deno as its own Drupal module

Then a site could install `canvas_ssr` with no runner at all. Nobody would want
that.

### Include a Node runner, instead or as well

Deno is sandboxed by default, while Node's permission model is opt-in. A second
included runner in a child process would also double the maintenance without
adding anything new.

### Make the engine in a PHP extension the default

It saves starting a process. But a Drupal module can't make a site install a
PHP extension. A short-lived Deno process balances easy installation, good
sandboxing and wide platform support. So it's the default any site can have.

## Consequences

- The conformance suite is all a runner author has to pass, so it has to stay
  complete.
- Switching runners means enabling a runner module. Nothing in a
  [component](../GLOSSARY.md#component) or a site changes.
- The default has a deadline. Deno has
  [announced](https://deno.com/blog/cloudflare) it will reach end of life in
  about a year, so the included runner has to be replaced before then. Node is
  the likely choice, once we've looked more closely at its sandboxing. Because
  runners are pluggable, that's a swap, not a rewrite.

Specs: [runner contract](../specs/semi-detailed/07-runner-contract/spec.md),
[runner selection](../specs/semi-detailed/08-runner-selection/spec.md).
