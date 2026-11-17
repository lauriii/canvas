# Realm Sharing

## Purpose

A runner renders every [island](../../../GLOSSARY.md#island) of a request in one
[realm](../../../GLOSSARY.md#realm), as
[runner-contract](../07-runner-contract/spec.md) requires, so modules
[evaluate](../../../GLOSSARY.md#evaluate) once. A render's markup is cached and
served on other pages and to other users, so what one island does mustn't reach
another. This spec covers what's undone between islands, what libraries may
keep, and what a component's module may not do. [ADR
11](../../../adr/0011-islands-share-a-locked-realm.md) says why it's built this
way, and lists the isolation gaps, CSR fallbacks and SSR/CSR mismatches it
leaves. [ADR
12](../../../adr/0012-ssr-may-refuse-code-that-works-in-the-browser.md) says why
some code is refused.

## Requirements

### Requirement: What a render does is undone before the next island

Before any component's code runs, the [realm setup
module](../../../GLOSSARY.md#realm-setup-module) SHALL freeze every built-in and
the global object's prototype chain, and make every existing global
non-writable and non-configurable. No built-in but a prototype SHALL keep an
accessor. A built-in only a call hands out SHALL be frozen too, then or before
the call that first hands it out returns, so no component gets one unfrozen.
Every module's exports SHALL be frozen once it evaluates. A write to any of
these that throws out of the render SHALL fall back to CSR, with a warning
where the renderer knows how the runner's engine words the error. Each island
SHALL start with:

- the global object's own prototype, and no global an earlier island added
  while rendering
- `console` and preact's options as libraries left them
- its own `drupalSettings`, which no island can redefine or delete
- no fetch result of an earlier island, and no promise job of one, unless
  the island settles a promise that job is waiting on

Properties an object commonly sets on itself though its prototype has them
SHALL be [tamed](../../../GLOSSARY.md#tamed-property) first, and a site MAY add
to them.

An island SHALL NOT end until every `import()` its components' modules made has
settled, and what follows it has run, whether or not the component waited for
it. An island whose components go on making them SHALL fall back to CSR, with a
warning, and the runner SHALL be reset.

#### Scenario: A component that mutates a built-in falls back, and changes nothing

- **GIVEN** a component that sets `Array.prototype.join` to its own function,
  and one that joins an array
- **WHEN** an island of the first renders, then an island of the second
- **THEN** the first falls back to CSR, with a warning naming it
- **AND** the second joins as it would in the browser

#### Scenario: Nothing a built-in keeps for the realm reaches a later island

- **GIVEN** a component that sets `Error.prepareStackTrace` and matches a
  regular expression, and one that reads an error's stack and `RegExp.$1`
- **WHEN** an island of the first renders, then an island of the second
- **THEN** the second's stack is the engine's own, and it reads no match

#### Scenario: A chunk a component reaches first is frozen already

- **GIVEN** a library no island has imported yet, and a component that imports
  the library's chunk by a URL it computes and mutates what the chunk exports
- **WHEN** an island of that component renders, then an island of a component
  that imports the library by name
- **THEN** the first falls back to CSR, with a warning naming it
- **AND** the second gets the library as it was written

#### Scenario: An island can't give later ones globals through the prototype

- **GIVEN** a component that gives the global object a prototype with a getter
  while it renders, and one that reads that global at module scope and while
  it renders
- **WHEN** an island of the first renders, then an island of the second
- **THEN** the first reads the getter's value
- **AND** the second reads `undefined` both times

#### Scenario: An island can't keep drupalSettings from the next

- **GIVEN** components that redefine, delete and assign `drupalSettings` while
  they render, and one that declares no data and reads it
- **WHEN** an island of each renders in turn
- **THEN** the ones that redefine and delete it fall back to CSR
- **AND** the one that assigns it reads what it assigned
- **AND** the last reads `undefined`

#### Scenario: A global an island adds while rendering is gone for the next

- **GIVEN** a component that adds `window` while it renders, and one that reads
  `window` at module scope and while it renders
- **WHEN** an island of the first renders, then an island of the second
- **THEN** the second reads `undefined` both times
- **AND** the same holds when the first defines it with
  `Object.defineProperty()`, even with `configurable: false`

#### Scenario: A component's change to console is its own island's

- **GIVEN** a component that imports a library that adds a method to `console`,
  and components that replace, delete or redefine it, make `console.log` throw,
  or give `console` another prototype, while they render
- **WHEN** an island of each renders, and then one that logs and calls the
  added method
- **THEN** the last logs, and the method is as the library left it
- **AND** a component that freezes `console` falls back to CSR

#### Scenario: A component's vnode hook is its own island's

- **GIVEN** a component that wraps `options.vnode` while it renders, and a
  plain one
- **WHEN** an island of the first renders, then an island of the second
- **THEN** only the first island's markup shows the wrapper
- **AND** the same holds when the first defines `options.vnode`
  non-configurable, replaces the prototype of `options`, or freezes it

#### Scenario: What follows an import() a component didn't wait for is its island's

- **GIVEN** a component that calls `import()` while it renders, of a component,
  of a computed specifier, or of forty modules one after another, and
  fetches once they've loaded, without waiting for any of it
- **WHEN** an island of it renders, then an island of another component
- **THEN** the first carries what was fetched, and the second doesn't

#### Scenario: A component that goes on importing is given up on

- **GIVEN** a component whose every `import()` makes another, which it doesn't
  wait for
- **WHEN** an island of it renders, then an island of another component
- **THEN** the second renders on the server, with none of what the first did
- **AND** on a runner that loads modules after the invoke that imported them,
  the first falls back to CSR, with a warning

### Requirement: What a library does as it evaluates is kept

The libraries an island's components import by name SHALL evaluate before any
of the components' modules, one after another, as a base evaluates them. That
includes one a component only names in an `import()`. What a library changes
as it evaluates, or leaves running to change, SHALL be kept for later islands:

- a global it adds SHALL be put back if a render reassigns or deletes it
- what it adds to `console` or preact's options SHALL stay

A library a component imports by a computed specifier evaluates among the
component's modules, and what it changes SHALL still be kept.

#### Scenario: A global a library adds while it evaluates is kept

- **GIVEN** a component that imports a library that adds a global while it
  evaluates, and uses it while it renders
- **WHEN** two islands of it render
- **THEN** the second still finds the global

#### Scenario: A global a library adds is put back, but not what it holds

- **GIVEN** a component that imports a library that adds a global holding an
  object while it evaluates, and components that reassign, delete and redefine
  that global, and one that mutates the object, while they render
- **WHEN** an island of the first renders after each of the others
- **THEN** it finds the global as the library left it, after each of the first
  three
- **AND** it sees the mutation after the last

#### Scenario: Hooks a library adds to preact's options are kept

- **GIVEN** a component that imports a library that wraps `options.vnode` as
  it evaluates, as a signals library does
- **WHEN** an island of it renders, then an island of another component
- **THEN** the hook applies to the other component's markup too, as in the
  browser

#### Scenario: What a library leaves running is the library's

- **GIVEN** a library whose module starts a promise chain that adds a global,
  and doesn't await it, and a component that imports it
- **WHEN** an island of the component renders, then one that reads the global
- **THEN** both render on the server, and read it
- **AND** an island reads it too after one whose component imports such a
  library, and a module that can't load, fell back to CSR

### Requirement: A component's module may not change what islands share as it evaluates

A component's module SHALL NOT add, reassign or delete a global, or
[mutate](../../../GLOSSARY.md#mutate) `console` or preact's options, as it
evaluates, nor leave something running that does. What it changed SHALL be put
back, and the islands that import it SHALL fall back to CSR, cached as a render
would be, with a throttled warning. When something left running did it, later
islands in the request that import a component module that island's invoke
evaluated SHALL fall back too, since which one did it can't be told. Their
fallbacks SHALL end when what that island depends on changes, and their
warnings SHALL name its component.

#### Scenario: A component's module may not change what islands share

- **GIVEN** components whose modules, as they evaluate, add or define a
  global, add to `console` or replace one of its methods, or add to preact's
  options
- **WHEN** an island of each renders, then one that reads what they changed
- **THEN** each falls back to CSR, cached as a render would be, with a warning
  naming it
- **AND** the last renders on the server, and sees none of the changes
- **AND** a component that imports one of them falls back too

#### Scenario: Nor may what a component's module leaves running

- **GIVEN** a component whose module starts a promise chain that adds a global
  and mutates `console`, and doesn't await it, a component that imports it, and
  one whose chain is a thousand steps long
- **WHEN** two islands of the first render, then one of the second and of the
  third, then one that reads what they changed
- **THEN** the first four fall back to CSR, cached as a render would be, each
  with a warning naming it
- **AND** the last renders on the server, and sees none of the changes

#### Scenario: A module an earlier island evaluated isn't blamed

- **GIVEN** two components that import the same child, and one of them whose
  module leaves a promise chain running that adds a global
- **WHEN** an island of the other renders, then one of it, then the other again
- **THEN** only its island falls back to CSR

#### Scenario: What was evaluated with the culprit is blamed until it changes

- **GIVEN** the same components, with a child no island has imported yet
- **WHEN** an island of the one whose module leaves the chain running renders,
  then one of the other
- **THEN** both fall back to CSR, and the second's warning names the first's
  component
- **AND** the second's fallback has the first's component's cache tags

#### Scenario: A failed island's leftovers aren't the next island's

- **GIVEN** a component that imports a library that throws as it evaluates, and
  a component whose module leaves a promise chain running that adds a global
- **WHEN** an island of the first renders, then an island of another component
- **THEN** the first falls back to CSR
- **AND** the second renders on the server, with no warning, and the global
  isn't there

### Requirement: Every island renders with preact/compat

Every island SHALL render with preact/compat loaded, whether or not its own
modules import it, so its markup doesn't depend on whether an earlier island
loaded it.

#### Scenario: Every island renders with preact/compat

- **GIVEN** a component that doesn't import `react`, rendering `<svg
  strokeWidth={2}>`
- **WHEN** it renders, whatever rendered before it
- **THEN** it renders `stroke-width`
