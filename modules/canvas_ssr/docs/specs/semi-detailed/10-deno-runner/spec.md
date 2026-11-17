# Deno Runner

## Purpose

The Deno [runner](../../../GLOSSARY.md#runner) ships with Canvas SSR and is its
default runner. It runs code that a site's editors wrote, on the web server, in
a Deno process. This spec is what an operator can rely on: what the runner
needs, what [component](../../../GLOSSARY.md#component) code can't reach, where
the runner keeps its files, and how it builds and keeps its caches. Like any
runner, it keeps [runner-contract](../07-runner-contract/spec.md).

## Requirements

### Requirement: Component code can't read the filesystem or reach the network

The Deno process SHALL run with no permissions. It SHALL
[load](../../../GLOSSARY.md#load) modules only from the cache seeded for it. So
a component can't read a file or open a socket, however it tries. A failure's
message SHALL NOT include what the code reached for. Component code SHALL NOT
see the `Deno` namespace at all, because a browser has none.

#### Scenario: An escape attempt gets nothing out

- **GIVEN** a component that tries to read a system file through an import of
  its `file:` URL, `node:fs`, Deno's filesystem API, a `fetch()`, or an import
  of a [cache URL](../../../GLOSSARY.md#cache-url) where nothing was seeded
- **WHEN** an [island](../../../GLOSSARY.md#island) of it renders
- **THEN** the [render](../../../GLOSSARY.md#render) fails or renders without
  the file's contents, and no error message contains them

#### Scenario: The network is out of reach

- **GIVEN** a component that fetches a remote URL, statically imports a remote
  module, imports an `npm:` [specifier](../../../GLOSSARY.md#specifier), or
  fetches the [cache origin](../../../GLOSSARY.md#origin) itself
- **WHEN** an island of it renders
- **THEN** no request leaves the process and nothing remote is loaded

#### Scenario: There's no Deno namespace

- **GIVEN** a component that checks for `Deno` as it loads and as it renders
- **WHEN** it renders
- **THEN** `Deno` is undefined both times

#### Scenario: The runner script gives a component nothing

- **GIVEN** a component that imports the runner's script by its file URL
- **WHEN** it renders
- **THEN** the import is refused, or gives a module with no exports

#### Scenario: Every global Deno defines is accounted for

- **GIVEN** the globals the Deno binary defines
- **WHEN** they're compared with those a realm keeps and the known host APIs
  the runner deletes
- **THEN** none is left over, so a new Deno's ECMAScript global isn't deleted
  unnoticed

#### Scenario: Nothing a component stores reaches the disk

- **GIVEN** a Deno process's `DENO_DIR`
- **WHEN** a script that still had `localStorage` and the Cache API tried to
  store something
- **THEN** both fail, since an empty file sits where Deno would make its
  storage directory

### Requirement: Without a usable binary or cache directory, the runner is unavailable

By default, the runner SHALL look for a Deno binary at `bin/deno` in
Composer's vendor directory, and a site MAY name another path. It SHALL report
itself unavailable when that binary isn't executable, or when its runner
caches root can't be used. Then SSR falls back to CSR and the runner costs
nothing. It SHALL check its cache roots once per request, at the first
render. It SHALL say why it's unavailable. A cache directory it can't use
SHALL be logged as a warning that says who owns what and how to fix it, at
most once an hour for each message on each web server, however PHP runs. A
missing binary SHALL NOT be logged, since a site may rely on another runner.

#### Scenario: A missing binary

- **GIVEN** no executable at the configured path
- **WHEN** an island renders
- **THEN** the runner is unavailable and the island falls back to CSR

#### Scenario: A root another OS user could change isn't used

- **GIVEN** a cache root with a directory on its path, the root included, that
  another OS user owns, or may write to without the sticky bit
- **WHEN** the runner checks its directories
- **THEN** it reports itself unavailable, because whoever can change that
  directory could put modules into this OS user's renders

#### Scenario: Another OS user's directory isn't used

- **GIVEN** an OS user's directory under a cache root that's a symlink, or that
  another OS user owns
- **WHEN** the runner checks its directories
- **THEN** it reports itself unavailable

#### Scenario: A directory that can't be used is logged and explained

- **GIVEN** an OS user's directory another OS user made first, which only root
  can remove from a sticky root
- **WHEN** the runner checks its directories, on any number of requests and
  PHP processes within an hour
- **THEN** it's unavailable, and says, and logs once, that the directory isn't
  private to PHP's OS user, who owns it, and to have root delete it or set the
  roots elsewhere

#### Scenario: A directory replaced while in use stops the runner

- **GIVEN** a runner whose OS user's directory is deleted and replaced by
  another OS user's after the request's check
- **WHEN** PHP next writes there, or hands Deno control by starting a process
  or sending it a message
- **THEN** the runner stops using the directory, deletes nothing in it, logs an
  error at most once a minute on each web server, and the request's islands
  fall back to CSR

### Requirement: Base caches fall back to the runner caches root

When the base caches root can't be used, the runner SHALL keep base caches
under the runner caches root instead, beside the runner caches, rather than
report itself unavailable. It SHALL keep only the 10 most recently used there,
since that root is often memory-backed. They may not outlast a reboot or a
deploy, so it SHALL log a warning saying why, and report it as a limitation.

#### Scenario: An unusable base caches root falls back

- **GIVEN** a base caches root whose OS user's directory isn't this OS user's,
  and more than 10 base caches already under the runner caches root
- **WHEN** an island renders
- **THEN** it renders on the server, with its base cache under the runner
  caches root, and the 10 most recently used base caches remain there
- **AND** a warning is logged, and the runner's limitations say where base
  caches are and why

### Requirement: Each OS user keeps its own cache directories under shared roots

The runner SHALL keep its files under two roots, in a directory per [OS
user](../../../GLOSSARY.md#os-user). One root is for [base
caches](../../../GLOSSARY.md#base-cache), which outlive requests. The other is
for [runner caches](../../../GLOSSARY.md#runner-cache), which live for a
request. A root the runner creates SHALL be world-writable with the sticky bit.
Each OS user's directory SHALL be readable by its owner only. Both roots SHALL
be configurable, and both SHALL be safe to delete while the site isn't serving
requests.

#### Scenario: Each OS user gets its own directory

- **GIVEN** a root that every OS user shares
- **WHEN** a runner makes its caches
- **THEN** they're under a directory named for the OS user PHP runs as, and only
  that OS user can read it

### Requirement: The Deno process loads modules by cache URL, which never reaches the page

The runner SHALL [seed](../../../GLOSSARY.md#seed) each [registered
module](../../../GLOSSARY.md#registered-module) at a cache URL derived from its
[module URL](../../../GLOSSARY.md#module-url), and load it from there. Wherever
a cache URL would leave the process, such as in output, stack traces or
`import.meta`, the runner SHALL put the module URL back.

#### Scenario: Two kinds of import from a module at an absolute URL resolve against the cache URL

- **GIVEN** a module at a CDN URL that imports a rooted path like `/x.js`, or
  climbs with `..` past the CDN's host
- **WHEN** it's loaded
- **THEN** the import [resolves](../../../GLOSSARY.md#resolve) against the cache
  URL, not the CDN, unlike in a browser. Nothing Canvas builds imports that way.

### Requirement: A base cache is built once, shared, and never written again

The runner SHALL build a base cache once for each combination of base caches
directory, [base](../../../GLOSSARY.md#base) id, Deno binary and runner script.
It SHALL build it under an exclusive lock that concurrent renders wait on. Each
runner cache SHALL link in the base cache's modules and load them from there,
instead of copying or seeding them, and SHALL NOT write back into the base
cache.

### Requirement: No request waits longer than one build for a base cache

A request SHALL wait no longer for a base cache than the medium time limit,
counted from when its build started, whichever process runs it. A build that
takes longer, or whose Deno process fails or doesn't exit cleanly in time, so
may not have written its caches, SHALL leave nothing behind, and a request
that waited for it SHALL NOT build it too, since every request that waited
would then build it in turn. A lock file that can't be opened SHALL fail the
build at once, rather than read as another process's build. Then
`useBase()` SHALL return FALSE, and the build SHALL be retried with the long
limit after a response is sent, on a page cache hit too, and the request SHALL
log a notice, at most once an hour on each web server. Until a base cache is
built, renders of that base SHALL neither build nor wait. A render of a new base
SHALL try it as usual. A retry that fails SHALL be tried again 10 minutes later,
doubling up to an hour, and a new base at once. A retry that builds it SHALL say
so, and one that fails SHALL say when it's tried next; see
[runner-contract](../07-runner-contract/spec.md).

#### Scenario: A slow build falls back, and its retry builds it

- **GIVEN** a base whose preload module's top level takes 1.5 s, and a 400 ms
  medium limit
- **WHEN** a render uses the base
- **THEN** it gets FALSE within the medium limit, no base cache is left, and a
  retry is due
- **WHEN** a later render uses it
- **THEN** it gets FALSE at once, without the base being defined
- **WHEN** the retry runs
- **THEN** the base cache is built, and renders use it

#### Scenario: A build that never finishes backs off until the base changes

- **GIVEN** a base whose preload module's top level loops forever
- **WHEN** its retry runs
- **THEN** it fails within the long limit and isn't due for 10 minutes
- **WHEN** the site's base changes before then
- **THEN** the new base is built at the next retry

#### Scenario: A new base is tried while an old one waits for its retry

- **GIVEN** a base whose build gave up, and is waiting for its retry
- **WHEN** a render uses a new base
- **THEN** it builds the new base and uses it
- **AND** the retry after the response finds it built

#### Scenario: A render that gives up on a build says so

- **GIVEN** a base whose build runs past the medium limit
- **WHEN** a render uses it
- **THEN** a notice is logged

#### Scenario: A wait for another process's build ends with that build's limit

- **GIVEN** another process that started building 800 ms ago, and a 1 s medium
  limit
- **WHEN** a render uses the base
- **THEN** it gets FALSE after about 200 ms

#### Scenario: A wait for a build that fails ends with it

- **GIVEN** another process building the base cache, which fails within its
  limit
- **WHEN** a render waits for it
- **THEN** `useBase()` returns FALSE once it fails, without building, and the
  retry is scheduled

#### Scenario: Building a base cache ends its fallbacks

- **GIVEN** islands that fell back to CSR while their base cache was built
- **WHEN** a retry after the response builds it
- **THEN** the fallbacks are invalidated, and nothing is on the status report

#### Scenario: A base that never builds is on the status report

- **GIVEN** a base whose retry fails
- **WHEN** the status report is built
- **THEN** it warns that this web server hasn't built the base since it started
  failing, and says when it's tried again

#### Scenario: A base cache is built once and linked into each runner cache

- **GIVEN** two runners using the same base
- **WHEN** both render a component the base covers
- **THEN** the base is loaded once, both runner caches link to it, neither
  seeded a module of its own, and deleting a runner cache leaves the base cache
  as built

#### Scenario: A new runner finds the base cache instead of building it

- **GIVEN** a base cache an earlier runner built
- **WHEN** a new runner, as if in another process, uses the same base
- **THEN** it isn't built again

### Requirement: Base caches are kept while used, and stale builds are cleaned up

Using a base cache SHALL refresh every file in it, if it hasn't been marked used
for an hour. So a cleaner that ages out files one by one keeps it whole. A build
SHALL keep the 100 most recently used base caches, or 10 under the runner caches
root, however old they are. It SHALL delete build directories that a crash left
behind, but never one that's in progress, nor a base cache a runner is linking
in. A base cache deleted while a process remembers it SHALL NOT be linked, and
nothing SHALL be made in its place. Whatever is left where it was SHALL NOT stop
the next build.

#### Scenario: A build keeps the most recently used base caches

- **GIVEN** more than 100 base caches, unused for days
- **WHEN** a new one is built
- **THEN** the 100 most recently used remain and the rest are deleted

#### Scenario: A deleted base cache is rebuilt

- **GIVEN** a base cache a process has used, which is then deleted, leaving
  nothing, a stray file, an empty directory, or part of the base cache
- **WHEN** that process renders again
- **THEN** it renders without the base cache and leaves the path as it found it,
  and the next request rebuilds the base cache there

#### Scenario: Pruning skips a base cache in use

- **GIVEN** more than 100 base caches, the least recently used of which a runner
  is linking in
- **WHEN** a new one is built
- **THEN** that one is kept for a later build to delete

### Requirement: One Deno process serves a request, and none outlives it

The runner SHALL start one Deno process when a render first needs it, and keep
it for the request. So islands after the first
[evaluate](../../../GLOSSARY.md#evaluate) nothing the first already did. It
SHALL end the process on [reset](../../../GLOSSARY.md#reset) and after a [runner
failure](../../../GLOSSARY.md#runner-failure). A [script
failure](../../../GLOSSARY.md#script-failure) SHALL keep it, unless the process
had to stop mid-message, as for a result past the size limit. It SHALL start a
new one when a registered source or the [import
map](../../../GLOSSARY.md#import-map) changes under a URL the process may have
evaluated.

A process SHALL end soon after PHP does, however PHP ends. While it waits for
PHP, it reads stdin, so it SHALL exit once stdin closes. A shutdown function
SHALL kill any process still running, since a fatal error runs no destructors.
JS SHALL have no way to block without using CPU, which would leave a process
nothing could end but the wall clock, so `SharedArrayBuffer` is absent and
`Atomics.wait()` has nothing to wait on. Each process SHALL run in its runner
cache or base cache. Where PHP can list its open files and sockets, a process
SHALL hold none of them.

#### Scenario: A process holds nothing PHP has open

- **GIVEN** PHP with a file and a socket open
- **WHEN** a Deno process starts
- **THEN** it holds neither

#### Scenario: A process ends when PHP is killed

- **GIVEN** a PHP process with a Deno process, idle or waiting on PHP
- **WHEN** PHP is killed
- **THEN** the Deno process ends within seconds

#### Scenario: A process ends when PHP has a fatal error

- **GIVEN** a Deno process spinning in an invoke, with time limits longer than
  the test waits
- **WHEN** PHP runs out of memory
- **THEN** the Deno process ends within seconds

### Requirement: Three time limits bound what JS can cost

The runner SHALL take SSR's three time limits, short, medium and long, from
the `canvas_ssr.timeouts` container parameter. They default to 100 ms, 2 s and
10 s. Limits where medium is under twice short, or long under twice medium,
SHALL be refused. An [invoke](../../../GLOSSARY.md#invoke) SHALL fail
once its Deno process has spent the short limit on it, counted as CPU time
where the OS reports it, so waiting for a core on a busy host or for a bound
function doesn't count. The whole invoke SHALL take no longer than the medium
limit in wall time. A new process's first answer SHALL get the medium limit,
since it waits for Deno to start, and other control messages the short one.
The runner SHALL enforce each by ending the process, and hitting one counts as
a [runner failure](../../../GLOSSARY.md#runner-failure).

Each process SHALL run with a CPU time limit half again the long limit, lowered
to the host's own if that's lower, so a process PHP lost track of can't run
forever. The runner SHALL replace a process past half that limit after an
invoke, so no render is cut off by it.

#### Scenario: A module that never finishes is ended within the short limit

- **GIVEN** a module that loops forever
- **WHEN** it's invoked
- **THEN** the invoke fails as a runner failure once the process has spent the
  short limit, and the next invoke runs

#### Scenario: Waiting doesn't count against the short limit

- **GIVEN** a module that calls a bound function PHP takes 300 ms to answer,
  then runs for 350 ms, 300 of them with its process stopped, as on a busy host
- **WHEN** it's invoked with a 100 ms short limit, where the OS reports CPU time
- **THEN** it renders

#### Scenario: An invoke ends at the medium limit

- **GIVEN** a module that runs for longer than the medium limit, and a short
  limit longer still
- **WHEN** it's invoked
- **THEN** the invoke fails once the medium limit has passed

#### Scenario: Each process has a CPU time limit, and is replaced before it

- **GIVEN** a Deno process
- **WHEN** it starts
- **THEN** its CPU time limit is half again the long limit, or the host's own
  if that's lower
- **WHEN** an invoke leaves it past half that limit
- **THEN** the next invoke runs in a new process

### Requirement: Each Deno process has a memory limit

The runner SHALL take a memory limit, which defaults to 256 MiB. It SHALL be
the JS heap's limit. Where the OS reports a process's resident memory, the
runner SHALL also check it every 2 ms while an invoke runs, since buffers
aren't on the heap, and end the process past the limit. Going over either
counts as a [runner failure](../../../GLOSSARY.md#runner-failure). Each process
SHALL be the first the kernel kills when the host runs out of memory, so that's
a CSR fallback rather than another service failing.

#### Scenario: A heap past the limit is a runner failure

- **GIVEN** a 64 MiB limit, and a module that keeps allocating objects
- **WHEN** it's invoked
- **THEN** the invoke fails as a runner failure, and the next invoke runs

#### Scenario: Buffers past the limit are a runner failure

- **GIVEN** a 64 MiB limit, and a module that keeps allocating buffers, where
  the OS reports resident memory
- **WHEN** it's invoked
- **THEN** the invoke fails as a runner failure that names the limit

#### Scenario: A process starts with its limits

- **GIVEN** a Deno process
- **WHEN** it starts
- **THEN** its heap limit is the memory limit, its OOM score adjustment is
  1000, and it runs in its runner cache

### Requirement: An island's HTML is at most 4 MiB

A message from a Deno process SHALL be at most 4 MiB. The largest is an invoke's
result, an island's HTML, which holds its slots'. A longer one SHALL be a
[script failure](../../../GLOSSARY.md#script-failure), since the same inputs
would write it again, and the process SHALL end, since it's mid-message.

#### Scenario: A result past the limit is a script failure

- **GIVEN** a module that returns 4 MiB of text, and one that returns a little
  less
- **WHEN** each is invoked
- **THEN** the first fails as a script failure, and the second renders

### Requirement: The status report says what the runner can't enforce here

Where PHP has a function the runner needs disabled, or can't read `/proc`, the
runner SHALL say so. Without `proc_open()` and the functions that manage the
process, it SHALL be unavailable, naming them. Without `/proc`, as on macOS or
under `open_basedir`, it SHALL report that it limits time as wall time, and
memory only on the heap. The status report SHALL list what each web server
reported, as it does a runner that's unavailable.

#### Scenario: Disabled process functions make the runner unavailable

- **GIVEN** PHP with `proc_open()` disabled
- **WHEN** availability is checked
- **THEN** the runner is unavailable, and names what's disabled

#### Scenario: An unreadable /proc is a limitation

- **GIVEN** PHP whose `open_basedir` leaves out `/proc`
- **WHEN** the runner's limitations are asked for
- **THEN** it names the weaker time and memory limits
