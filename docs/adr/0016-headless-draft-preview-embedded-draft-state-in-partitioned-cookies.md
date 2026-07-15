# 16. Headless draft preview: embedded draft state in partitioned cookies (CHIPS)

Date: 2026-07-07

Issue: <https://git.drupalcode.org/project/canvas/-/work_items/3591775>

## Status

Accepted

## Context

The headless draft preview's session state is cookie-based: the frontend framework's own draft-mode cookie plus the
draft SDK's session cookie carrying the entry path, the revision policy, the user-bound access token
([ADR-0014](0014-headless-draft-preview-user-bound-tokens-via-jwt-assertion-grant.md)), and the PKCE verifier that
authorizes the session's next renewal
([ADR-0015](0015-headless-draft-preview-session-renewal-re-anchored-in-drupal-session.md)). Two credentials, then, not
one: `httpOnly` keeps page JavaScript from reading the token *and* from stealing the proof needed to mint another.

Cookies used by an iframe whose site differs from the top-level site are third-party cookies: when the Canvas editor
frame embeds the app, the browser restricts the embedded app's access to *its own* cookies. No cookie ever crosses
between Drupal and the app — the classification is purely contextual, and it is inherent to cookie-based draft mode.
Two independent problems follow:

- Frameworks commonly set their draft-mode cookie `SameSite=Lax` (Next.js, for example, does). The `SameSite`
  attribute tells the browser which cross-site requests may carry a cookie, and `Lax` limits that to top-level
  navigations — requests from inside an iframe never qualify — so draft mode silently stays off inside the editor
  frame while working in a standalone tab.
- Browsers increasingly block or partition third-party cookies outright, with policies that vary per browser and per
  user configuration.

[Next.js for Drupal](https://next-drupal.org/) — the reference implementation this ADR series builds from
([ADR-0014](0014-headless-draft-preview-user-bound-tokens-via-jwt-assertion-grant.md)) — overrides the framework's
`Lax` default with `SameSite=None; Secure`, which addresses the first problem but leaves the second — blocking and
partitioning — unaddressed. It also sets no frame headers at all, so any site can iframe a draft-capable app — a
clickjacking surface.

## Decision

The frontend half of this design belongs in a framework-independent package, **`@drupal-canvas/headless`** — the
draft SDK this ADR series refers to — with framework-specific packages such as `@drupal-canvas/headless-next`
providing the glue between the generic implementation and each framework's request and cookie APIs. Everything
decided below is behavior of the generic package and holds for any framework; Next.js appears only as an example.
The example app predates this split and still implements the SDK inline as Next.js code — extracting it into these
packages is pending work.

Keep draft mode cookie-based, and mark both draft cookies **`Partitioned`** (CHIPS — Cookies Having Independent
Partitioned State), alongside `SameSite=None; Secure; httpOnly; Path=/` — sent on cross-site requests (`None`), over
HTTPS only (`Secure`, which both `None` and `Partitioned` require), unreadable by page JavaScript (`httpOnly`), and
valid for the whole app (`Path=/`). The attributes are set explicitly rather than inherited from the cookie the
framework wrote: the cookie store's read contract only guarantees name and value, and a token-carrying cookie must
not depend on the runtime happening to preserve the rest.

A `Partitioned` cookie gets a separate copy per embedding context: the browser keys its storage on the pair of
top-level site and embedded site, so the cookie the app sets while embedded in the Canvas editor exists only there,
and the cookie it sets when visited directly exists only there — neither context can see the other's. That is why
partitioned cookies remain allowed where ordinary third-party cookies are blocked: they cannot correlate a user
across sites. The separation also matches draft preview exactly: draft mode enabled inside the editor frame does not
turn drafts on for normal browsing of the app in the same browser, and vice versa.

Deletion must carry the same partition attributes the cookie was set with — browsers otherwise leave the cookie
alive. Framework and platform deletion helpers commonly emit deletions without `Partitioned` (Next.js's
`draftMode().disable()` and its cookie store's `delete()` both do), which silently makes draft mode impossible to
exit while cookie-jar-based tests (curl) keep passing; the SDK therefore deactivates by overwriting both cookies
with expired equivalents carrying the original attributes.

Additionally, every app response is protected by a Content Security Policy `frame-ancestors` directive. An
application-defined directive remains authoritative. Otherwise, responses without a draft session allow only
`'self'`; responses with a draft session also allow the exact Drupal editor origin derived from the signed `renewUrl`
claim in that session. The origin is therefore cryptographically bound to activation and scoped to the session,
rather than repeated in a second environment setting. The same exact origin is the app's sole postMessage peer for
the renewal protocol ([ADR-0015](0015-headless-draft-preview-session-renewal-re-anchored-in-drupal-session.md)).

## Alternatives considered

- **Same-site hosting** (frontend and CMS under one registrable domain): the only configuration in which the problem
  does not exist at all, in any browser, with nothing extra. But it is a deployment property, not something a
  framework can assume — and a rare one, since most headless deployments put the frontend and Drupal on different
  sites. An option, never "the answer".
- **Storage Access API**: the embedded app requests access to its own cookies after a user gesture (an "Enable
  preview" click). The standards-track answer for Safari versions without CHIPS and for stricter privacy
  configurations. Not adopted: it adds a user-visible interaction step to every embed, and CHIPS covers the primary
  browsers without one; it remains the compatible fallback should those configurations need first-class support.
- **Cookieless draft transport**: propagate the validated draft proof in URLs or client-held state, bypassing cookie
  policy entirely — the only path that works under "block all third-party cookies". It means leaving the framework's
  built-in draft mode: URL propagation demands draft-aware links on every internal navigation; client-state transport
  splits rendering into a second, client-driven path. Most universal, highest cost — and effectively the shape a
  purely client-side (SPA) consumer of the assertion grant would take anyway.

## Consequences

- **The frontend app must be served over HTTPS for embedded draft mode to work, with one exception: Chromium-based
  browsers on localhost.** `Partitioned` requires `Secure`, and Chromium treats `http://localhost` as a potentially
  trustworthy origin, which is what keeps a plain-http local dev server workable — in Chromium-based browsers only;
  Firefox refuses the cookies in a cross-site iframe over plain http, and Safari grants localhost no such exemption.
  Standalone draft mode is unaffected everywhere.
- Over HTTPS, embedded draft mode works in Chromium-based browsers, Firefox, and Safari — Safari subject to its
  CHIPS availability (shipped in 18.4, withdrawn in 18.5, reintroduced in 26.2): embedded draft mode is unavailable
  on the versions in between.
- Users who block *all* third-party cookies (Firefox custom ETP) reject the cookies outright — `Partitioned` does not
  exempt them there, unlike Chromium's blocking mode; the remedy is a per-site exception for the Drupal site.
