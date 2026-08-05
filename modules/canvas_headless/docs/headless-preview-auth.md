# Understanding the headless preview auth: OAuth 2.0, JWTs, and RFC 7523

This document teaches the concepts behind the `canvas_headless` module's
authentication design deeply enough to own it: to debug it when a token is
refused, and to build on it. It assumes you have *used* OAuth (configured
consumers, called APIs with bearer tokens) but never studied it, and that
JWTs and their claim vocabulary — `iss`, `sub`, `aud`, `azp`, `jti` — are
new.

Part 1 builds the OAuth mental model, Part 2 dissects the JWT format,
Part 3 follows one preview end to end through the actual code, Part 4
maps the implementation onto the RFCs clause by clause, and Part 5 is the
reference you'll come back to.

The decisions themselves — and the alternatives that were considered and
rejected on the way to them — are recorded in three ADRs:
[ADR-0014](../../../docs/adr/0014-headless-draft-preview-user-bound-tokens-via-jwt-assertion-grant.md)
(user-bound tokens through the assertion grant),
[ADR-0015](../../../docs/adr/0015-headless-draft-preview-session-renewal-re-anchored-in-drupal-session.md)
(session renewal), and
[ADR-0016](../../../docs/adr/0016-headless-draft-preview-embedded-draft-state-in-partitioned-cookies.md)
(embedded cookie transport). This document references them where the
teaching touches a decision.

---

## Part 1 — The OAuth 2.0 mental model

### 1.1 Four roles

