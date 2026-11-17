# Runner Selection

## Purpose

Several [runner modules](../../../GLOSSARY.md#runner-module) can be enabled at
once, and a site may have none that works. This spec covers how a
[render](../../../GLOSSARY.md#render) picks a
[runner](../../../GLOSSARY.md#runner), how long that choice lasts, and what
happens when a runner fails or none is
[available](../../../GLOSSARY.md#available). It's for site operators deciding
which runner modules to enable. The contract each runner keeps is covered in
[runner-contract](../07-runner-contract/spec.md).

## Requirements

### Requirement: The highest-priority available runner renders

A render SHALL use the highest-priority runner that reports itself available,
and skip the ones that don't. So a runner that's missing something on this
system gets passed over, instead of dropping the whole site to CSR. The included
runner's priority SHALL be low enough for a runner module to outrank it.

#### Scenario: The first available runner takes the whole render

- **GIVEN** three runners in priority order, and the first is unavailable
- **WHEN** a module is registered, a function is bound and the module is
  [invoked](../../../GLOSSARY.md#invoke)
- **THEN** every call reaches the second runner, and none reaches the first or
  the third

#### Scenario: A runner module that outranks the included runner is picked where it can run

- **GIVEN** a runner module with a higher priority than the included runner, and
  its runner can run here
- **WHEN** a runner is picked
- **THEN** it's that runner module's runner

### Requirement: With no available runner, every island renders on the client

When no runner is available, SSR SHALL report itself unavailable, and every
[island](../../../GLOSSARY.md#island) SHALL fall back to CSR. No
[base](../../../GLOSSARY.md#base) SHALL be loaded, and resetting SHALL NOT fail.
The status report SHALL say SSR is off, with each runner's reason, highest
priority first, for those that give one. Those fallbacks are cached as renders
are, and nothing invalidates them when a runner becomes available, so the
status report SHALL also say to clear all caches once one is.

#### Scenario: With no available runner, SSR acts as unavailable

- **GIVEN** every runner is unavailable
- **WHEN** SSR is asked whether it's available, and
  [reset](../../../GLOSSARY.md#reset)
- **THEN** it answers no, and reset doesn't fail

#### Scenario: The status report says SSR is off, and why

- **GIVEN** every runner is unavailable, Deno for want of a binary
- **WHEN** the status report is built
- **THEN** it warns that SSR is off on this web server, names the missing
  binary, and says to clear all caches once a runner is available

#### Scenario: The status report lists every web server's problems

- **GIVEN** another web server that recorded no runner, and a third that
  recorded a base it can't build
- **WHEN** the status report is built on this one
- **THEN** it lists both, with when and why, and this web server once

### Requirement: The choice lasts until reset

The chosen runner SHALL be used for every call until reset, even if it reports
itself unavailable in the meantime. That way a render never spans two runners.
After reset, the choice SHALL be made again, and the runner chosen then SHALL
get everything from scratch. The chosen runner SHALL be reset at the end of each
request, so no realm outlives it where PHP keeps its services from one request
to the next.

#### Scenario: The choice lasts for a render and is made again after reset

- **GIVEN** two available runners, and a
  [registration](../../../GLOSSARY.md#registrations) chose the first
- **WHEN** the first becomes unavailable and the render invokes
- **THEN** the invoke still reaches the first
- **AND** after a reset, a registration reaches the second, as a fresh runner

#### Scenario: The end of a request resets the chosen runner

- **GIVEN** a runner chosen by a registration
- **WHEN** the request ends
- **THEN** the runner is reset, and the next registration reaches it again

### Requirement: Nothing reaches the runner while it invokes

A call that would reach the runner while it invokes SHALL be refused, so an
island rendered from inside another's invoke, as through a same-site fetch,
falls back to CSR, cached as a render would be. A reset asked for meanwhile
SHALL wait until the invoke returns, so the outer island keeps its realm. So no
runner has to handle a call from inside a binding's callback, and only one
island renders in the realm at a time.

#### Scenario: A call from inside an invoke is refused

- **GIVEN** a runner that's invoking, with a base in use
- **WHEN** a binding's callback registers a module, binds a function,
  invokes, uses another base or builds a pending base
- **THEN** each is refused, and none reaches the runner
- **AND** asking for the base in use still answers, since that doesn't reach
  it

#### Scenario: A reset asked for inside an invoke waits for it

- **GIVEN** a runner that's invoking
- **WHEN** a binding's callback asks for a reset
- **THEN** the runner is reset once the invoke returns, and not before

### Requirement: A script failure keeps the runner; a runner failure resets it

A [script failure](../../../GLOSSARY.md#script-failure) SHALL leave the runner
and everything it was given in place, so the next island renders without
registering again. A [runner failure](../../../GLOSSARY.md#runner-failure) SHALL
reset the runner, so the base, with its import map, and the modules get handed
over again.

#### Scenario: A script failure keeps registrations

- **GIVEN** a base and a module already forwarded to the runner
- **WHEN** an invoke fails as a script failure, and the render registers the
  same module and uses the same base again
- **THEN** neither reaches the runner again, but the next invoke does

#### Scenario: A runner failure resets

- **GIVEN** a base and a module already forwarded to the runner
- **WHEN** an invoke fails as a runner failure, and the render uses the base
  and registers the module again
- **THEN** the runner is reset, and then gets both again
