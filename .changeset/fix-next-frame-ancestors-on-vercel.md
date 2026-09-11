---
"@drupal-canvas/headless-next": minor
---

Resolve the `frame-ancestors` policy from configuration instead of the draft
session cookie.

- Draft previews were refused on Vercel. The policy came from a `next.config`
  header rule conditioned on the session cookie, and that condition is matched
  against the raw, percent-encoded `Cookie` header there but against the
  decoded value by Next.js's own server, so the editor origin was never added
  and the editor's iframe was blocked. A rule with no condition behaves the
  same on every host.
- The policy now admits every origin `CANVAS_EDITOR_ORIGINS` names, defaulting
  to the origin of `CANVAS_SITE_URL`, so a single-origin deployment needs no
  new configuration. Set it when editors reach Drupal on a different origin
  than the app server does, or on more than one.
- Values must be `http(s)` origins without credentials, naming a domain, an
  IPv4 literal, or a bracketed IPv6 literal; only their scheme, host, and port
  are used. Wildcard hosts are not accepted.