[RFC 6749](https://datatracker.ietf.org/doc/html/rfc6749) (the OAuth 2.0
core spec) defines four roles
([§1.1](https://datatracker.ietf.org/doc/html/rfc6749#section-1.1)):

| Role | Meaning | In the headless preview |
| --- | --- | --- |
| **Resource owner** | The party — usually a person — whose data is being accessed | The **editor** (their content access is what the preview borrows) |
| **Client** | The application that wants to access the data | The **frontend app** (the example app is Next.js) |
| **Authorization server** (AS) | Issues access tokens after deciding a request is legitimate | **Drupal** (Simple OAuth's `/oauth/token`) |
| **Resource server** (RS) | Holds the data; accepts access tokens | **Drupal again** (JSON:API and the rendered-content API) |

(Terminology warning for Drupal readers: the RFC's own wording for the
resource owner is "an entity capable of granting access to a protected
resource" — "entity" there just means *a party*, and has nothing to do
with Drupal entities.)

Drupal playing both server roles is normal for CMS setups and is why one
bearer token works against both APIs: `simple_oauth` registers a global
authentication provider, so *any* Drupal route that allows OAuth
authentication understands the tokens Drupal itself issued.

### 1.2 The core loop, and what "Bearer" means

Everything in OAuth is elaboration on one loop: **the client obtains an
access token from the authorization server, then presents it to the
resource server on every request.**

The token type we use — the only widely deployed one — is **Bearer**
([RFC 6750](https://datatracker.ietf.org/doc/html/rfc6750)):

```
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiIs...
```

"Bearer" is meant literally, as in "bearer bonds": *whoever bears the
token has the power*. The RS does not ask who is presenting it. This
single fact drives half of the design decisions — short TTLs, single-use
assertions, the token living only in an `httpOnly` cookie — because a
leaked bearer token **is** the access, until it expires.

### 1.3 Grants: the ways of obtaining tokens

A **grant** is a standardized procedure for convincing the authorization
server to issue a token. RFC 6749 ships four, and each answers a
different trust question:

- **`authorization_code`**
  ([§4.1](https://datatracker.ietf.org/doc/html/rfc6749#section-4.1)) —
  "a user is present in a browser and consents": redirect to the AS, log
  in, approve, get redirected back with a code, exchange the code for a
  token. The full ceremony. It was evaluated and rejected early (see
  ADR-0014's alternatives): redirects and consent screens add nothing
  when the editor is already authenticated in Drupal and Drupal itself
  vouches for them.
- **`client_credentials`**
  ([§4.4](https://datatracker.ietf.org/doc/html/rfc6749#section-4.4)) —
  "no user at all, the client acts as itself": the client authenticates
  with its id + secret and gets a token representing *the application*.
  This is what the rejected first design used (ADR-0014, alternatives).
  One Simple OAuth 6 detail is easy to misread here: the module requires
  a fallback account on any consumer using this grant — but that account
  is the token's *acting identity* (whom actions are attributed to when
  the CMS needs a uid), **not** its authorization. (Where a machine
  token's authorization *does* come from is covered in 1.5.) The deeper
  problem: the token represented a machine standing in for an editor,
  and ADR-0014 records why that was the wrong somebody.
- **`refresh_token`**
  ([§6](https://datatracker.ietf.org/doc/html/rfc6749#section-6)) —
  exchange a long-lived refresh token for a fresh access token. This
  design deliberately issues none, and the reason is worth
  internalizing: sessions renew by minting and redeeming a *new
  assertion* from the editor's live Drupal session (ADR-0015), so every
  renewal re-proves the session, the permission, and the account's
  status — the Drupal session stays the revocation boundary, and logging
  out ends renewals. A refresh token renews on its own authority and
  would sever exactly that.
- **Extension grants**
  ([§4.5](https://datatracker.ietf.org/doc/html/rfc6749#section-4.5)) —
  the spec's own escape hatch: "the client uses an extension grant type
  by specifying the grant type using an absolute URI ... and by adding
  any additional parameters necessary." This is the door the design
  walks through. Extension grants are not a workaround; they are a
  first-class mechanism, and several later standards (device grant,
  token exchange, JWT bearer) began life as one.

### 1.4 Clients: confidential vs public

RFC 6749 [§2.1](https://datatracker.ietf.org/doc/html/rfc6749#section-2.1)
splits clients by one question: *can this application keep a secret?*

- A **confidential** client (server-side app with protected config) gets
  a `client_secret` and must authenticate with it at the token endpoint.
- A **public** client (SPA, mobile app — or any client that simply
  doesn't need a secret) has only a `client_id`, which is an identifier,
  not a credential.

The module's consumer is **public**, and not because an app server
couldn't keep a secret — it could — but because the secret would be
*redundant*: every token request already carries a cryptographically
signed, single-use, 60-second **assertion** minted by Drupal — a signed
statement in which Drupal asserts facts about the request: *this editor,
authenticated here, initiated a preview of this path moments ago* (Part
2 dissects one). That is a far stronger credential than a static secret.
Removing it also removed an entire category of operational burden
(distribution, rotation, leakage) — the rejected client-credentials
design's secret and its "rotate this in production" footnote are simply
gone.

A consequence worth pausing on: because no secret is needed, this grant
is usable by **purely client-side apps** (SPAs) too — a browser app
could redeem the assertion at `/oauth/token` itself, no backend
required. Read that as a property of the grant, not as a supported
integration path: any SDK built on this design starts and carries draft
mode through `httpOnly` session cookies, which only an app server can
set, so a purely client-side setup would be on its own rather than
something the SDK supports first-class. The tradeoffs also shift
rather than disappear: the token then
lives in JavaScript-reachable memory (XSS becomes the threat to design
against, where this design keeps the token in an httpOnly cookie that
page JavaScript can never read), Drupal must send CORS headers for the
app's origin, and the SPA manages its own draft state instead of the
framework's draft mode — making it essentially the "cookieless draft
transport" alternative of ADR-0016, now with a concrete auth story. The
grant itself needs no changes for any of this.

### 1.5 Scopes, and Simple OAuth 6's intersection model

A **scope** ([RFC 6749 §3.3](https://datatracker.ietf.org/doc/html/rfc6749#section-3.3))
is a label attached to a token narrowing what it may do — "read-only
mail", "post to my timeline". The spec deliberately says almost nothing
about what scope values *mean*: that is left to each authorization
server — the spec role, not the Simple OAuth module specifically. Every
OAuth provider invents its own scope vocabulary (Google's scopes are
URLs, GitHub's are strings like `repo:status`); what they share is only
the mechanic of requesting and honoring the labels.

Simple OAuth 6.x, as *our* authorization server, gives scopes a precise
Drupal meaning. Everything up to the next heading-sized break is
**Simple OAuth's own behavior** — it applies to every token on the
site, whether or not the `canvas_headless` module exists. All of it is
new in 6.x (5.x had none of it), built on the Access Policy API Drupal
core added in 10.3. Verify rather than trust:

- A scope is a config entity whose **granularity plugin** resolves it
  to a set of permissions. Simple OAuth ships two granularities —
  `src/Plugin/ScopeGranularity/Permission.php` (exactly one permission)
  and `Role.php` (a role's whole permission set); granularities are
  plugins, which is the extension point the module uses.
- Simple OAuth registers an access policy with core
  (`simple_oauth.services.yml`, service `access_policy.simple_oauth`,
  tag `access_policy`) whose class is
  `src/Access/Oauth2AccessPolicy.php`. Its `alterPermissions()` applies
  the scopes' permissions in one of two modes — a single ternary on
  whether the token has a bound user (`auth_user_id`):
  - **Token bound to a user**: effective permissions = the user's own
    permissions ∩ the union of the token's scope permissions. Scopes
    **narrow; they never grant**.
  - **Machine token** (client_credentials — no bound user): the scopes'
    permissions apply *directly*. There is no user to intersect with;
    the scope **is** the authorization. (This is the mode the rejected
    client-credentials design lived in, and where the answer promised
    in 1.3 lives: a machine token's authorization is its scopes.)

Where does the `canvas_headless` module enter? Twice, both times as
input to those Simple OAuth rules, never replacing them:

- **It issues user-bound tokens.** The jwt-bearer grant binds every
  preview token to the initiating editor — the design choice that
  selects the intersection mode for previews.
- **It ships the one scope those tokens carry.** A token with *no*
  scopes has *no* permissions (an intersection with the empty set), so
  the module installs the `canvas_headless` scope with a custom
  granularity plugin that computes the view-only ceiling — assembled
  from `hook_canvas_headless_safe_permissions()` declarations, because
  any *hardcoded* ceiling would silently strip permissions it doesn't
  know about (ADR-0014).

---

## Part 2 — JWT anatomy

### 2.1 Three parts, two dots

A **JSON Web Token** ([RFC 7519](https://datatracker.ietf.org/doc/html/rfc7519))
is three [base64url](https://datatracker.ietf.org/doc/html/rfc4648#section-5)-encoded
segments joined by dots:

```
eyJ0eXAiOiJjYW52YXMtaGVhZGxlc3MtcHJldmlldy1hc3NlcnRpb24rand0Iiw... . eyJpYXQiOjE3ODMx... . JaOTbzWU9A4Jc6...
└── header ──────────────────────────────────────────────────────┘ └── payload ────┘ └── signature ──┘
```

Decode the first two segments of a real preview assertion and you get:

```json
// Header
{
  "typ": "canvas-headless-preview-assertion+jwt",
  "alg": "RS256"
}

// Payload (the "claim set")
{
  "iat": 1783183922,
  "nbf": 1783183922,
  "exp": 1783183982,
  "iss": "6f4d4c1e-…-site-uuid-…",
  "aud": "/oauth/token",
  "sub": "2",
  "jti": "e73594a4-24fa-4bd3-b717-4621bb045023",
  "azp": "canvas_headless",
  "use": "activation",
  "path": "/node/3",
  "resourceVersion": "rel:working-copy",
  "renewUrl": "https://drupal.example.com/canvas-headless/renew"
}
```

(About that `path` claim: it records where the preview was initiated and
becomes the redirect target after activation — nothing more. It grants
nothing and restricts nothing; the editor navigates the frontend freely
afterwards, because access control lives entirely in the token. Part 3.1
returns to it.)

Two facts about this format matter more than everything else:

1. **A JWT is signed, not encrypted.** Anyone holding the string can
   base64-decode the payload and read every claim — no key needed. The
   signature proves *who wrote it* and *that it wasn't altered*, nothing
   more. This is why the SDK can safely decode `path` and
   `resourceVersion` out of the assertion
   (`packages/headless/src/assertion.ts`)
   — and also why no secret must ever be put in a claim.
2. **The signature covers header + payload exactly as encoded.** Change
   one character of either and verification fails. The tamper refusal
   ("Token signature mismatch") is this property doing its job.

### 2.2 The signature: RS256

`alg: RS256` names the signature algorithm: an RSA signature over a
SHA-256 hash of the token. (The full name you'll see in references,
RSASSA-PKCS1-v1_5, merely identifies *which* standardized RSA signing
construction — the oldest and most universally supported one; a newer
variant, RSA-PSS, appears in JWTs as the `PS*` algorithms.) What matters
day to day is that RS256 is **asymmetric**: the *private* key signs, the
*public* key verifies, so a verifying party never holds signing power.

Simple OAuth already maintains an RSA keypair for its access tokens, so
assertions reuse it — zero new key management — and all signing and
verification mechanics come from
[lcobucci/jwt](https://lcobucci-jwt.readthedocs.io/), the library Simple
OAuth itself uses.

### 2.3 The registered claims

RFC 7519 [§4.1](https://datatracker.ietf.org/doc/html/rfc7519#section-4.1)
defines seven **registered claims**. None are mandatory in general; each
*profile* (like RFC 7523) decides which it requires. Generic meanings:

| Claim | Name | Generic meaning |
| --- | --- | --- |
| `iss` | Issuer | Who created and signed this token |
| `sub` | Subject | Whom/what the token is *about* |
| `aud` | Audience | Who is meant to *accept* it — anyone else must reject it |
| `exp` | Expiration time | Reject after this moment (Unix timestamp) |
| `nbf` | Not before | Reject before this moment |
| `iat` | Issued at | When it was created |
| `jti` | JWT ID | Unique identifier for *this individual token* |

The last four rows are timestamps and a serial number. The first three
are the abstract ones, and one analogy carries all of them: a passport.
`iss` is the issuing country — whose seal is on the document, and whose
authority you must decide to trust before anything else matters. `sub`
is the person the passport is *about* — the photo page. `aud` is who is
meant to *accept* it — a border checkpoint turns away documents
addressed to someone else, however valid (your gym card also proves
who you are; the border's answer is still "not for us"). In our
assertion: issued by *this Drupal site* (`iss` = the site UUID), about
*editor #2* (`sub`), acceptable only to *the token endpoint*
(`aud` = `/oauth/token`). Most of the validation failures in Part 3 are
one of these three questions answered "no".

Names not in this list are either **public claims** registered with IANA
(our `azp` is one, borrowed from OpenID Connect — see 3.2) or **private
claims** agreed between issuer and consumer (our `use`, `path`,
`resourceVersion`, and `renewUrl`). The
[IANA JWT claims registry](https://www.iana.org/assignments/jwt/jwt.xhtml)
is the authoritative list.

### 2.4 `typ`: labeling token species

The system mints two different *kinds* of JWT, and both come from the
same keypair:

| Kind | Job | Where it travels |
| --- | --- | --- |
| **Preview assertion** | Proof that an editor initiated a preview; redeemable once at `/oauth/token` for an access token | Inside the preview URL |
| **Access token** | The API credential draft fetches present to JSON:API and the rendered-content API | The `Authorization: Bearer` header |

Both are valid RS256 JWTs signed by the same private key — so signature
verification alone cannot tell them apart. To be precise about what the
attack is, because a natural objection stops most people here: *"the
payloads differ — and tampering with a payload breaks the signature, so
how could one ever pass as the other?"* Correct on both counts, and
neither is the attack. Nothing gets tampered with: the attacker takes a
**whole, intact, correctly signed** JWT of one kind and presents it in
the slot expecting the other kind — say, POSTing a captured access
token (15-minute lifetime, travels on every API request) to
`/oauth/token` *as if it were an assertion*, hoping to trade a
soon-to-expire credential for a fresh one. The signature check passes,
because the signature is real. Everything that protects you after that
is the verifier's *claim* checks happening to reject a payload that was
never designed for them. In this system they would, today — a real
access token carries `aud: "canvas_headless"` (the client id), so the
grant's "aud must contain `/oauth/token`" check refuses it — but
"happens to fail" is a coincidence of claim shapes, and claim shapes
drift as systems evolve. Attacks in this family are called **token
confusion** (or cross-JWT confusion): making a verifier accept a
valid JWT that was minted for a different purpose.

The `typ` header turns that coincidence into a rule. Assertions carry
`typ: canvas-headless-preview-assertion+jwt`, and the grant refuses any
other value before even reaching signature checks; access tokens carry
Simple OAuth's own type and are never accepted by the grant. Giving
every JWT species an explicit label like this is a formal recommendation
of the JWT Best Current Practices document,
[RFC 8725 §3.11](https://datatracker.ietf.org/doc/html/rfc8725#section-3.11)
— and RFC 8725 as a whole is a catalogue of every known way JWTs get
misused, worth a full read once Part 3 has landed.

---

## Part 3 — One preview, end to end

Now the walkthrough. Every step names the file that implements it.

### 3.1 Minting: the editor opens an entity in the Canvas editor

When the editor frame embeds the app, the Canvas UI requests an
assertion from the module's minting endpoint — `POST
/canvas-headless/assertion`, session-authenticated and CSRF-protected
via the `X-CSRF-Token` header
(`modules/canvas_headless/src/Controller/AssertionController.php`). The
controller resolves the edited entity's canonical path (access-checked)
and delegates to `PreviewUrlGenerator::issueForPath()`, which checks the
`access canvas headless preview` permission and calls
`PreviewAssertionFactory::issue()`
(`modules/canvas_headless/src/PreviewAssertionFactory.php`), which
builds the JWT claim by claim. Each choice is a decision:

- **`typ: canvas-headless-preview-assertion+jwt`** — species separation
  (2.4 above).
- **`iss` = the site UUID** (`system.site` config). The obvious choice —
  the site's URL — fails twice here: local topologies are often
  multi-origin (browsers reach Drupal over DDEV's https URL, the app's
  Node.js server over its http URL), so a URL derived from the request at
  mint time wouldn't match one derived at validation time; and a
  *configured* URL would live in synced config, which every environment
  shares. The site UUID is unique per *installation* and
  origin-independent, so validating it strictly fences separately
  installed sites that ended up sharing a keypair (keys committed to a
  repo and reused across projects — one `.gitignore` mistake away for
  any project that generates them into its tree). Know exactly what it
  does *not* fence: clones of one installation. Cloned environments keep
  the site UUID — config sync refuses to import between sites whose
  UUIDs differ, so the standard dev/staging/prod workflow preserves it
  by construction — and platform clones copy the keypair too, so a
  staging-minted assertion would redeem on production. The environment
  boundary is the keypair itself: regenerate it per environment (the
  module README's setup section records this as a required step). With
  distinct keys, cross-environment redemption dies at
  the signature check and `iss` never gets a say.
- **`aud` = `/oauth/token`** (the token endpoint as a path). The
  audience says "only the token endpoint should accept this" — so the
  same JWT can't be, say, presented as an API credential somewhere else.
  Path rather than absolute URL for the same multi-origin reason. (Don't
  reach for an absolute `aud` as an environment fence either: it would
  live in synced config, which every environment shares — ADR-0014.)
- **`sub` = the editor's uid.** The most consequential claim in the
  design: the grant issues the access token *for this user*. This is the
  entire "user-bound token" idea expressed as one claim — the preview
  sees what editor #2 may see because the token *is* editor #2, capped
  view-only.
- **`azp` = `canvas_headless`** — which client may redeem this (3.2).
- **`iat` / `nbf` / `exp`** — issued now, valid immediately, dead in
  60 seconds (`assertion_expiration` config). The assertion only needs
  to survive the gap between minting and the app's first load.
- **`jti`** = a fresh UUID — the handle for single-use enforcement (3.4).
- **`use` = `activation`** here, because this assertion travels in a URL
  and the app's server redeems it the moment it arrives. The renewal lane
  mints `use: renewal` instead, and those have to prove more at
  redemption, because they pass through script context on the way (3.7).
- **`path`, `resourceVersion`, `renewUrl`** — private claims carrying the
  draft *session contract* (ADR-0014): where the preview enters, which
  JSON:API revision policy applies, and the absolute browser-facing URL
  of the standalone renewal route (ADR-0015 — Drupal states its own
  address as the editor's browser sees it, which is exactly the request
  it is minting on, so the frontend never configures a browser-facing
  Drupal URL). Signing them means the app can trust them later without a
  second validation round trip.

The signed, serialized JWT becomes one query parameter: the Canvas
editor sets the iframe's `src` to the app's activation endpoint,
`…/api/draft?assertion=eyJ0eXAi...`. Assertions are single-use, so every
activation — including a recovery reload — gets a freshly minted one.

### 3.2 A note on `azp`: borrowed vocabulary

`azp` ("authorized party") is not from RFC 7519 or 7523 — it comes from
[OpenID Connect Core §2](https://openid.net/specs/openid-connect-core-1_0.html#IDToken),
where it names the client an ID Token was issued for, and it is in the
[IANA registry](https://www.iana.org/assignments/jwt/jwt.xhtml). We
borrow it with the same meaning: *this assertion was minted for the
`canvas_headless` consumer, and only that client may redeem it*. The
grant compares the claim against the identity of the client actually
making the token request. Without it, any *other* consumer on the site
that ever got the `jwt_bearer` grant enabled could redeem preview
assertions minted for the app.

### 3.3 The exchange: one POST, no secrets

The app's `/api/draft` route
(`packages/headless/src/server/flows.ts`,
`enableDraftMode()`) sends the whole credential story in one
form-encoded request — the shape prescribed by
[RFC 7523 §2.1](https://datatracker.ietf.org/doc/html/rfc7523#section-2.1):

```
POST /oauth/token
Content-Type: application/x-www-form-urlencoded

grant_type=urn:ietf:params:oauth:grant-type:jwt-bearer
&assertion=eyJ0eXAiOiJjYW52YXMtaGVhZGxlc3MtcHJldmlldy1hc3NlcnRpb24rand0...
&client_id=canvas_headless
&code_challenge=E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM
&code_challenge_method=S256
```

That `grant_type` URN is *registered* — RFC 7523 §2.1 defines it — which
is what makes this "an implementation of a standard grant" rather than
"a custom endpoint that resembles OAuth". Notice what is absent: any
`client_secret`. The assertion is the credential (1.4 above).

The `code_challenge` pair borrows the vocabulary of Proof Key for Code
Exchange (PKCE;
[RFC 7636](https://datatracker.ietf.org/doc/html/rfc7636)) for session
continuity: the app server registers the S256 hash of a verifier it
keeps to itself, and the *next* renewal exchange must present that
verifier (`code_verifier`) or Drupal refuses it. Why renewals need
proving — and why activations do not — is 3.7's story.

On success, the response is a plain RFC 6749 token response
(`access_token`, `token_type: Bearer`, `expires_in: 900`). On failure,
a standard OAuth error body — which is why the SDK can surface
meaningful messages ("The assertion has already been used") without any
custom error protocol.

### 3.4 Validation: fifteen questions, in order

`PreviewAssertionGrant::respondToAccessTokenRequest()`
(`modules/canvas_headless/src/Grant/PreviewAssertionGrant.php`)
answers a chain of questions, each one closing a specific attack. This
is the code to internalize — everything else is plumbing around it.

| # | Check | Refuses |
| --- | --- | --- |
| 1 | Client exists, may use this grant (league core) | Unknown clients; consumers without `jwt_bearer` enabled |
| 2 | `assertion` parameter present, parses as a JWT | Garbage |
| 3 | `typ` header is `canvas-headless-preview-assertion+jwt` | Access tokens (or any other JWT species) replayed as assertions |
| 4 | Signature verifies against Simple OAuth's public key (`SignedWith`) | Forgeries and *any* modified claim |
| 5 | Time claims hold, 30 s leeway (`LooseValidAt`) | Expired or not-yet-valid assertions; tolerant of clock skew |
| 6 | `aud` contains `/oauth/token` (`PermittedFor`) | JWTs minted for another purpose |
| 7 | `iss` equals this site's UUID (`IssuedBy`) | Assertions from another *installation*, even one sharing this keypair (clones of this installation keep the UUID — distinct per-environment keys fence those; see 3.1) |
| 8 | Every expected claim is present (`sub`, `jti`, `iat`, `exp`, `azp`, `use`, `path`, `resourceVersion`) | Structurally incomplete assertions, no matter how correctly signed |
| 9 | Lifetime (`exp` − `iat`) is at most 5 minutes | A misconfigured issuer's oversized `assertion_expiration` becoming long-lived credentials |
| 10 | `azp` equals the requesting client's id | Redemption by a different consumer |
| 11 | A `use: renewal` assertion comes with a `code_verifier` hashing to a registered session challenge (3.7) | Scripts that intercepted the assertion from the postMessage relay — they hold no verifier |
| 12 | `jti` never seen before — under a lock (below) | Replay, including *concurrent* replay |
| 13 | `sub` loads as an existing, non-blocked user | Deleted or blocked editors |
| 14 | The `sub` user still holds `access canvas headless preview` | Editors whose preview permission was revoked between mint and redeem |
| 15 | The `canvas_headless` scope exists | Broken installs (server error, not silent power) |

Then, and only then: `issueAccessToken(ttl, client, user_id, [scope])` —
league's stock issuance, through Simple OAuth's stock storage, with one
adjustment: the TTL is clamped to 15 minutes. The consumer entity's
`access_token_expiration` field feeds it and stays editable with no
maximum, so the exposure bound is the grant's to enforce, not the
consumer's to offer. Nothing else about the token is custom.

Only after the token exists does the session challenge rotate: the proven
one is deleted and the request's `code_challenge` registered in its
place. That ordering is the point. Check 11 *verifies* the challenge but
does not spend it, so any failure between there and here — a replayed
jti, a blocked user, a disabled scope, a malformed successor challenge —
leaves the current challenge alive and the session able to renew with the
verifier it still holds. Spending it at verification time would strand a
session on any of those paths.

Two of these re-answer questions the minting side already answered, on
purpose. Check 9 re-bounds a lifetime the issuer's own configuration
controls, and check 14 re-asks a permission `PreviewUrlGenerator` checked
at mint time — because issuer and validator being the same site today is
a deployment fact, not a design guarantee, and because re-checking makes
permission revocation take effect immediately instead of within the
assertion's remaining lifetime.

Check 11 running *before* check 12 is deliberate: the app server and an
intercepting script hold the same relayed assertion, so a redemption
attempt without proof must be refused before the single-use `jti` is
consumed — otherwise the interceptor, unable to get a token, could still
burn the assertion out from under the legitimate renewal. Note that
check 11 only *verifies*; nothing is spent until the commit point below.

**The lock in check 12** deserves its own paragraph, because it is the
subtlest piece of the implementation. "Check jti, then mark jti used" is
two operations, and two parallel requests can interleave them
(check A, check B, mark A, mark B → both pass — a
[TOCTOU race](https://en.wikipedia.org/wiki/Time-of-check_to_time-of-use)).
Neither of the obvious mitigations actually holds: Drupal core's
`setWithExpireIfNotExists()` is itself check-then-set internally, and
Simple OAuth's token controller lock only serializes **byte-identical**
request bodies — vary one parameter and both requests reach the grant
concurrently. So `consumeAssertion()` wraps the check-and-mark in
Drupal's lock service, keyed on the jti; failing to *acquire* the lock is
treated as already-used, because for a single-use credential
"someone else is redeeming this right now" and "redeemed" deserve the
same answer. Under an actual parallel race — two simultaneous exchanges
of one assertion with *different* request bodies, which bypasses the
token controller's own lock — exactly one wins; the other gets "The
assertion has already been used."

**Check 11 carries the same lock, for the same reason.** A session
challenge is single-use too — it is spent and replaced on every
redemption — so "look the challenge up, then delete it" is the identical
TOCTOU shape: two concurrent renewals presenting the same `code_verifier`
would each find the challenge present, each issue a token, and each
register a successor. `verifySessionProof()` therefore takes a lock keyed
on the challenge before looking it up, treats a failure to acquire as
already-spent, and — this is the part that differs from the jti — *keeps
the lock held* through every remaining check, releasing it only after the
rotation above commits. Verification and rotation are one atomic step
against a concurrent redemption, without the check-time deletion that
would make a later failure fatal to the session.

### 3.5 What the token *is*: the intersection, one more time

The issued access token records `auth_user_id` = the editor. When it
authenticates a later API request, Simple OAuth wraps the request in a
`TokenAuthUser`, and two access policies compute what it may do:

- `DecoratedUserRolesAccessPolicy` contributes **the editor's full role
  permissions** (one side of the intersection);
- `Oauth2AccessPolicy` intersects that with **the token's scope
  permissions** — the `canvas_headless` scope, whose
  `PreviewSafePermissions` granularity plugin
  (`modules/canvas_headless/src/Plugin/ScopeGranularity/PreviewSafePermissions.php`)
  computes the view-only ceiling from
  `hook_canvas_headless_safe_permissions()`.

Effective permissions are the editor's permissions intersected with the
preview-safe set. On a typical editorial role that leaves a small group
of view permissions: permissions such as `revert all revisions`, `edit
terms in tags`, and `administer url aliases` do not reach the token.

The ceiling applies to permission checks. Drupal can also authorize
entity operations through node access grants (`hook_node_grants()`),
ownership rules, or custom `hook_entity_access()` logic. Because those
mechanisms do not necessarily evaluate a permission, the ceiling does
not further restrict them; the token follows the underlying access of
the editor account it represents. This is the intended boundary of the
permission-only model.

### 3.6 The app's side of the bargain

After a `200` from the token endpoint, `enableDraftMode()` does something
that looks like a shortcut but is a sound trust argument: it decodes
`path` and `resourceVersion` **out of the assertion it just sent**
(`decodeAssertionClaims()` — no signature check). Why is that safe?
Because Drupal just verified *that exact string* — signature, expiry,
single-use and all — and a tampered assertion would never have gotten a
token, so its claims would never be read. The trust binding is string
identity: decode only what was redeemed, only after redemption succeeds.
(The decoded values are still shape-checked — notably, the `path` must be
site-relative, no `//host` forms — as an app-side backstop for an
invariant Drupal's minting endpoints already enforce.)

The session then lives in two CHIPS cookies — and yes, that includes
the access token: it is a field inside the `canvas_headless_draft_data`
cookie, alongside the secret verifier that proves the app server is
continuing the same session at its next renewal (3.7). Three properties
make that safe enough for its 15-minute life: the cookie is `httpOnly`,
so page JavaScript can never read either secret (the token is only ever
*used* by the app's server, which unpacks the cookie and forwards the
token to Drupal as the `Authorization: Bearer` header); it is `Secure`,
so browsers normally accept and send it only over HTTPS; and it is
`Partitioned` — the CHIPS story, covered by ADR-0016 rather than here.
That combination has a development implication: the example's
`http://localhost:3000` origin works for embedded preview in
Chromium-based browsers because they treat localhost as a secure-context
exception, but non-local origins need HTTPS, as does local cross-browser
testing in Firefox and Safari. A browser that refuses the cookies cannot
keep draft mode active inside the preview iframe; ADR-0016 carries the
compatibility details.

### 3.7 Three lifetimes, one session

| Artifact | Lifetime | Why |
| --- | --- | --- |
| Assertion | 60 s, single-use | Only needs to survive minting → first load |
| Access token | 15 min | Bearer-token blast-radius control; renewed by re-assertion, never refreshed (below) |
| Draft cookies | Browser session | The actual editing session boundary |

The token, though, rarely gets to die: the app knows `tokenExpiresAt`
from its own session cookie, so a minute before expiry it has a fresh
assertion minted from the editor's live Drupal session and redeems it in
place — the same exchange as activation, at `POST /api/draft/renew`. The
interesting part is *who does the minting*, because the app cannot: its
requests to Drupal never carry the editor's `SameSite=Lax` session
cookie. Embedded, the host page (which *is* inside that session) relays
over origin-checked postMessage — the app side lives in
`packages/headless/src/client/draft-session.ts`,
the host side in `packages/headless-host/src/index.ts`, wired into the
Canvas editor by
`ui/src/features/layout/preview/useHeadlessDraftSession.ts`. Standalone,
a "Renew session" link navigates top-level through
`/canvas-headless/renew` and back — its absolute URL arriving as the
`renewUrl` claim (2.1), Drupal stating its own browser-facing address
rather than the app configuring one.

The relay has a property activation never has: the assertion passes
through the embedded page's **script context** (postMessage in, `fetch`
to `/api/draft/renew` out), where a script injected into the app — an
XSS — could intercept it. Origin checks do not help there; the hostile
script *is* the right origin. So relayed assertions are minted with
`use: renewal`, and the grant refuses to redeem those without proof
that the redeemer is the running app server: a `code_verifier` whose
S256 hash was registered as the `code_challenge` of the session's
previous exchange (3.3). The verifier lives in the `httpOnly` draft
data cookie — server-side state no page script can read — and rotates
on every redemption. An intercepted renewal assertion is therefore
worthless anywhere but back at `/api/draft/renew`, which just renews
the session normally. The bootstrap is what makes this sound:
challenges are only ever registered by redeeming an *activation*
assertion (`use: activation`), and those travel in URLs redeemed
server-side on arrival — never through script context — so a hostile
script can neither redeem what it intercepts nor register a challenge
it knows the verifier for. Activation URLs answer for themselves the
way they always have: single-use jti, 60-second life.

Renewal is strictly a
*continuation*: with no existing session to continue, `/api/draft/renew`
answers 400 (starting sessions is the preview URL's job), and it is
identity-pinned — the session records the assertion's `sub` at
activation, and a renewal naming a different editor (the browser's
Drupal session changed hands) is refused with 409. A session can change
identity only through an explicit new activation, never a silent
renewal. ADR-0015 records the full design, including why refresh tokens
were rejected (see 1.3).

When renewal *does* fail — the editor logged out of Drupal, which is the
one failure renewal must not paper over — the app **degrades loudly**:
pages fall back to what anonymous visitors can see, and the banner turns
red — "Draft preview session expired". The failure mode to avoid was
published content silently masquerading as a draft. (Embedded, the app
normally shows no banner at all — it reports status upward and the host
owns the chrome; the red state is the one banner that still renders inside
an iframe, as a last resort for hosts that don't speak the protocol.)

One more edge the route handles: suppose you close the preview tab and
your browser later restores it. The restored URL is the activation URL —
`/api/draft?assertion=…` — and its assertion was already redeemed once,
so the exchange fails. But your draft cookies still hold a live
session. Rather than stranding you on an OAuth error page,
`enableDraftMode()`'s failure branch checks for exactly this state and
redirects into the existing session.

---

## Part 4 — The RFC map: why this is idiomatic

The claim "this is standard OAuth, not a bespoke sidecar" is auditable.
Here is the audit.

**[RFC 7521](https://datatracker.ietf.org/doc/html/rfc7521)** is the
*assertion framework*: the general pattern of trading a signed statement
from a trusted issuer for a token. **[RFC 7523](https://datatracker.ietf.org/doc/html/rfc7523)**
is its JWT profile, and [§3](https://datatracker.ietf.org/doc/html/rfc7523#section-3)
is a numbered checklist of what a conforming authorization server must
process. Mapping it to the code:

| RFC 7523 §3 requirement | The implementation |
| --- | --- |
| 1. `iss` REQUIRED | Minted as the site UUID; validated with `IssuedBy` |
| 2. `sub` REQUIRED — "identifies an authorized accessor for which the access token is being requested" | The editor's uid; becomes the token's `auth_user_id` |
| 3. `aud` REQUIRED — must identify the authorization server; token endpoint URL suggested as value | Token endpoint path; validated with `PermittedFor` (path form documented as the multi-origin tradeoff) |
| 4. `exp` REQUIRED, must be validated; limited lifetime expected | 60 s; `LooseValidAt` with 30 s leeway; the grant additionally refuses any assertion whose `exp` − `iat` exceeds 5 minutes, so "limited lifetime" holds even against issuer misconfiguration |
| 5. `nbf` MAY | Set to mint time |
| 6. `iat` MAY | Set |
| 7. `jti` MAY; the AS "MAY ensure that JWTs are not replayed by maintaining the set of used jti values" | UUID per assertion; lock-serialized used-jti store — the spec's own suggested replay defense, implemented |
| 8. Other claims MAY be present | `azp`, `use`, `path`, `resourceVersion`, `renewUrl` |
| 9. Signature MUST be validated | `SignedWith` against Simple OAuth's public key |

Beyond §3:

- The **wire format** (grant_type URN + `assertion` parameter) is
  [RFC 7523 §2.1](https://datatracker.ietf.org/doc/html/rfc7523#section-2.1)
  verbatim.
- **Skipping client authentication** is sanctioned: the token request
  comes from a public client
  ([RFC 6749 §2.1](https://datatracker.ietf.org/doc/html/rfc6749#section-2.1)),
  and RFC 7523's authorization-grant usage does not require client
  authentication on top of the assertion — the assertion *is* the
  authenticated statement.
- **Explicit `typ`** is [RFC 8725 §3.11](https://datatracker.ietf.org/doc/html/rfc8725#section-3.11).
- **`azp`** is OpenID Connect vocabulary
  ([OIDC Core §2](https://openid.net/specs/openid-connect-core-1_0.html#IDToken)),
  IANA-registered, applied to the same problem it was coined for.
- The **grant registers into Simple OAuth as a plugin**
  (`modules/canvas_headless/src/Plugin/Oauth2Grant/PreviewAssertion.php`)
  and into league/oauth2-server as an `AbstractGrant` subclass — both
  frameworks' designed extension points
  ([league's docs on custom grants](https://oauth2.thephpleague.com/)).

What is *ours*, and deliberately so, is policy no spec can decide:
that `iss` means the site UUID, that assertions bind to one client, that
subjects must be active Drupal users, that the scope is a hook-computed
view-only ceiling. ADR-0014 keeps open the option of one day upstreaming
exactly this policy layer into Simple OAuth — an option, not a plan.

---

## Part 5 — Reference

### 5.1 Claims in the assertion

| Claim | Value (example) | Validated by | Attack it closes |
| --- | --- | --- | --- |
| `typ` (header) | `canvas-headless-preview-assertion+jwt` | String equality in `validateAssertion()` | Token-species confusion |
| `alg` (header) | `RS256` | `SignedWith` constraint pins the algorithm | Algorithm-substitution tricks |
| `iss` | Site UUID | `IssuedBy` | Cross-*installation* redemption under a shared keypair (clones keep the UUID; per-environment keys are the environment fence — §3.1) |
| `aud` | `/oauth/token` | `PermittedFor` | Reuse of the JWT outside the token endpoint |
| `sub` | `"2"` (editor uid) | User load + `isBlocked()` + `access canvas headless preview` re-check; pinned by the app across renewals | Tokens for deleted, blocked, or permission-revoked accounts; silent identity swaps mid-session |
| `azp` | `canvas_headless` | Equality with requesting client id | Redemption by a different consumer |
| `iat` / `nbf` / `exp` | mint / mint / +60 s | `LooseValidAt` (30 s leeway); `exp` − `iat` capped at 5 min | Stale or premature assertions; misconfigured long-lived ones |
| `jti` | UUID | Lock + expirable keyvalue store | Replay, incl. concurrent |
| `use` | `activation` \| `renewal` | Renewal demands a `code_verifier` matching the session's registered challenge (§3.7) | Redemption of assertions intercepted from the postMessage relay's script context |
| `path` | `/node/3` | Consumed by the app after redemption | (session contract, not access control) |
| `resourceVersion` | `rel:working-copy` | Same | Same |
| `renewUrl` | `https://drupal…/canvas-headless/renew` | Same (shape-checked: absolute http(s) URL) | Tampered renewal targets — moot, because a tampered assertion never redeems |

### 5.2 Terms

- **Assertion** — a signed statement from a trusted issuer, exchanged
  for a token (RFC 7521). Ours: "editor X, authenticated here, initiated
  a preview of path Y seconds ago."
- **Grant** — a standardized procedure for obtaining tokens; ours is the
  RFC 7523 JWT-bearer extension grant.
- **Bearer token** — access credential where possession equals power
  (RFC 6750).
- **Consumer** — Drupal's entity representing an OAuth client
  (`consumers` module); ours is public and auto-provisioned.
- **Public client** — a client with no secret; identified, not
  authenticated (RFC 6749 §2.1).
- **Scope / granularity** — Simple OAuth 6.x scopes are config entities
  whose permission set comes from a *granularity plugin* (permission,
  role, or custom — ours computes the view-only ceiling).
- **Intersection model** — Simple OAuth's rule: user-bound token
  permissions = user's permissions ∩ scope permissions. Scopes narrow,
  never grant.
- **Replay / TOCTOU** — reusing a captured credential / the
  check-then-act race; closed by `jti` single-use under a lock.
- **CHIPS** — [partitioned third-party cookies](https://developer.mozilla.org/en-US/docs/Web/Privacy/Guides/Privacy_sandbox/Partitioned_cookies);
  the embedded-iframe cookie story, ADR-0016.

### 5.3 The custom files

Module paths are relative to `modules/canvas_headless`.

| File | Role |
| --- | --- |
| `src/PreviewAssertionFactory.php` | Mints assertions (claims + RS256 signature) |
| `src/PreviewUrlGenerator.php` | Permission gate + wraps the assertion in the frontend URL |
| `src/FrontendUrl.php` | Canonicalizes a frontend list URL into one origin + base URL; refuses ambiguous or non-web values |
| `src/Controller/AssertionController.php` | Minting endpoints: activation/renewal assertions (JSON, CSRF header, cookie auth only) + the standalone renew redirect |
| `src/Grant/PreviewAssertionGrant.php` | The league grant: the fifteen checks, then token issuance |
| `src/Plugin/Oauth2Grant/PreviewAssertion.php` | Registers the grant with Simple OAuth; wires Drupal services |
| `src/Plugin/ScopeGranularity/PreviewSafePermissions.php` | Computes the view-only ceiling from hook declarations |
| `src/Hook/CanvasHeadlessHooks.php` | Baseline preview-safe permission declarations; injects the editor's headless settings |
| `canvas_headless.api.php` | Hook documentation for other modules |
| `canvas_headless.install` | Auto-provisions the public consumer, and removes it on uninstall only if it provisioned it |
| `packages/headless-host/src/index.ts` | Host side of the renewal/recovery protocol, as a reusable package |
| `ui/src/features/layout/preview/useHeadlessDraftSession.ts` | Wires the host protocol into the Canvas editor (CSRF, activation per edited entity) |
| `packages/headless/src/server/flows.ts` | The exchange (activation and in-place renewal), cookie handling, session recovery |
| `packages/headless/src/server/pkce.ts` | Generates and hashes the verifier binding the next renewal to the app server |
| `packages/headless/src/assertion.ts` | Post-redemption claim decoding (the string-identity trust argument) |
| `packages/headless/src/client/draft-session.ts` | App side of the protocol: renewal scheduling, status reporting (the example app's banner renders from its events) |

### 5.4 Reading list

Primary sources, in the order that builds understanding:

1. [RFC 6749 — The OAuth 2.0 Authorization Framework](https://datatracker.ietf.org/doc/html/rfc6749) — §1 (roles), §2.1 (client types), §3.3 (scope), §4.5 (extension grants)
2. [RFC 6750 — Bearer Token Usage](https://datatracker.ietf.org/doc/html/rfc6750)
3. [RFC 7519 — JSON Web Token](https://datatracker.ietf.org/doc/html/rfc7519) — §4.1 (registered claims)
4. [RFC 7521 — Assertion Framework](https://datatracker.ietf.org/doc/html/rfc7521)
5. [RFC 7523 — JWT Profile for OAuth 2.0 Authorization Grants](https://datatracker.ietf.org/doc/html/rfc7523) — §2.1 (wire format), §3 (the checklist)
6. [RFC 8725 — JWT Best Current Practices](https://datatracker.ietf.org/doc/html/rfc8725)
7. [OpenID Connect Core](https://openid.net/specs/openid-connect-core-1_0.html#IDToken) — for `azp`
8. [IANA JWT Claims Registry](https://www.iana.org/assignments/jwt/jwt.xhtml)
9. [league/oauth2-server documentation](https://oauth2.thephpleague.com/)
10. [lcobucci/jwt documentation](https://lcobucci-jwt.readthedocs.io/)
11. [Simple OAuth module](https://www.drupal.org/project/simple_oauth)
12. [oauth.net](https://oauth.net/2/) — approachable secondary material on every OAuth topic

One practical caution: never paste a *live* token or assertion into
online decoders like jwt.io — they are bearer credentials (expired ones
from local dev are fine, and decoding locally with
`base64 -d` or the `decodeAssertionClaims()` helper costs nothing).
