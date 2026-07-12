# 15. Headless draft preview: sessions renew by re-assertion from the live Drupal session

Date: 2026-07-07

Issue: <https://git.drupalcode.org/project/canvas/-/work_items/3591775>

## Status

Accepted

## Context

The draft preview's credentials are deliberately short-lived
([ADR-0014](0014-headless-draft-preview-user-bound-tokens-via-jwt-assertion-grant.md)): the assertion lives 60
seconds (it only needs to survive the gap between minting and the app's first load), the access token 15 minutes, and
the draft cookies are session-scoped. A token that expires mid-session must not end the preview, and it must not
silently downgrade it either — published content masquerading as a draft is the failure mode to avoid.

**Expiry is invisible exactly where it matters most**: an embedding host (the Canvas editor frame) shows the app
chrome-less in an iframe, so an in-app banner is not seen and expiry would look like content quietly vanishing. And
**expiry is clock-based, not 401-based**: the app knows the token's expiry to the millisecond from its own session
cookie, so renewal can be scheduled ahead of time rather than reacted to.

The question to answer is **what authorizes a renewal**. There are only three options: a refresh token; the live
Drupal session, reached via the embedding host; or the live Drupal session, reached via top-level navigation.

## Decision

Sessions renew by **re-assertion**: shortly before the token expires, a fresh assertion is minted from the editor's
live Drupal session and redeemed in place — the same exchange as activation, so every renewal re-proves the session,
the permission, and the account's status. The Drupal session is the revocation boundary: log out of Drupal and
previews stop renewing when the current token lapses. The two lanes below are not redundant fallbacks; they are the
same authority reached by the only two transports that exist.

Both minting routes accept **cookie authentication only**. Simple OAuth registers a global authentication provider, so
without the restriction a bearer token holding the preview permission could call them — chaining itself into a fresh
assertion, then a fresh token, indefinitely. That would undo the revocation boundary above, so the routes enforce it
directly: only the live Drupal session mints. (Core's header CSRF check does not close this on its own; it explicitly
permits requests carrying no session cookie.)

**The renewal lane (embedded).** A three-message postMessage protocol between the app and its host, origin-checked in
both directions (the app validates against the same origin allowlist that feeds its CSP `frame-ancestors`; the host
validates the frontend origin from module config and addresses replies to it, never `*`):

| Direction  | Message                          | Meaning                                          |
| ---------- | -------------------------------- | ------------------------------------------------ |
| app → host | `canvas-headless:status`         | Session state, on load and on every change       |
| app → host | `canvas-headless:renew-request`  | "Mint me a fresh assertion", sent before expiry  |
| host → app | `canvas-headless:assertion`      | A freshly minted assertion to redeem in place    |

Renewal is **proactive**: the app schedules the request a minute ahead of expiry, clamped to half the token's
remaining life so an unusually short site-configured TTL renews at half-life instead of degenerating into an
immediate-renewal loop. The host fetches the module's same-origin, session-authenticated, CSRF-protected,
permission-checked assertion endpoint — minting for the path the editor is currently on — and posts the assertion
down. The app redeems it at its own renewal endpoint and re-renders with the new token; no document reload, no
navigation loss.

Relayed assertions are **bound to the app server with PKCE**: redeeming one also requires a secret (the PKCE code
verifier) that only the app's server holds, so an intercepted assertion is useless on its own. The protection matters
because the relay passes through the embedded page's script context, where an injected script could intercept the
assertion — and origin checks cannot help against a script running *on* the allowed origin. The host mints
renewal-lane assertions with a `use: renewal` claim; the grant only redeems those together with a `code_verifier`
whose S256 hash was registered as the `code_challenge` of the session's previous token exchange. The verifier lives in the app's `httpOnly` session cookie, out of any script's
reach, and rotates on every *successful* redemption — a rejected one leaves the current challenge intact, so a
session is never stranded by a failure that happened after its proof was checked. Challenges can only be
bootstrapped by redeeming an activation assertion
(`use: activation`), which travels in a URL and is redeemed server-side on arrival, never entering script context —
so an intercepted renewal assertion redeems nowhere except back at the app's own renewal endpoint, which is just a
normal renewal.

Renewal is **continuation-only**: with no running session to renew, the app's renewal endpoint refuses rather than
starting one — activation stays the preview URL's job. And it is **identity-pinned**, because a renewal is a
continuation, not an activation: the session records the editor's `sub` at activation, and a renewal whose assertion
names anyone else (the browser's Drupal session changed hands mid-preview) is refused before the exchange, leaving
the assertion unconsumed and the session untouched. A session can change identity only through an explicit new
activation. Activation deliberately has no such check: a preview URL arriving top-level is an explicit new session
for whoever holds the Drupal session.

The renewal scheduling lives in the browser but holds no state a reload could lose: the timer is re-derived from the
session cookie's recorded expiry on every document load.

**The recovery lane (embedded).** If the app still reports an expired session — a renewal failed, or never happened —
the host mints a whole activation URL and resets the iframe `src`: a reload, coarse but dependable, re-entering at
the app-reported current path. One attempt per expiry; the next is allowed only after the app reports an active
session again, so a session that cannot recover does not reload in a loop.

**The standalone lane.** No host exists, but a standalone tab can do what an iframe cannot: navigate top-level. The
module's renew route authenticates the editor's session (a top-level navigation is the one request shape from
another site that still carries Drupal's `SameSite=Lax` session cookie), mints a preview URL for the given path, and
redirects straight back into the app. Wired as a "Renew session" link in the app's expired banner — deliberately
*not* automatic: an auto-redirect with a dead Drupal session would land the tab on a Drupal 403 page, while a link
the editor clicks keeps the failure legible. The renew route carries no CSRF token (it is reached by a cross-site
link), but its redirect destination is the *configured* frontend URL and the path must be relative, so a forged link
cannot steer the editor anywhere the editor frame itself would not.

The link's address travels as a **signed `renewUrl` claim** in the assertion: assertions are minted during a browser
request, whose scheme and host are by definition the origin the editor's browser reaches Drupal on — even in
multi-origin topologies where the app's server-side calls use a different one. The app therefore never configures a
browser-facing Drupal URL.

**Banner ownership moves with the chrome.** Embedded, the app suppresses its banner and reports status upward; the
host owns the visual context and renders session state itself. One deliberate exception: the *expired* banner still
renders even embedded, as the last-resort fallback for a host that does not speak the protocol — expiry going
invisible inside an iframe is the problem that motivated this decision.

## Alternatives considered

- **Refresh tokens** — the standard approach. league/oauth2-server issues them with a flag, Simple OAuth supports the
  grant, the token would travel in the same `httpOnly` cookie, and they are transport-independent: renewal would work
  identically embedded and standalone, with no host protocol and no redirect. Rejected because they cost the design's
  best property: every credential in this system traces to a moment of authenticated Drupal presence, and a refresh
  token renews *on its own authority* — a preview would keep renewing after the editor logged out. They also add a
  longer-lived stealable credential plus rotation state, and they solve nothing about banner invisibility, which
  needs a host channel anyway — at which point the channel might as well carry renewal too.
- **The app fetches a mint endpoint itself, with credentials.** Impossible in either context, and recorded so nobody
  re-derives it: Drupal's session cookie is `SameSite=Lax`; a fetch from the embedded app is cross-site by ancestor
  chain, so the cookie is never sent along — and iframe *navigations* do not get Lax cookies either, only top-level
  navigations do. Standalone, the same fetch is a plain third-party-cookie request.
- **A second configured browser-facing Drupal base URL** for the standalone renew link. Built, working, and
  discarded on review: two base URLs for one site is confusing configuration of exactly the kind this design exists
  to eliminate, and the fact being configured is one Drupal already knows about itself — hence the `renewUrl` claim.
- **Automatic standalone renewal** (redirect without a click). Rejected: with a dead Drupal session it teleports the
  tab onto a 403 page; the explicit link keeps the failure legible.
- **Accepting expiry as the end of the session** (degrade loudly, editor walks back to Drupal). The original stance,
  workable standalone; unacceptable once the embedded, chrome-less editor frame became the primary surface, and a
  manual round trip for something the system can do itself.

## Consequences

- The Drupal session is the revocation boundary: logout, a blocked account, or a revoked permission ends renewals at
  the next expiry, because every renewal is a full re-validation. Nothing but that session can mint — the minting
  routes refuse every authentication method except the session cookie, so a preview token cannot renew itself.
- There is no refresh-token state — nothing longer-lived than the 15-minute access token exists to steal or rotate.
- TTLs can stay short without ever becoming user-visible: embedded sessions renew in place automatically; standalone
  renewal is one click through Drupal and back.
- Every embedding surface must implement the host side of the protocol; it is small, dependency-free, and shipped as
  a reusable package (`@drupal-canvas/headless-host`).
- An XSS in the frontend app cannot exfiltrate a bearer token through the renewal relay: the assertion it could
  intercept is unredeemable without the server-held PKCE verifier. Apps that skip the PKCE registration simply lose
  the in-place renewal lane (their renewals are refused) and fall back to the recovery lane's full reloads.
- A reload of the app re-derives its renewal schedule from the cookie and never contacts the token endpoint; a reload that
  re-hits an already-redeemed activation URL finds the live cookie session and redirects into it. The one reload that
  mints is the editor frame itself, which activates a new session — the superseded token simply ages out within its
  TTL; nothing revokes it, by design.
- A logged-out editor's standalone renewal lands on Drupal's 403 page; sending them through the login form with a
  `destination` back into the preview is an unbuilt nicety.
