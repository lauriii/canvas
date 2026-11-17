# Runner Contract

## Purpose

A [runner](../../../GLOSSARY.md#runner) runs a
[render](../../../GLOSSARY.md#render)'s JavaScript. Any Drupal module can supply
one by implementing the runner interface and tagging the service. The Deno
runner that ships with Canvas SSR is one of them.

This spec is the contract every runner keeps, checked by the conformance suite.
It's for [runner module](../../../GLOSSARY.md#runner-module) authors. See
[runner-selection](../08-runner-selection/spec.md) for how a runner is chosen
among several.

## Requirements

### Requirement: An invoke imports a main module and calls its default export

An [invoke](../../../GLOSSARY.md#invoke) SHALL import the [main
module](../../../GLOSSARY.md#main-module), plus anything it imports that the
[realm](../../../GLOSSARY.md#realm) hasn't loaded yet, and call its default
export with the [payload](../../../GLOSSARY.md#payload). The invoke SHALL return
what the default export returned, as a string. If that's a promise, the invoke
SHALL wait for it to settle. Whether it waits for another object with a
`then()` method is up to the runner. A main module that `await`s what it
returns, as canvas_ssr's does, gets the same result either way. Either way, the
invoke SHALL run the job queue until it's empty before it returns, so a promise
chain the module started but didn't wait for can't run during a later invoke,
between what that one awaits, unless that invoke settles a promise the chain
is waiting on. It SHALL also run the queue until it's empty between importing
the main module and calling its default export. A module evaluates once in a
realm, so a promise chain its evaluation started and didn't wait for would
otherwise run during the first call, and not during later ones.

A job that throws, as a `queueMicrotask()` callback can, and a rejection
nothing handles, SHALL NOT end the invoke or the realm. A browser reports
both and carries on, so the jobs after it SHALL run, and the invoke SHALL
return what it would have.

#### Scenario: A promise the main module returns is settled

- **GIVEN** a module whose default export is async and awaits a value
- **WHEN** it's invoked
- **THEN** the result is the awaited value

#### Scenario: What evaluation left running has run before the export is called

- **GIVEN** a main module, and a module it imports, that each start a long
  promise chain as they evaluate, don't wait for it, and set a variable when it
  ends
- **WHEN** the main module is invoked twice, and its default export returns
  both variables
- **THEN** both are set, both times

#### Scenario: A promise chain the main module left runs before the invoke returns

- **GIVEN** a module whose default export starts a long promise chain that sets
  its state, without waiting for it
- **WHEN** it's invoked, then invoked again to read its state
- **THEN** the second invoke reads what the chain set

#### Scenario: A job that throws is passed over

- **GIVEN** a module whose default export queues a job that throws and one
  that counts, and rejects a promise nothing handles
- **WHEN** it's invoked twice
- **THEN** each invoke returns, and the counting job ran each time

### Requirement: Registrations last until reset, whatever happens to the realm

A module registered under a [module URL](../../../GLOSSARY.md#module-url) SHALL
stay registered until [reset](../../../GLOSSARY.md#reset), through using a
base, which brings its [import map](../../../GLOSSARY.md#import-map),
[bindings](../../../GLOSSARY.md#binding), other invokes and any replacement of
the realm. Modules MAY be registered in any order. Registering the same source
again SHALL do nothing, unless there was a reset in between. Registering
different source under the same URL SHALL replace it.

#### Scenario: Changed source under a registered URL replaces it

- **GIVEN** a module that's been registered and invoked
- **WHEN** different source is registered under its URL, and it's invoked again
- **THEN** the second result comes from the new source

#### Scenario: A registration survives everything short of reset

- **GIVEN** two modules registered, then a base used, a function bound, and
  another module registered and invoked
- **WHEN** the first main module is invoked without registering anything again
- **THEN** it renders with its import

### Requirement: The realm lasts between invokes, script failures included

A runner SHALL keep its realm from one invoke to the next, so modules
[evaluate](../../../GLOSSARY.md#evaluate) once, and their state lasts from one
[island](../../../GLOSSARY.md#island) to the next. A [script
failure](../../../GLOSSARY.md#script-failure) SHALL NOT replace it, as a throw
in a browser doesn't replace its page: the realm setup module keeps built-ins
and globals from changing, so what a failed island leaves is its own modules'
state, as it would be in the browser. A runner MUST replace the realm after a
[runner failure](../../../GLOSSARY.md#runner-failure), which may have stopped
the JS anywhere. It MAY replace it at other times for its own reasons, such as
to bound a process's resources.

#### Scenario: A script failure keeps the realm

- **GIVEN** a main module that counts renders in a module it imports
- **WHEN** an invoke throws halfway, then the same modules are registered again
  and invoked
- **THEN** the count includes the failed render

### Requirement: A script failure keeps every registration, but a runner failure may not

A throw while a module evaluates or runs, or an import that can't
[load](../../../GLOSSARY.md#load), SHALL be reported as a [script
failure](../../../GLOSSARY.md#script-failure), and SHALL leave every
[registration](../../../GLOSSARY.md#registrations) in place. Any other failure
SHALL be reported as something else. After one, the runner MAY have dropped its
registrations, but given them again, it SHALL run. A module that never finishes
SHALL be interrupted as a [runner failure](../../../GLOSSARY.md#runner-failure).

#### Scenario: A script failure keeps what the runner was given

- **GIVEN** an import map, a module, a binding, and a main module that throws
- **WHEN** the throwing module is invoked, then a module that uses all three
- **THEN** the second invoke renders with the import, the binding and the map

#### Scenario: Something that never finishes is interrupted

- **GIVEN** a module that loops forever
- **WHEN** it's invoked
- **THEN** the invoke fails within the runner's time limit with a
  `TimeLimitException`, not as a script failure
- **AND** a module registered afterwards renders

### Requirement: A failure says why, where the renderer acts on it

A runner SHALL say when an invoke went past one of its limits by the class it
throws, so the renderer needn't match the runner's messages:

- Past its time limit, a `TimeLimitException`.
- Past its memory limit, a `MemoryLimitException`.

Both are runner failures.

#### Scenario: A module that outgrows its memory is ended

- **GIVEN** a module that keeps allocating
- **WHEN** it's invoked
- **THEN** the invoke fails with a `MemoryLimitException`
- **AND** a module registered afterwards renders

### Requirement: The import map in force is the base's

A runner SHALL [resolve](../../../GLOSSARY.md#resolve) with the import map of
the [base in use](../../../GLOSSARY.md#base-in-use), and with an empty one when
no base is in use. Using another base SHALL replace the map entirely. The runner
SHALL forget the map on reset, and using a base it kept SHALL bring the map back
without the base being asked for. A base the runner couldn't take SHALL leave
the map in force as it was. There is no other way to give a runner a map, so
the map and what the base evaluated can't disagree. See
[module-resolution](../02-module-resolution/spec.md) for what resolution means.

#### Scenario: Another base's map replaces the one before it

- **GIVEN** a module that resolves a [specifier](../../../GLOSSARY.md#specifier)
- **WHEN** bases with other maps are used between invokes, the last with an
  empty one
- **THEN** each invoke answers from the map in force, and the last one throws

#### Scenario: A kept base brings its map back after a reset

- **GIVEN** a base whose map covers a specifier, used and then reset
- **WHEN** a module resolves the specifier
- **THEN** it can't
- **WHEN** the base is used again, and the runner doesn't ask for it
- **THEN** the module resolves it from the base's map

### Requirement: Bindings expose PHP callbacks as synchronous global functions

A binding SHALL install a PHP callback as a global function in the realm. A
bound name SHALL keep its first callback until reset, so callers can bind before
every render at no cost. The global SHALL be non-writable and non-configurable,
so no module can replace or delete it, and see what later modules pass or get
back. A runner SHALL define it with the built-ins as they were before any module
evaluated, since the realm setup module wraps `Object.defineProperty()` so a
global a component defines stays configurable. Only strings SHALL cross between
PHP and JavaScript, through a serializer for the call and a deserializer for the
result. The function SHALL return synchronously, and SHALL throw where it's
called when the callback throws. A callback that suspends its Fiber SHALL NOT
suspend the caller's Fiber. The serializer SHALL run on the call's own stack,
and a stack trace it takes SHALL reach the callback naming each frame's module
by its module URL, innermost first.

#### Scenario: A bound name keeps its first callback until reset

- **GIVEN** a name bound twice to different callbacks
- **WHEN** a module calls it
- **THEN** it gets the first callback's result
- **AND** after a reset, binding the name again takes effect

#### Scenario: A bound function can't be replaced or deleted

- **GIVEN** a module that assigns to a bound name, redefines it and deletes it
- **WHEN** another module calls it
- **THEN** it gets the PHP callback's result

#### Scenario: A serializer's stack trace names the calling modules

- **GIVEN** a module that calls a bound function through two others, one
  registered by an absolute URL
- **WHEN** the serializer takes a stack trace
- **THEN** the callback gets a frame for each module, ending with its module
  URL, line and column, the caller nearest the call first

#### Scenario: A bound function returns its value synchronously

- **WHEN** a module calls a bound function
- **THEN** it gets a string back, not a promise

### Requirement: A runner's own code is out of components' reach

A runner's own JavaScript carries results, binding calls and
`import.meta.resolve()` answers from one component to the next. It SHALL take
the built-ins it calls before any module evaluates, keep its state in closures
rather than in globals or top-level bindings a module could reassign, and
define the functions modules call so none can be replaced. A component that
reassigns a global or mutates a built-in SHALL NOT change what a later invoke
returns, passes to a binding, or resolves through the runner's own calls. Nor
SHALL a component remove, or listen alongside, a handler the runner installs
in the realm, such as one that keeps an unhandled rejection or an uncaught
error from ending it.

What the language looks up on its own, a runner can't hold on to: an `await`
reads a promise's `then()`, and a typed array's `subarray()` its
constructor's species. Those are closed by the realm setup module, which
freezes the built-ins before any component runs.

#### Scenario: Replaced built-ins don't reach a later invoke

- **GIVEN** a module that replaces `JSON`, `String`, `Object.prototype.toJSON`,
  the array iterator and string, array and RegExp methods
- **WHEN** another module then calls a binding and `import.meta.resolve()`
- **THEN** PHP gets the call's arguments, and the module gets PHP's answer and
  the import map's URLs, unchanged

### Requirement: The web platform is present and Node is absent, in every realm

A runner SHALL supply the supported [web platform
APIs](../../../GLOSSARY.md#web-platform-api) and an `import.meta.resolve()`
that answers from the import map, before any module evaluates and in every
realm it builds. Besides the ECMAScript globals, which belong to the language,
those SHALL be the only globals a module sees. Any other host API, like a
timer, a `Worker`, storage or `fetch()` before Renderer binds it, would let a
component reach outside its render, or render on some runners and not others.
A runner SHALL expose no Node globals and SHALL load no `node:` module. The
supported list can evolve as components need more.

Of the ECMAScript globals, a runner SHALL leave out `FinalizationRegistry`.
Its callbacks run once the collector frees an object, which may be during a
later island than the one that registered it, where no island could be held
to account for what they do.

#### Scenario: Only the supported web platform APIs are present

- **WHEN** modules list the globals that aren't ECMAScript, across several
  invokes
- **THEN** each list is exactly the supported web platform APIs, every time,
  and `performance` has only `now()` and `timeOrigin`

#### Scenario: Node APIs are absent

- **WHEN** a module checks for Node's globals and tries `node:` imports
- **THEN** none of the globals exists, and every import fails

#### Scenario: FinalizationRegistry is absent

- **WHEN** a module checks for `FinalizationRegistry` and `WeakRef`
- **THEN** only `WeakRef` exists

### Requirement: A runner takes any base it's given, unless it can't build from it

A [base](../../../GLOSSARY.md#base) is the modules that nearly every render
registers, the [preload modules](../../../GLOSSARY.md#preload-modules) that
evaluate the libraries among them, the [modules to
compile](../../../GLOSSARY.md#modules-to-compile), and the import map in force
with them, which its id covers. A runner SHALL build from a base once and keep
what it builds, even through reset, so the base is asked for at most once per
id. While a base is in use, its modules SHALL count as registered. A runner
MAY load and compile the modules to compile, but SHALL NOT evaluate one where
a render sees it: each evaluates only when a render imports it. A runner that
can't build from a base SHALL render as if there were no base. A base SHALL
NOT change what a render outputs.

#### Scenario: A base leaves output unchanged

- **GIVEN** a [base in use](../../../GLOSSARY.md#base-in-use), and a render that
  registers the same modules again
- **WHEN** the main module is invoked
- **THEN** the output is what it would be without the base

#### Scenario: A module to compile evaluates only when a render imports it

- **GIVEN** a base with a preload module that adds a global, and two modules
  to compile, one that adds another global and one that imports the preload
  module and reads both globals
- **WHEN** the reader is invoked, then the other, then the reader again
- **THEN** the reader first sees only the preload module's global, then both

#### Scenario: A base that can't be built is ignored

- **GIVEN** a base where one of the modules doesn't compile
- **WHEN** it's used, and a working module is registered and invoked, both
  before and after a reset
- **THEN** the module renders each time

#### Scenario: A base is asked for once per id, even through reset

- **GIVEN** a base used under an id
- **WHEN** the runner is reset and the same id is used again
- **THEN** it isn't asked for again

### Requirement: What a runner keeps from a base is up to it, and never shows

Building from a base imports its preload modules, which loads and evaluates the
libraries renders use. What a runner keeps of that is its own choice:

- It MAY keep only what loading produced, such as compiled code. Each realm
  then evaluates the libraries again, as renders import them. The Deno runner
  does this: its build runs in a process that's thrown away, and each request
  starts a new one.
- It MAY keep the realm the build evaluated, and start later realms from a
  copy of it. Renders then begin with the libraries evaluated, and with what
  they built as they evaluated. A preload module can exist only for that: the
  one that calls tailwind-merge has its class map built once, where a runner
  that keeps only compiled code builds it in every request.

Either way, a render SHALL output what it would without a base. So a runner
that keeps the realm SHALL keep nothing of a module to compile but its
compiled code. It SHALL leave out a preload module that failed in the build,
as one that needs a binding does, since the build has none, so that module
evaluates in the render that imports it. And nothing a render does in its
realm SHALL reach the kept one, or another request's.

#### Scenario: A preload module that needs a binding evaluates in a render

- **GIVEN** a base with a preload module that calls a binding at top level
- **WHEN** it's used, the binding is bound, and the module is invoked
- **THEN** it renders with the binding's answer, whatever the runner kept

### Requirement: Unloadable preload modules and modules to compile are reported, and only those

Using a base SHALL answer TRUE when every preload module loaded, and every
module to compile the runner checked, and FALSE when the runner couldn't take
the base, as when building from it takes too long. Otherwise it SHALL answer
with the non-empty list of those modules that can't load, because they import
what nothing provides or don't compile. A module that only throws while
evaluating, like one that needs a missing binding, SHALL NOT be listed.

#### Scenario: Only preload modules that can't load are listed

- **GIVEN** a base with four modules: a working one, one that imports a missing
  specifier, one that doesn't compile, and one that calls a binding at top level
- **WHEN** it's used
- **THEN** at most the missing-import and non-compiling modules are listed, and
  each fails when invoked. The working and binding-dependent modules render once
  the binding exists.

#### Scenario: Only modules to compile that can't load are listed

- **GIVEN** a base whose modules to compile are a working one, one that imports
  a missing specifier, one that doesn't compile, one that imports that one, and
  one that calls a binding at top level
- **WHEN** it's used
- **THEN** at most the three that can't load are listed, and each fails when
  invoked. The working and binding-dependent modules render once the binding
  exists.

### Requirement: A base a runner couldn't take is built after the response

A runner that answers FALSE for a base it may take later SHALL say so when asked
whether a base is pending, at a cost cheap enough for every response, page cache
hits included. After a response, canvas_ssr's subscriber SHALL then ask it to
build from the base. The runner MAY decide it isn't time to try again yet. When
it can take the base, the subscriber SHALL invalidate `canvas_ssr_base_pending`.
When it fails again, the runner SHALL say since when, when it tries next, and
why, and the subscriber SHALL record that for the status report, for this web
server, and log it. A runner that never answers FALSE SHALL say no base is
pending.

#### Scenario: Building the pending base ends its fallbacks

- **GIVEN** islands that fell back to CSR for a base the runner couldn't take
- **WHEN** the runner builds from it after a response
- **THEN** the fallbacks are invalidated, and nothing is on the status report

#### Scenario: Only the selected runner builds

- **GIVEN** an unavailable runner ranked above the one selected
- **WHEN** a response ends, and the selected runner has a pending base
- **THEN** only the selected runner is asked to build it

### Requirement: A runner reports whether it can run here, and if not, why

A runner SHALL report itself [available](../../../GLOSSARY.md#available) only
when it can run on this system. It SHALL be constructible without the thing it
runs on, like a binary, so the check costs nothing when it fails. An unavailable
runner is skipped; see [runner-selection](../08-runner-selection/spec.md). Once
it has found it can't run, it SHOULD say why, naming itself, since the status
report shows that as it is.

An available runner SHALL also say which of its limits it can't enforce on
this system, if any, the same way. It's asked once per request, so it SHALL be
cheap to answer.

#### Scenario: A runner without its binary is unavailable

- **GIVEN** the included runner, set up with a binary path that doesn't exist
- **WHEN** availability is checked
- **THEN** it reports itself unavailable, names the missing binary, and every
  island falls back to CSR
