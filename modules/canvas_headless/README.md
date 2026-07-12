# Drupal Canvas Headless

Introduces first-class headless frontend app support to [Drupal Canvas](https://www.drupal.org/project/canvas):
the editor embeds your decoupled frontend app, and editors preview their work rendered by the app itself.

The module is experimental (`lifecycle: experimental`): while the Canvas Headless milestone is in progress, its APIs,
hooks, and configuration may change without a deprecation path.

## Requirements

- [Simple OAuth module](https://www.drupal.org/project/simple_oauth) (>=6.0.0), with its RSA keypair configured (see
  `/admin/config/people/simple_oauth`). The same keypair signs preview assertions; no additional keys are needed.
- The [Lupus Decoupled](https://lupus-decoupled.org/) CE API stack (`custom_elements`, `lupus_ce_renderer`,
  `lupus_decoupled_ce_api`), declared as module dependencies; it provides the rendered-routes endpoint
  (`/ce-api/{path}`).
- A frontend app implementing the draft SDK and the host protocol. The bundled example app (`examples/nextjs-app`)
  is a working Next.js reference; see its README for setup. It additionally expects JSON:API for listings.

## Setup

1. Install the module. It provisions the OAuth consumer and scope it needs; there is nothing to create manually.
2. Point it at the frontend app:

   ```bash
   drush config:set canvas_headless.settings frontend_url http://localhost:3000
   ```

3. Grant the `access canvas headless preview` permission to the editorial roles that should preview through the
   app. The permission lets its holders mint preview credentials for themselves.

Opening an entity in the Canvas editor then loads the app in the editor frame with an active draft session.

In cloned environments, regenerate the Simple OAuth keypair per environment; with shared keys, preview credentials
minted on one clone would redeem on another.

## Browser support

- Chromium-based browsers: works over HTTPS, and without HTTPS on a plain-http `localhost` dev server.
- Firefox: works over HTTPS; fails over plain http, and under "block all third-party cookies" unless the user adds
  a per-site exception for the Drupal site.
- Safari: follows CHIPS availability (unavailable in 18.5–26.1).

## Declaring preview-safe permissions

A preview token carries the editor's own permissions, capped to those declared safe for a read-only preview. The
module's baseline covers core content viewing; if your module defines view permissions that draft previews need,
declare them:

```php
function my_module_canvas_headless_safe_permissions(): array {
  return ['view my_module widgets'];
}
```

An undeclared permission means a preview shows too little, never too much. See `canvas_headless.api.php` for the
hook documentation, including the site-policy `_alter` hook.

## Known limitations

- The CE API serves the default revision: an unpublished entity previews fully, but a published entity's forward
  revision appears only in JSON:API-driven listings (the SDK hydrates working copies), not on CE-rendered pages.
- Core JSON:API filtered collections exclude unpublished content regardless of permissions; the example app avoids
  filtered collection queries for draft content.
- Content gated by a view permission not declared preview-safe is invisible in previews until the owning module
  declares it.
- Editors need view access to the entity they preview, not only edit access; without it the preview fails to start.
- `frontend_url` is a single site-wide setting: enabling the module replaces the Drupal-rendered preview for every
  entity editing context. An entity without a canonical URL, or one the app does not serve, shows a preview-start
  failure.

## Further reading

- A concept-level walkthrough of the auth design — OAuth roles, JWT anatomy, the validation chain, the RFC map:
  [docs/headless-preview-auth.md](docs/headless-preview-auth.md).
- The architectural decisions and their alternatives:
  ADRs [0014](../../docs/adr/0014-headless-draft-preview-user-bound-tokens-via-jwt-assertion-grant.md) (user-bound
  tokens via the assertion grant),
  [0015](../../docs/adr/0015-headless-draft-preview-session-renewal-re-anchored-in-drupal-session.md) (session
  renewal), and
  [0016](../../docs/adr/0016-headless-draft-preview-embedded-draft-state-in-partitioned-cookies.md) (embedded
  cookie transport, including the full browser matrix).
