# 14. Headless draft preview: user-bound tokens through the OAuth 2.0 JWT assertion grant

Date: 2026-07-07

Issue: <https://git.drupalcode.org/project/canvas/-/work_items/3591775>

## Status

Accepted

## Context

The `canvas_headless` module embeds a decoupled frontend app in the Canvas editor frame. For the embedded app to be a
useful preview, it must render **draft content** — unpublished entities, forward revisions, and eventually Canvas
auto-save state — that only the editing user is allowed to see. The app fetches content from Drupal over HTTP:
JSON:API, plus an endpoint that resolves arbitrary Drupal paths to renderable component data. The example app
currently uses the Lupus Decoupled CE API for the latter — a `/ce-api/{path}` endpoint that marks the request for the
Lupus Custom Elements Renderer — but as a reference: the eventual integration will adapt the relevant parts under an
endpoint of Canvas's own, or contribute to the module directly. The auth question is the same
for any such endpoint, because Simple OAuth's authentication provider is global — every mention of the CE API in this
ADR series stands for "the rendered-routes endpoint, whatever it ends up being". So the question is one of
authentication and authorization: what credential lets the app read drafts, how is that credential scoped, and what
does a site have to configure to make it work.

The reference model is [Next.js for Drupal](https://next-drupal.org/) and the `next` module's security patch that
introduced signed, short-lived preview URLs
([commit `64c859dd`](https://git.drupalcode.org/project/next/-/commit/64c859dde2438c8dd2699f7fe4a9f8ee706e929c)):
a signed URL initiated from an authenticated Drupal session gates
draft-mode activation, and content fetching uses the app's own **client-credentials** token, narrowed to the
initiating user's access level via roles-as-scopes. That narrowing mechanism no longer exists: Simple OAuth 6.x
replaced roles-as-scopes with explicit `oauth2_scope` config entities and an **intersection access policy** — a token
bound to a user gets the user's permissions ∩ the scopes' permissions (scopes narrow, never grant), while a machine
token (client credentials, no bound user) gets its scopes' permissions directly.

Requirements for the Canvas integration:

- **Zero site configuration.** No scope entities, roles, service accounts, or secrets to set up per site.
- **No secrets in the frontend.** The app should hold nothing whose leakage matters.
- **Per-editor attribution.** Preview requests should execute as, and be attributable to, the initiating editor.
- **An extensible permission ceiling.** Site and contrib modules (Canvas included) must be able to expose their own
  view permissions to previews without changes to this module.
- **Both embedded and standalone operation**, across browsers.

## Decision

The preview URL carries one query parameter: a signed JWT **assertion**
([RFC 7521](https://datatracker.ietf.org/doc/html/rfc7521) framework,
[RFC 7523](https://datatracker.ietf.org/doc/html/rfc7523) JWT profile). It asserts "this editor, authenticated in
Drupal, initiated a preview of this path moments ago" — and it is the *authorization grant itself*: the app exchanges
it at Drupal's standard token endpoint (`grant_type` `urn:ietf:params:oauth:grant-type:jwt-bearer`, the registered URI
from RFC 7523 §2.1) for an access token **bound to that editor**. Drupal is issuer and authorization server in one —
a self-issued assertion.

Minting: only for users holding the `access canvas headless preview` permission, signed RS256 with Simple OAuth's
existing keypair (no new key management), expiring in 60 seconds, single-use. The claims, each a binding decision:

- **`typ: canvas-headless-preview-assertion+jwt`** (header) — explicit token typing per
  [RFC 8725 §3.11](https://datatracker.ietf.org/doc/html/rfc8725#section-3.11), so an access token can never be
  replayed as an assertion or vice versa, even though both are signed with the same keypair.
- **`iss` = the site UUID** — installation binding. Not a URL: multi-origin topologies (a browser reaches Drupal on
  one URL, the app's server on another) make absolute-URL claims mint/redeem-asymmetric, and a configured URL would
  live in synced config, which every environment shares. The site UUID is unique per installation and
  origin-independent, so an assertion cannot cross-redeem between separately installed sites even when they share a
  signing keypair. It does not fence clones of one installation — see Consequences.
- **`aud` = the token endpoint path** — purpose binding: only the token endpoint should accept this JWT.
- **`sub` = the editor's uid** — identity binding: the grant issues the access token *for this user*.
- **`azp` = the module's provisioned consumer** — client binding, borrowed from OpenID Connect: only that client may
  redeem the assertion.
- **`jti`** = a fresh UUID — single-use enforcement, the replay defense RFC 7523 §3 itself suggests. Redemption
  consumes the `jti` under a lock keyed on it, because "check, then mark used" is a race two parallel redemptions can
  interleave, and Simple OAuth's token controller only serializes byte-identical request bodies.
- **`use`** = `activation` or `renewal` — which lane minted the assertion. Renewal-lane assertions are relayed through
  the embedded app's script context, so their redemption additionally demands proof of the running app session; see
  [ADR-0015](0015-headless-draft-preview-session-renewal-re-anchored-in-drupal-session.md).
- **`path`, `resourceVersion`** (and `renewUrl`, see [ADR-0015](0015-headless-draft-preview-session-renewal-re-anchored-in-drupal-session.md))
  — private claims carrying the **entity-less session contract**: a validated entry path and the session-wide JSON:API
  revision policy (`rel:working-copy`). Drupal states *where the session enters*, not *what that means*; access
  control lives entirely in the token. The app decodes these claims only after the token endpoint accepted that exact
  assertion string — acceptance is the verification.

Redemption re-validates everything: signature, `typ`, `iss`, `aud`, time claims (30-second leeway), a hard cap
refusing any assertion whose lifetime exceeds five minutes regardless of the issuing side's configuration, `azp`
against the requesting client, the PKCE session proof a `use: renewal` assertion requires (ADR-0015), `jti` freshness,
and that `sub` loads as an existing, active user who *still* holds the preview permission — minting checks the
permission, redemption re-checks it, so revocation is immediate. Token issuance is otherwise stock Simple OAuth —
token entities and revocation are unchanged — with one exception: the grant clamps the issued token's lifetime to 15
minutes. Simple OAuth normally takes the token lifetime from the consumer entity's `access_token_expiration` field,
which a site administrator can raise to any value; the clamp keeps the 15-minute bound in the grant's hands so no
consumer configuration can extend it.

The scope system becomes a computed ceiling instead of per-site configuration. The module ships one code-installed
scope whose granularity plugin computes its permission set at runtime from
`hook_canvas_headless_safe_permissions()`: each module declares which of its permissions are safe to expose in a
read-only preview. Under the intersection policy the token then resolves to *the editor's own permissions, capped to
view-only* — write permissions the editor holds never reach the token. A hook rather than a hardcoded list is forced
by two facts: Drupal permissions carry no read/write metadata, so someone's judgment must define "view-only", and the
intersection silently strips permissions the ceiling does not know about, so that judgment must live with the module
that defines each permission.

Canvas pages themselves expose the one case a view-only list cannot express: their access handler derives view access
to *unpublished* pages from the create, edit, and delete permissions — there is no view-only permission to declare.
The baseline declares those three permissions preview-safe, the ceiling's one deliberate exception to view-only:
without them, the preview could not render an unpublished page of the very entity type Canvas provides. The
intersection still bounds the token to permissions the editor already holds, so the exception widens what a preview
token may *do* (`canvas_page` write access checks pass), never *who* it may act as; the token's 15-minute lifetime
and `httpOnly` cookie transport ([ADR-0016](0016-headless-draft-preview-embedded-draft-state-in-partitioned-cookies.md))
bound the exposure. The proper fix is a dedicated view-unpublished permission on `canvas_page` in the main Canvas
module — outside this module's scope. Once that permission exists, the baseline declares it instead and the write
permissions leave the ceiling.

The consumer is a **public client** (RFC 6749 §2.1), auto-provisioned at install, with no secret: RFC 7523 permits
skipping client authentication because the signed assertion is the credential. Total site configuration for the whole
auth model: the frontend URL.

## Alternatives considered

- **Porting the reference trust model as-is** (client-credentials fetching narrowed by roles-as-scopes). Impossible:
  Simple OAuth 6.x removed roles-as-scopes.
- **Reconstructing the entitlement under Simple OAuth 6.x**: one scope entity with role granularity, a dedicated role
  bundling the read permissions draft access needs, a service account as the consumer's acting identity, a client
  secret, and a permission-wise entitlement check offering the scope only to users who personally hold every bundled
  permission. Built and verified working — and rejected on inspection: it was configuration-heavy boilerplate every
  adopting site would re-derive; the token was a service user with a fixed permission set identical for every editor,
  so requests were not editor-attributable; entitlement was all-or-nothing (one missing permission, no preview); and
  any curated permission bundle silently strips permissions it does not know about, in direct conflict with
  extensibility.
- **The authorization-code grant.** Redirects and consent screens add nothing when the editor is already
  authenticated in Drupal and Drupal itself vouches for them. It remains relevant only if per-editor *consent*
  semantics are ever required.
- **Hand-rolled HMAC preview secrets** (the reference patch's scheme). Even hardened — JSON-encoded ordered signature
  input, constant-time comparison — it reimplements what JWT libraries already provide, its secrets were replayable
  within their window, and its URLs carried five coupled tamperable parameters where the assertion is one opaque one.
- **Absolute-URL `iss` or `aud` as an environment fence.** This would backfire: URL values would live in synced
  config, which every environment imports, so a staging site importing production config would mint assertions
  addressed to production — the fence would itself create the cross-environment mixup it is meant to prevent.
  Per-environment signing keypairs are the environment boundary.
- **Entity descriptors or an opaque signed "context" slot in the session contract.** A page builder previews a
  composition, not one entity, and the entity fields played no part in access control. A speculative
  extension slot in a signed contract is structure without a requirement; when a concrete need appears, a claim is
  added against it (as `renewUrl` later was).

## Consequences

- Preview works out of the box on any site, with the permissions its users already have. The guarantee "a preview
  token can never exceed the initiating user's access" holds *by identity* instead of by an entitlement check.
- Preview requests are editor-attributable by construction, and revocation takes effect at the next redemption or
  renewal: a blocked account or removed permission fails re-validation, and logging out of Drupal ends the session
  that renewals mint from.
- The view-only ceiling is only as complete as its hook declarations: an undeclared view permission means a preview
  shows too little, never too much, and the fix is a one-line hook implementation in the module that owns the
  permission.
- The ceiling's `canvas_page` exception means a preview token can pass `canvas_page` write access checks its editor
  could pass anyway. Any write surface reachable with a bearer token (for example JSON:API on sites that disable
  read-only mode) is exercisable for the token's 15-minute life; the `httpOnly` cookie transport keeps the token
  away from page scripts. The exception lasts until Canvas provides a dedicated view-unpublished permission for
  `canvas_page` — a fix outside this module's scope.
- **Distinct Simple OAuth keypairs per environment are required operational hardening.** The `iss` check
  distinguishes installations, not clones of one installation: standard multi-environment workflows preserve the site
  UUID (config sync refuses imports between sites whose UUIDs differ), and hosting-platform clones copy the keypair.
  With distinct keys, cross-environment redemption fails at the signature check.
- The assertion travels as a URL query parameter, so it lands in browser history and server logs; single-use
  redemption makes a logged assertion worthless the moment the preview loads, and the 60-second expiry bounds the
  window.
- Within the view-only ceiling, the token still reads everything the editor may read for its 15-minute life,
  mitigated by short TTLs and the token living in an `httpOnly` partitioned cookie
  ([ADR-0016](0016-headless-draft-preview-embedded-draft-state-in-partitioned-cookies.md)).
- Because no client secret exists, the grant is usable by purely client-side frontends (SPAs) with no backend; the
  tradeoffs shift (token in JavaScript-reachable memory, CORS on the token endpoint) but the grant needs no changes.
- The custom surface is deliberately thin — orchestration and policy around lcobucci/jwt and league/oauth2-server via
  Simple OAuth's grant plugin system — and its natural upstream home, if ever pursued, is Simple OAuth itself.
