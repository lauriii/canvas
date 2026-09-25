---
'@drupal-canvas/cli': minor
---

`canvas pull` migrates safe `getPageData()` and `getSiteData()` calls in
pulled components to the `usePageContext()` and `useSiteContext()` hooks from
`drupal-canvas/react`, when the connected site advertises context-hook support
and the project's installed React entry exports both hooks as runtime values. The
migration is all-or-nothing per file: a call must be an imported binding
called without arguments at an unconditional hook position in a component or
custom hook, with no possible earlier return and no identifier conflicts.
Planned conversions show before the pull is confirmed and honor `--yes` and
`--skip-overwrite`; remaining cases, `new JsonApiClient()` construction, and
helper modules are reported with an AI-agent migration prompt.

The codemod resolves namespace imports conservatively (bare hook calls with a
root import, unchanged files on shadowed or computed namespace use), treats
only `memo`/`forwardRef` wrappers as components, and never imports a hook
twice.

React wrappers are recognized by their `react` import binding and only for
their component argument, and a replacement hook name shadowed anywhere in
the file leaves the file unchanged.

Component positions are decided syntactically before the name (named
callbacks passed to other calls are not components), and re-exported getters
leave the file unchanged.
