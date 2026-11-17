# Canvas SSR Deno runner

`DenoRunner` is Canvas SSR's default runner. It runs each request's
JavaScript in a Deno process with no permissions. It's tagged
`canvas_ssr.runner` at priority 0, so a runner module outranks it with any
positive priority.

It ships inside `canvas_ssr`, so there's nothing extra to enable. Without a
binary it reports itself unavailable and costs nothing.

It needs a POSIX system, such as Linux or macOS: it starts Deno through
`/bin/sh` and keeps its caches in directories private to an OS user. It
doesn't run on Windows.

The words used here are defined under [Deno
runner](../../../docs/GLOSSARY.md#deno-runner) in the glossary. What the runner
guarantees is in its
[spec](../../../docs/specs/semi-detailed/10-deno-runner/spec.md), and why it
works this way is in ADRs
[8](../../../docs/adr/0008-a-deno-process-lives-for-one-request.md),
[9](../../../docs/adr/0009-deno-runs-with-no-permissions.md) and
[10](../../../docs/adr/0010-a-base-is-built-once-and-never-changes-output.md).

## Getting the binary

The runner looks for `deno` in the `bin` directory of Composer's vendor
directory, usually `vendor/bin/deno`. It can be a binary or a symlink.
Nothing installs it: there's no Composer dependency. If your project sets
Composer's `bin-dir`, or the binary is elsewhere, set `$denoBinary` in the
service's arguments, as below.

```bash
curl -fsSL https://deno.land/install.sh | sh   # or a distro package
ln -s "$(command -v deno)" vendor/bin/deno
```

Use Deno 2.9 or later, and prefer a release line Deno still supports. 2.7
and 2.8 work too, but earlier releases have `node:` modules the Deno import
map doesn't block. The version isn't checked. `isAvailable()` checks that
the binary is executable, that PHP's process functions are enabled and that
the cache directories can be used, but starts nothing: the Deno process
starts when a render first needs it. If the binary can't run
`js/runner.js`, that render fails and falls back to CSR.

## Cache directories

The runner keeps its files in `<root>/drupal-<uid>/canvas/ssr/deno` under
each of two roots, where `<uid>` is the [OS
user](../../../docs/GLOSSARY.md#os-user) PHP runs as, such as `drupal-33`
for `www-data` on Debian:

- **Base caches** outlive requests, so their default root is `/var/tmp`,
  which survives reboots.
- **Runner caches** are made for one runner and deleted when it resets, or
  when PHP ends, even with a fatal error. One is left behind only if PHP is
  killed. Every render writes there, so tmpfs is fastest. The default root
  is the system temp directory, usually `/tmp`.

Neither default uses `$HOME`, which often isn't writable under a web server.

The directory is per OS user, not per site, so sites run by one OS user
share base caches. Another OS user who could change any directory on the
way to it could put modules into that OS user's renders, or read them. So
the runner only uses a root when:

- every directory from `/` to the root is owned by root or by PHP's OS user,
  and nobody else may write to it unless it has the sticky bit, like `/tmp`;
- the OS user's directory is a directory, not a symlink, owned by PHP's OS
  user. If others may read, write or enter it, the runner makes it `0700`.

A root that doesn't exist is created if its parent passes, world-writable
with the sticky bit so other OS users can share it. Everything below the OS
user's directory is created one directory at a time.

Each request checks the whole path at its first render. Before Deno gets
control, and before each batch of writes, the OS user's directory is checked
again. If it was deleted or replaced, the runner stops using it without
deleting anything in it, logs an error, and that request's islands fall
back to CSR. Only root can delete it from under a sticky root, so don't
delete the cache directories while the site is serving requests.

To set the roots or the binary, define the service again in a site's
`services.yml`, one that `$settings['container_yamls']` lists, with them
among its arguments. That definition replaces canvas_ssr's, so copy all of
it, tag included. Without the tag, the runner is never selected, and every
island renders on the client:

```yaml
services:
  Drupal\canvas_ssr\JsRunner\Deno\DenoRunner:
    arguments:
      $timeouts: '%canvas_ssr.timeouts%'
      $logger: '@canvas_ssr.throttled_logger'
      $baseCachesRoot: /var/cache
      # $runnerCachesRoot: /dev/shm
      # $denoBinary: /usr/local/bin/deno
    tags:
      - { name: canvas_ssr.runner, priority: 0 }
```

Only root may write `/var/cache`, so create the OS user's directory there
first:

```bash
install -d -o www-data -m 0700 /var/cache/drupal-33
```

Both are safe to delete while the site is idle; everything is rebuilt when
needed. If the base caches root can't be used, as when `open_basedir` leaves
out `/var/tmp`, base caches go under the runner caches root instead, beside
the runner caches. Only the 10 most recently used are kept there, since
that root is often tmpfs, and they may not outlast a reboot or a deploy, so
the runner logs a warning and the status report lists it as a limitation.
If the runner caches root can't be used, the runner reports itself
unavailable. Unless another runner is available, SSR falls back to CSR, and
the status report says SSR is off on that web server and why. If it's a
directory, the runner also logs a warning that says who owns what and
how to fix it. Each web server logs each such message at most once an hour,
however PHP runs, since the runner finds the same problem on every request.
A directory replaced while in use is an event rather than a standing
condition, and how often it recurs says what's deleting it, so that error is
logged up to once a minute. The limits are kept in Drupal's expirable
key-value store.

Any other OS user can make a directory under a shared root like `/var/tmp`,
including one named for PHP's OS user, before PHP does. The runner won't use
it, so that turns SSR off until root deletes it. To rule that out, and any
other OS user's interference, give PHP a private temp directory, such as
systemd's `PrivateTmp=true` for the PHP-FPM service, or set both roots to
directories only PHP's OS user may write.

The runner keeps the 100 most recently used base caches, or 10 under the
runner caches root, and leaves older ones to the OS's temp cleanup. A base
cache is locked shared while a runner links it in, and exclusively while
it's built or pruned, so pruning never deletes one in use. If one is deleted
anyway, a process that still remembers it renders without it, and the next
request rebuilds it, replacing whatever was left there.

## Building a base cache

A request waits the medium time limit for a base cache, whether it's building
it or another process is. A waiting process reads when the other build started
from its lock file, so no request waits longer than one build may take.
Building imports every component's module too, and a component's top level can
loop forever or never settle. So a build that runs past its limit is killed
and leaves nothing behind, and so does one whose Deno process fails.

The request then renders its islands with CSR, writes `retry.json` in the
base caches directory, and logs a notice, at most once an hour on each web
server. While that file is there, renders of that base don't build or wait:
their islands render with CSR at once. A render of a new base tries it as
usual, since it hasn't failed. The CSR fallbacks are cached with the
`canvas_ssr_base_pending` cache tag and the base's own tags, with no expiry,
so a site's pages aren't re-rendered while it waits.

At the end of every request, page cache hits included, canvas_ssr's
`PendingBaseSubscriber` asks the runner whether a base is pending, which here
costs an `lstat` and one failed open when the file isn't there. The runner
reads the file only from a directory that is its OS user's alone. If a retry
is due, the runner builds the base cache with the long limit, after the
response is sent. If that works, it deletes the file and the subscriber
invalidates `canvas_ssr_base_pending`, so those islands render with SSR next
time. If it fails, the next retry is 10 minutes later, doubling up to an hour,
and the status report says so. A new base is tried at once.

On a host that has just booted, the first build can take seconds while the
disk is cold. Then the first requests render CSR, and the retry builds the
base cache within seconds. The status report only mentions a base whose
retry failed too, but the notice shows up in the log, so a site whose new
hosts keep starting that way can tell.

Each web server records a base that won't build, or no runner, in the
expirable key-value store, which every web server shares. The status report
is served by one of them, so it lists every web server's problems. A record
lasts a few hours unless its web server renews it, so one that's gone drops
off by itself.

## Time limits

The runner takes SSR's three time limits, in milliseconds, through its
constructor's `$timeouts`. The service passes the `canvas_ssr.timeouts`
container parameter, which a site can override in its `services.yml`. See
`\Drupal\canvas_ssr\Timeouts` for what each one limits. Here's how this
runner applies them.

| Limit | Default | Limits |
| --- | --- | --- |
| short | 100 | what one invoke's JS may spend, as CPU time where `/proc` reports it, and control messages |
| medium | 2000 | what a request waits for in wall time: an invoke with its bound function calls, a new process's first answer |
| long | 10000 | each process's CPU time limit, at half again, through `ulimit -t` |

Counting CPU time means a process waiting for a core on a busy host, or for
PHP to answer a bound function, isn't failed for it. On macOS, without
`/proc`, it's wall time less the time PHP spent answering.

Each Deno process starts through `/bin/sh`, which sets its CPU time limit and
then runs Deno in its place. A host's lower hard limit wins. The limit ends a
process PHP lost track of, like one left spinning when PHP died. So that it
never cuts a render off, the runner replaces a process past half its limit
after an invoke.

## Memory and process limits

Each Deno process may use 256 MiB, which the constructor's `$memoryLimitMib`
sets. That's the JS heap's limit, which V8 enforces. Buffers like `ArrayBuffer`
aren't on the heap, so where `/proc` is readable, PHP also checks the process's
resident memory every 2 ms while an invoke runs, and kills it past the limit.
That's checked only while JS runs, which is only during an invoke. It can get
a few tens of MiB past the limit between checks. Pages measured so far
peaked at about 100 MiB.

Each PHP worker rendering an island has its own Deno process, so the memory a
host must spare is the limit times how many workers render at once. If the host
runs out anyway, the kernel kills a Deno process first: each runs with an OOM
score adjustment of 1000, so a render falls back to CSR rather than something
else failing.

One message from a Deno process is at most 4 MiB. The largest is an island's
HTML, which holds its slots' HTML, so a layout's can hold a page's. A longer
one is a script failure, cached like a render.

A Deno process never outlives PHP for long. While it waits for PHP, it reads
stdin, which closes however PHP ends, and then it exits. While it runs JS, its
CPU time limit ends it. And a shutdown function kills it, since a fatal error
in PHP runs no destructors. No JS can block without using CPU:
`SharedArrayBuffer` is absent, as in a page that isn't cross-origin isolated,
so `Atomics.wait()` has nothing to wait on.

The status report says when a host keeps the runner from enforcing any of
this, as when `/proc` can't be read, on macOS or under PHP's `open_basedir`.
Then only the heap is limited, and invoke time is wall time.

### What to watch for on a host

- **Process functions.** The runner needs `proc_open()`, `proc_get_status()`,
  `proc_terminate()` and `proc_close()`. Hosts that disable them in
  `disable_functions` leave it unavailable, and the status report says why.
- **Process and thread limits.** A Deno process has one thread per core, plus
  about four, and more while it reads modules: up to about 20 on 4 cores
  while loading a page of about 150 modules. Linux counts threads against a
  limit on processes per OS user, like `ulimit -u`, so allow about 20 per PHP
  worker that renders.
- **Memory limits.** A limit on virtual memory, like `ulimit -v`, keeps Deno
  from starting at all, since V8 reserves far more address space than it uses.
  Limit resident memory instead.
- **Cache directories.** The runner caches root must be writable. The base
  caches root should be too, and should outlive a deploy, or every new
  container rebuilds its base caches. A read-only root filesystem, or a
  private `/tmp` per service, as systemd's `PrivateTmp` gives, needs the roots
  set to somewhere writable. Where temporary storage counts against a quota,
  base caches count too, though they're pruned.
- **Trusted hosts.** Canvas's `v0.baseUrl` site data includes the host, so
  each hostname a site answers to gets its own base, built once. Set
  `trusted_host_patterns`, as Drupal's status report asks. Without it,
  Drupal accepts any Host header, and each new one costs a base build.
- **Large islands.** Very large HTML is a strain on caches too. Drupal stores
  each island's HTML and each page, so a database cache's packet size, like
  MySQL's `max_allowed_packet`, can refuse what's bigger.

## Security model

The Deno process runs code a site's editors wrote, on the web server:

- **No permissions.** It gets no `--allow-*` flag, so it can't read files or
  open sockets.
- **Only seeded modules.** With `--cached-only`, every import is looked up in
  the runner cache and nowhere else, so a remote URL is a miss, not a fetch.
- **No Node.** `npm:` specifiers are refused, and every `node:` module is
  blocked.
- **Only the supported web platform APIs.** Before any component loads,
  `js/runner.js` deletes every global but the ECMAScript ones and those in
  its list. That takes the `Deno` and Node globals, and Deno's host APIs,
  like timers, `Worker`, `fetch()`, `WebSocket`, storage and WebGPU. The
  list can evolve as components need more. It deletes two ECMAScript ones
  too: `SharedArrayBuffer`, which browsers withhold, and
  `FinalizationRegistry`, whose callbacks could run in a later island.
- **No storage on disk.** Deno keeps `localStorage` and the Cache API in
  `location_data` under the `DENO_DIR`. Both are deleted, and `DenoProcess`
  also puts an empty file there, so Deno can't make that directory, whoever
  it runs as.
- **Nothing of PHP's.** Each of PHP's open files and sockets above stderr,
  such as a database connection, is replaced with `/dev/null` in the Deno
  process, where PHP can list them, so the process holds none of them.
- **Nothing to import.** `js/runner.js` is one file that imports and exports
  nothing. Deno lets code import what's already loaded, so a component that
  imports it by its file URL gets an empty module, not the protocol.

The runner cache is the boundary, so never point `DENO_DIR` at a directory
something else writes modules into. `SandboxTest` checks that component
code can't get out.

## Code

- `DenoRunner` holds a runner's registrations and decides when its Deno
  process is replaced.
- `DenoProcess` starts one Deno process and speaks the protocol to it.
- `ModuleSeeder` seeds registered modules into a cache directory, owns the
  directory layout Deno reads them from, and derives the Deno import map.
- `RunnerCache` makes, checks and removes one runner's runner cache.
- `BaseCaches` builds, links and prunes base caches, each described by a
  `BaseManifest`.
- `BaseRetry` keeps the record of a build a request gave up on, until the
  runner's `buildPendingBase()` builds it after a response.
- `OsUserDirectory` makes and checks each OS user's directory under a root.

## Tests

The runner's tests live in `tests/src/Unit/JsRunner/Deno/`:

- `ModuleSeederTest` covers seeding and the Deno import map, without Deno.
- `DenoConformanceTest` runs `RunnerConformanceTestBase` against this
  runner.
- `SandboxTest` tests the security model above.
- `BaseCacheTest` tests how base caches are built, linked and pruned.
- `ProcessLifetimeTest` tests no Deno process outlives PHP, however PHP ends.
- `HostSupportTest` tests what the runner reports when PHP's settings keep it
  from running, or from enforcing a limit.
- `DenoProcessTest` tests when the runner starts and ends its Deno process,
  and its time, memory and message limits.
- `BaseBuildTimeTest` tests how long a request waits for a base cache, and
  the retries after.
- `OsUserDirectoryTest` tests which directories the runner trusts with its
  caches.

All but `ModuleSeederTest`, `HostSupportTest` and `OsUserDirectoryTest` skip
where no usable binary is installed.

Kernel tests cover the rest:

- `tests/src/Kernel/JsRunner/Deno/BaseRetryTest` tests the retry after the
  response, the cache tag it invalidates, and the status report.
- `tests/src/Kernel/JsRunner/Deno/DenoProcessReuseTest` tests a page's
  islands render in one Deno process.
- `tests/src/Kernel/JsRunner/Deno/RunnerSelectionTest` tests where the
  selector ranks Deno among the runners.

```bash
vendor/bin/phpunit path/to/canvas_ssr/tests/src/Unit/JsRunner/Deno
```

The SSR suite runs on this runner unless `CANVAS_SSR_TEST_RUNNER_CLASS`
names another. To pick it where something else sets that, name its class:

```bash
CANVAS_SSR_TEST_RUNNER_CLASS='Drupal\canvas_ssr\JsRunner\Deno\DenoRunner' \
  vendor/bin/phpunit path/to/canvas_ssr
```
