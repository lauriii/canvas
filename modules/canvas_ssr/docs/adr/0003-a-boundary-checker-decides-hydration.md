# 3. A boundary checker decides hydration, and a static island ships no code

Status: Accepted

## Context

Without SSR, every Canvas [island](../GLOSSARY.md#island) ships its
[component](../GLOSSARY.md#component) code and renders in the browser. Once
the server has rendered the markup, we can hydrate it anyway or leave it as it
is. Hydrating costs code and work in the browser. Not hydrating breaks a
component that needs the browser.

React has a convention for exactly this. A component that has to run in the
browser starts with `"use client"`. Preact follows the same convention. Other
frameworks have their own ways of saying it.

## Decision

Each [framework](../GLOSSARY.md#framework) gets a [boundary
checker](../GLOSSARY.md#boundary-checker) that reads that framework's own
convention. The React checker also covers Preact. It reads the directive from
the parsed module, so a BOM, a `'use strict'` before it or a comment after it
don't hide it.

One [client boundary](../GLOSSARY.md#client-boundary) anywhere in the
island's [component graph](../GLOSSARY.md#component-graph) makes the whole
island hydrate:

- An island with a client boundary is a [hydrated
  island](../GLOSSARY.md#hydrated-island). Its server-rendered markup
  hydrates on load.
- An island without one is a [static island](../GLOSSARY.md#static-island).
  Its markup is final, with no component code and no script.

A framework with no checker renders on the client. It isn't judged by another
framework's rules.

## Alternatives considered

### Always hydrate

It's safe, but then every island's code goes to the browser, and SSR saves
nothing for static components. Those are most of a page.

### A per-component setting in the Canvas UI

That's a second convention beside the framework's own, and authors would have to
keep the two in step.

### One rule for every framework

React's rule is right for Preact, but a Vue or Svelte component would be judged
by a rule that isn't its own.

### Find the directive with a regex

It's simpler than parsing, but it's easy for a regex to miss a valid way of
writing the directive. Each miss leaves a component that needs the browser dead
in the page. The import parser parses the module anyway, so reading the
directive from it costs nothing extra.

## Consequences

- If an author forgets `"use client"` on a component that needs the browser, it
  renders as dead markup, where CSR would have worked. We accept that. The
  convention is React's, so the responsibility sits where React puts it.
- Adding a framework takes a [framework
  renderer](../GLOSSARY.md#framework-renderer) and a checker.
- Whether an island hydrates is known before any JavaScript runs. So the
  [base](../GLOSSARY.md#base) can record it per component, and a warm
  [render](../GLOSSARY.md#render) doesn't have to ask again.

Specs: [island rendering](../specs/semi-detailed/01-island-rendering/spec.md).
