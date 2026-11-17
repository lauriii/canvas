# Architecture decision records

Each record says what was decided, what else was considered, and what follows.
The [specs](../specs/) say how canvas_ssr behaves; these say why it's built the
way it is. Read in order: the first three are about what a render means, the
next four about what a runner is and what crosses into it, the next three
about the runner that ships and the optimization over all of it, and the last
two about what islands sharing a realm may do to each other, and what code is
refused because of it. The last is about what a hydrated island takes to the
browser.

1. [A component's code means what it means in the
   browser](0001-components-mean-what-they-mean-in-the-browser.md)
2. [Any failure falls back to CSR, and the fallback is cached by why it
   failed](0002-any-failure-falls-back-to-csr.md)
3. [A boundary checker decides hydration, and a static island ships no
   code](0003-a-boundary-checker-decides-hydration.md)
4. [Runners are pluggable, the conformance suite defines them, and one
   ships](0004-runners-are-pluggable-and-one-ships.md)
5. [Bindings are synchronous and carry strings
   only](0005-bindings-are-synchronous-and-carry-strings.md)
6. [`fetch()` is a subrequest: allowlisted GET and HEAD, as the current
   user](0006-fetch-is-a-drupal-subrequest.md)
7. [A component never renders without the data it
   declared](0007-never-render-without-declared-data.md)
8. [A Deno process lives for one
   request](0008-a-deno-process-lives-for-one-request.md)
9. [Deno runs with no permissions and reads only a cache we
   seed](0009-deno-runs-with-no-permissions.md)
10. [A runner builds from a base once, and using it never changes what a
    render outputs](0010-a-base-is-built-once-and-never-changes-output.md)
11. [Islands share one realm, locked before any component
    runs](0011-islands-share-a-locked-realm.md)
12. [SSR may refuse code that works in the
    browser](0012-ssr-may-refuse-code-that-works-in-the-browser.md)
13. [A hydrated island carries its libraries' state to the
    browser](0013-a-hydrated-island-carries-its-libraries-state.md)

A new record takes the next number. Only write one when the decision is hard to
reverse, would surprise a reader without context, and had real alternatives.
