# 8. A Deno process lives for one request

Status: Accepted

## Context

The Deno runner runs [components](../GLOSSARY.md#component) in a child process.
How long should it live? It could start for each
[island](../GLOSSARY.md#island), or for each request. Or it could keep running
as a daemon that serves requests as they come in.

A daemon would keep a pool of worker threads behind a Unix socket, start on
demand under a lock, and retire idle threads. It would be faster per request,
and handle more requests at once.

## Decision

Each [runner](../GLOSSARY.md#runner) gets one Deno process per request. The
runner starts it when a [render](../GLOSSARY.md#render) first needs it, and
keeps it for the rest of that request's islands. So modules
[evaluate](../GLOSSARY.md#evaluate) once, and later islands are cheap.
[`reset()`](../GLOSSARY.md#reset) kills the process, and so does a [runner
failure](../GLOSSARY.md#runner-failure). Either way, the next
[invoke](../GLOSSARY.md#invoke) starts in a fresh [realm](../GLOSSARY.md#realm).
A [script failure](../GLOSSARY.md#script-failure) keeps it. Nothing runs
between requests.

The [registrations](../GLOSSARY.md#registrations) outlive the process. The
runner keeps the [bindings](../GLOSSARY.md#binding) and the [import
map](../GLOSSARY.md#import-map). The modules it [seeded](../GLOSSARY.md#seed)
stay on disk in the [runner cache](../GLOSSARY.md#runner-cache). So a new
process starts warm.

## Alternatives considered

### A process per island

Every island would evaluate the framework, and everything else it imports, all
over again.

### A persistent process

It's faster, and some people will want it. That's one reason runners are
pluggable. But it isn't worth it for the included runner:

- A daemon has to be started, locked, found and cleaned up after. Its threads
  have to be handed out, reset and retired.
- Keeping requests from seeing each other's data would then depend on that
  cleanup code, not on the operating system.
- Shared hosting usually forbids persistent processes, so it could only be an
  option, not the default.
- The [base cache](../GLOSSARY.md#base-cache) closes most of the startup gap a
  daemon would save.

The gains aren't big enough to justify the extra maintenance.

## Consequences

- Requests are isolated by the process boundary, so there's no shared state to
  clean up.
- Every request pays for starting a process. The base cache makes it a warm
  start: Deno's dependency analysis and V8's code cache for the
  [base](../GLOSSARY.md#base) are already on disk.
- A request's memory is freed when the request ends.
- A running process can't see a changed source under a URL it may already have
  evaluated. It can't see a changed import map either. Either change means a new
  process, with the same registrations.

Specs: [Deno runner](../specs/semi-detailed/10-deno-runner/spec.md).
