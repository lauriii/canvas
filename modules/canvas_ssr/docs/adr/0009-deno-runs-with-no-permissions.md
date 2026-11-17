# 9. Deno runs with no permissions and reads only a cache we seed

Status: Accepted

## Context

The Deno process runs code the site's editors wrote, on the web server, next to
`settings.php`. Deno sandboxes code with permission flags. Without
`--allow-read` it can't read files, and without `--allow-net` it can't open
sockets. But Deno has to get modules from somewhere, and whatever hands it
modules is also a way into the process.

## Decision

The process gets no `--allow-*` flags at all. It also gets `--cached-only`,
`--no-npm`, `--no-config`, `--no-lock` and `--no-prompt`.

Modules reach it through Deno's own on-disk module cache:

- PHP [seeds](../GLOSSARY.md#seed) each registered source into that cache at
  a [cache URL](../GLOSSARY.md#cache-url). That's the [module
  URL](../GLOSSARY.md#module-url) turned into a path on an
  [origin](../GLOSSARY.md#origin) nothing listens on.
- With `--cached-only`, Deno looks up every import in that cache, and nowhere
  else.
- A [Deno import map](../GLOSSARY.md#deno-import-map), built from the [SSR
  import map](../GLOSSARY.md#ssr-import-map), sends bare
  [specifiers](../GLOSSARY.md#specifier) and absolute module URLs to their
  cache URLs. It also maps every `node:` module to null.
- The Deno runner doesn't parse a source or rewrite its imports. Deno
  resolves relative imports itself, to the right cache URL.

Each [runner](../GLOSSARY.md#runner) gets its own [runner
cache](../GLOSSARY.md#runner-cache), and [`reset()`](../GLOSSARY.md#reset)
deletes it. So no request can import what another one seeded. The directories
live under a directory per [OS user](../GLOSSARY.md#os-user) that only its
owner can read, under `/var/tmp` and the system temp directory, never `$HOME`.
If another OS user could change one, or any directory on the way to it, the
runner doesn't use it.

`runner.js` deletes every global but the ECMAScript ones and the supported
[web platform APIs](../GLOSSARY.md#web-platform-api) before any
[component](../GLOSSARY.md#component) loads, which takes the `Deno` and Node
globals. Only the protocol goes to stdout. `console` goes to stderr.

## Alternatives considered

### An HTTP module server

It needs `--allow-net`, a listening socket and a free port. It would also give
the process a network it otherwise doesn't have.

### `--allow-read` on a modules directory

Modules would have `file:` URLs, so a server path would show up in
`import.meta.url`. A read permission also covers more than "load these modules".
It covers every file in the tree.

### `blob:` URLs and rewritten imports

Every module becomes a `blob:` URL, and each import is rewritten to point at the
right blob. That works with no permissions. But the rewriter has to redo every
rule the browser uses to resolve imports, and parse every module on the way in.
Seeding the cache lets Deno do the resolving.

### One shared cache directory

It's cheaper, but then a request could import a module an earlier one seeded.
And anyone who could write to the directory could put modules into everyone's
[renders](../GLOSSARY.md#render).

## Consequences

- Registering a module is a file write in PHP. The process needs no permission
  to load it.
- Cache URLs stay the same from one process to the next. Deno's dependency
  analysis and V8's code cache are both keyed by URL, so they carry across
  requests.
- Two imports resolve differently than in a browser, because Deno resolves them
  against the cache URL. One is a rooted import from a module at an absolute
  URL. The other is `..` past that URL's host. Nothing Canvas builds imports
  that way, and the spec lists both.
- A running process can't pick up a changed source under a URL it may already
  have [evaluated](../GLOSSARY.md#evaluate). That takes a new process.
- The list of `node:` modules has to match the Deno version in use. A test
  checks it against Deno's own list.

Specs: [Deno runner](../specs/semi-detailed/10-deno-runner/spec.md).
