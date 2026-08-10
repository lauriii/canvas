# Drupal Canvas Personalization

In the rest of this document, `Drupal Canvas` will be written as `Canvas`.

Personalization is provided by the hidden, experimental `canvas_personalization` module. The module itself is the
feature flag: it is `hidden: true` and only installable via drush, recipes, or tests. It may be folded into the main
module when that's more pragmatic.

## 1. Terminology

`Personalization Segmentation Rule` (aka `segment condition`): A condition that allows categorizing people into a
group, or a context condition. E.g. _“People who bought a bike last month”_, _“Visitors from the State of Colorado”_,
_“Today is Sunday”_. This powers market segmentation. It might or might not be deterministic.

`Personalization Segments (aka Segments)`: Group of segmentation rules that determine the target audience for doing
market segmentation. Sometimes might be referred to as `Audience` colloquially speaking, but we feel Segments is more
neutral e.g. when talking about temporary campaigns.

`Audience`: See above. The group of people who fit some characteristics defined by a group of segmentation rules.

`Campaign`: A personalization segment which is defined by a Segment Rule which might not be based on market
segmentation, but on a temporary basis. E.g. “Christmas campaign”.

`Personalization Variant` (term might change to `Variation`): A particular customization for a given Personalization
Segment. Not to be confused with [SDC Variant](https://www.drupal.org/node/3517062). A variant can change both the
explicit inputs of `component instance`s and *which* `component instance`s are present.

`Default Personalization Variant`: The variant displayed when the visitor doesn't match any other variant's segments.

`Grafting`: personalization variants of a subtree are grafts because they are _grafted_ onto (on top/over, really)
an existing subtree. See [Grafting on Wikipedia](https://en.wikipedia.org/wiki/Grafting).

## 2. Product requirements

* MUST be possible for the Site Builder to define Segments for which they will provide a Personalization Variant.
* MUST be possible for the Site Builder to select different values for each Segment Rule. How multiple values within
  one rule combine is defined by that rule's plugin — typically OR (e.g. _"Location = Colorado OR Massachusetts"_);
  some rules offer an all/any toggle (e.g. UTM parameters).
* MUST be possible for the Site Builder to add multiple Segment Rules to each Segment. Rules are combined with AND
  (e.g. _"Location = Colorado OR Massachusetts AND Day = Saturday OR Sunday"_). Rules can be negated.
* MUST be possible for the Site Builder to include a Segment Rule defined in a third-party tool like Mautic. See §6.
* MUST NOT be possible to add a Segment Rule of a type the Segment already has an instance of.
* MUST be possible to disable a Segment site-wide.
* MUST be possible to disable a Personalization Variant for a given component tree.
* MUST be possible to sort the Variants of a personalized subtree. They are evaluated in that priority order; the
  first Variant whose Segments all match is shown.
* MUST be possible to preview a given Personalization Variant, to name a Variant on creation, to select its Segments,
  and to add/modify/delete `component instance`s per Variant.
* Personalized pages MUST stay cacheable for anonymous users and MUST NOT leak a wrong variant from any cache.
* Personalized pages MUST NOT require client-side variant swapping: the correct variant is in the first HTML response,
  with no flicker, no layout shift, and no render-blocking personalization JavaScript on the live page.

## 3. Segments

### 3.1 Server data model

Segments are config entities (`canvas_personalization.segment.*`) with `id`, `label`, `description`, `rules`,
`weight`, and `status`.

* `status` is used for publishing: segments created over the HTTP API are born disabled, and segments in auto-save
  have `status: false`. Unpublished segments are treated as non-matching during negotiation (but still contribute
  their cache tag, so publishing one invalidates affected pages).
* `weight` orders the segments dashboard. Variant selection order is per-switch (see §5), not weight-driven.
* Segments are site-wide and reusable across variants and pages.
* A locked `default` segment is provided; it matches every visitor, acts as the negotiation fallback, and cannot be
  edited or deleted (enforced by `SegmentAccessControlHandler`).

`rules` is a mapping keyed by `SegmentCondition` plugin ID — one instance per condition type per segment, which
structurally enforces the "no duplicate rule type" requirement. Rules are combined with AND.

### 3.2 The `SegmentCondition` plugin type

Segment conditions are a dedicated plugin type — **not** core Condition API plugins. The deciding factor is what
third-party providers must implement: cacheability is part of the interface contract, so a condition cannot exist
without declaring what its result varies by.

* Attribute: `#[SegmentCondition(id: ..., label: ...)]`; manager service: `plugin.manager.segment_condition`;
  alter hook: `segment_condition_info`.
* `SegmentConditionInterface` requires `evaluate(): bool` and extends `CacheableDependencyInterface`,
  `ConfigurableInterface`, and `PluginFormInterface`.
* `SegmentConditionBase` provides `negate` support (final `evaluate()` delegating to `doEvaluate()`), and declares
  `getCacheTags()` final, returning `[]`:
  **segment conditions MUST NOT set cache tags.** Cache tags express dependencies on *stored data*; a condition's
  result depends only on request context (contexts) and time (max-age). Tags returned by a misbehaving condition are
  discarded and logged by the evaluator.
* Each plugin declares config schema as `canvas_personalization.segment_condition.<plugin_id>`, which validates its
  settings within the segment's `rules`.

Cacheability examples:
* a query-parameter condition on `coupon` declares the `url.query_args:coupon` cache context and permanent max-age;
* a day-of-week condition declares no contexts and a max-age of the seconds remaining until the next midnight in the
  site's timezone (its result cannot change sooner);
* a geolocation condition declares `headers:<configured country header>`.

### 3.3 Shipped conditions

| Plugin ID | Matches on | Settings |
|---|---|---|
| `query_parameter` | An arbitrary URL query parameter | `parameter`, `value`, `matching` (`exact` / `starts_with` / `present`) |
| `utm_parameters` | UTM query parameters | `parameters` (list of `key`/`value`/`matching`), `all` (AND/OR across entries) |
| `geolocation` | Country/region provided by the edge | `countries` (ISO 3166-1 alpha-2), `regions` (optional) |
| `day_of_week` | Day of week in the site timezone | `days` (OR within) |

All conditions support `negate`.

Geolocation does not resolve IPs itself: it reads request headers whose names are configured in
`canvas_personalization.settings` (`country_header`, default `X-Country-Code`; `region_header`, default
`X-Region-Code`), set by your CDN or reverse proxy. An absent or unknown header evaluates as not matching — fail
closed, never a wrong variant.

> **Deployment requirement — the edge MUST strip or overwrite both headers on inbound requests.** Drupal cannot tell
> a header its own edge set from one the visitor sent. An edge that only *adds* the header when it is missing leaves
> every variant selectable by anyone: `curl -H 'X-Country-Code: BE'` is then enough to see the Belgian variant. Set
> both headers unconditionally (overwrite, never append) on every request the edge forwards. This is the same trust
> boundary as `X-Forwarded-For`, and like it, it is closed in the edge configuration and Drupal's `trusted_host` /
> reverse-proxy settings — not in this module. Sites with an enabled geolocation rule get a status report warning
> naming the two headers, because there is no way to detect the difference at runtime.

### 3.4 HTTP API

Segments use the standard Canvas config HTTP API (`/canvas/api/v0/config/segment[/{id}]`) plus auto-save
(`/canvas/api/v0/config/auto-save/segment/{id}`), enabled by `ApiConfigRouteSubscriber`. Publishing goes through the
shared auto-save publish endpoint. Access is controlled by the `administer personalization segments` permission.

## 4. Variants: switch/case storage

The `p13n` component source defines two non-discoverable components. See `docs/data-model.md` §3.2.1.1.

* `p13n.switch` wraps a personalized subtree. Its inputs hold the ordered variant-ID priority list:
  ```yaml
  variants:
    - halloween
    - default
  ```
  Variant IDs are arbitrary machine names local to the switch; they are not Segment IDs.
* Each `p13n.case` lives in the switch's `content` slot and holds the subtree for one variant:
  ```yaml
  variant_id: halloween      # must appear in the parent switch's `variants`
  segments: ['halloween']    # Segment config entity IDs; ALL must match
  disabled: false            # optional; disabled cases are skipped in negotiation
  ```
* A case with `variant_id: default` / `segments: ['default']` is the fallback and should always be present. Because
  negotiation is strictly first-match-wins and the `default` segment matches everyone, the default variant MUST be
  last in `variants` — any variant listed after one that targets a match-everyone segment is unreachable. The
  authoring UI enforces default-last; hand-written trees must preserve it.
* The user-facing variant name lives in the component instance `label`, not in the inputs.
* Page-level personalization is simply a root-level switch whose cases contain full page trees.

## 5. Negotiation and cacheability

This is the heart of the feature. Live rendering negotiates each switch exactly once
(`Personalization::negotiateCases()`, called from `ComponentTreeItemList::renderify()`):

1. Walk the switch's `variants` in priority order.
2. A variant matches when **all** of its case's `segments` match the current request. Segment evaluation
   (`SegmentEvaluator`, memoized per request per segment) ANDs the segment's condition plugins, stopping at the first
   non-matching one — a condition that costs a network call is not consulted once a cheaper rule has decided the
   segment. The `default` segment always matches. Disabled cases, unpublished segments, and missing segments never
   match. A condition that throws is logged and treated as not matching — a broken or unreachable provider degrades to
   the default variant, it never breaks the page.
3. The first matching case is rendered; every other case is pruned before rendering.
4. In the editor preview, negotiation is skipped and all cases render.

### 5.1 Cacheability rules

The negotiation attaches to the switch's render element — regardless of which case won — the union of the
cacheability of **every** segment referenced by **any** case of the switch:

* **cache contexts**: union of all conditions' contexts. The match decision short-circuits; metadata collection never
  does, because a cached response must be correct for request contexts in which an earlier-priority variant matches.
* **max-age**: the minimum across all conditions.
* **cache tags**: `config:canvas_personalization.segment.<id>` for each referenced segment ID — added as literal
  strings so that deleted or not-yet-created segments still invalidate affected pages when they (re)appear.

### 5.2 Internal page cache compatibility

Drupal's internal `page_cache` keys entries on the URL only; it ignores cache contexts, and its entries expire only
via the `Expires` header. Three mechanisms keep personalization correct and fast for anonymous users:

1. **URL-derived contexts** (`url.query_args:*` — query parameter and UTM conditions) need nothing: different query
   strings are different page_cache entries. These pages get full page_cache hits per variant URL.
2. **Finite max-age** (day-of-week): a response subscriber sets `Expires: now + max-age` on responses whose
   negotiation contributed a finite max-age, so the page_cache entry expires exactly when the condition's result can
   change. HTTP clients ignore `Expires` when `Cache-Control: max-age` is present, so only the internal cache is
   affected.
3. **Non-URL contexts** (geolocation headers, provider cookies): invisible to a URL-only cache key, so a
   `page_cache_response_policy` service denies internal page caching for these responses. They remain fully cacheable
   in `dynamic_page_cache`, which keys on cache contexts — anonymous visitors still get cached responses, keyed by
   country header rather than URL alone. A wrong variant can never be served from cache, by construction. External
   edge caches that set the geo header themselves can additionally key on it and regain full-page edge caching.

Both mechanisms derive their decision from the response itself: the segment cache tags identify which segments
influenced it, and those segments' conditions *declare* their cacheability from configuration alone (see §3.2 —
declared cache contexts must never depend on the current request's values). This matters because a personalized
response is often served by `dynamic_page_cache` without any evaluation running on that request — the exclusion and
expiry must hold there too. Responses without segment tags are never touched, so caching of non-personalized pages is
unchanged.

## 6. Third-party segmentation providers

A provider integration (Mautic is the open-source reference; `canvas_personalization_vwo` is the shipped worked
example) is one `SegmentCondition` plugin in the provider's module, plus config schema. The contract gives an
integration everything it needs:

* **Extend `ExternalSegmentConditionBase`.** It implements the whole degradation policy below — the bounded-TTL
  membership cache, the negative cache, and fail-closed on every error path — so an integration supplies only the two
  halves that are actually provider-specific:
  ```php
  protected function getVisitorIdentity(): ?string;   // read the cookie/header; NULL = never consult the provider
  protected function resolveMembership(string $id): bool;  // ask the provider; may throw
  ```
  Getting that policy wrong is how a personalized page ends up hanging on someone else's outage, which is why it is
  not left to each integration to rewrite. Override `::getMembershipTtl()` / `::getFailureTtl()` to make the TTLs
  site-configurable, and declare `::getCacheContexts()` — only the plugin knows what its identity varies by.
* **Config schema** for the plugin is declared as `canvas_personalization.segment_condition.<plugin_id>`, typed off
  `canvas_personalization.segment_condition_settings`, in the *provider module's* own schema file. The type name is
  keyed by the host module's namespace, not the provider's, because `rules` resolves it as
  `canvas_personalization.segment_condition.[%key]`.
* **Services**: `SegmentConditionBase::create()` constructs `new static($configuration, $plugin_id,
  $plugin_definition)`, so a condition cannot take its dependencies as constructor arguments. A plugin needing more
  services overrides `::create()`, calls `parent::create()`, and assigns them as properties.
* **Credentials**: keep the secret itself out of the plugin's configuration — segment rules are exported with site
  configuration and readable over the segment HTTP API. Store a secret *reference* in the plugin settings (the
  provider segment/list identifier is fine there) and resolve the actual key at evaluation time from site settings,
  an environment variable, or a key management module.
* **Membership lookups** map a request-derived identifier — typically the provider's first-party cookie — to segment
  membership via the provider's API. The condition declares the matching cache context (`cookies:<name>` or
  `headers:<name>`); §5.2(3) then automatically keeps those responses out of the URL-keyed internal page cache, so a
  wrong variant cannot leak. A provider condition is consulted only where it can still change the outcome:
  evaluation of a segment stops at the first non-matching rule, and the segments of variants after the winning one
  are never evaluated — their cacheability is declared, not measured.
* ⚠️ **Mind the cardinality of that cache context.** A provider's first-party cookie is usually a *unique
  per-visitor* identifier, so declaring `cookies:<name>` gives every visitor their own `dynamic_page_cache` entry for
  a personalized page. That is correct — a wrong variant can never be served — but it is not a shared cache, and on a
  high-traffic page it is a large number of low-value entries. A provider expecting real anonymous traffic should
  declare a *derived, low-cardinality* context instead: a calculated cache context service
  (`Drupal\Core\Cache\Context\CalculatedCacheContextInterface`, tagged `cache.context`) that resolves to membership
  rather than to identity, so the page has as many cache entries as it has variants. That needs no change to this
  module, and the response policy in §5.2(3) treats it the same way.
* **Graceful degradation**: when the provider is unreachable, `resolveMembership()` should throw; the base class
  logs it to the `canvas_personalization` channel, fails closed to the default variant, and negatively caches the
  failure, so an outage costs at most one timeout per identifier per TTL instead of one per request. A condition that
  bypasses the base class and simply returns FALSE degrades *silently* — nothing is logged and nothing surfaces on
  the status report. Exceptions are additionally caught by the evaluator (§5, step 2).
* **Authoring**: the segments dashboard discovers condition types over
  `/canvas/api/v0/personalization/segment-condition`, so a third-party condition appears in the "Add rule" menu and
  its saved rules are listed on the segment. The dashboard only ships *editors* for the four conditions in §3.3;
  every other condition is configured through its `PluginFormInterface` form at
  `/admin/structure/segment/{segment}/rule`, which the dashboard links to. Providers that want an in-dashboard editor
  need a UI contribution; that is not yet an extension point.
* Conditions that depend on state only available client-side (e.g. a cookie the provider's JavaScript sets after
  first paint) cannot influence the *first* server-rendered response; from the second request on, the cookie rides
  the request normally. Canvas deliberately ships no client-side variant swapping (flash of wrong content, layout
  shift, cache complexity); if a provider requires it, that is a separate design.

## 7. Authoring UI

* `/segments`: the segments dashboard — create, rename, describe, enable/disable, delete, and reorder segments, and
  edit their rules with a dedicated editor per condition type.
* In the editor, variant management on a personalized page: create a variant (choosing an existing variant as the
  starting point — a deep client-side copy with fresh UUIDs), switch which variant is previewed and edited (the
  active variant is always indicated; only its subtree is shown in preview and layers), reorder variants by drag and
  drop (rewrites the switch's `variants` order), promote a variant to default (swaps the `variant_id`/`segments`
  mapping with the current default case), and disable or delete a variant.

## 8. Demo recipe

A recipe used for tests doubles as a demo:

```
ddev drush site:install minimal --yes && ddev drush user:password admin admin && \
ddev -d /var/www/html/web exec php core/scripts/drupal recipe modules/contrib/canvas/tests/fixtures/recipes/test_site_personalization
```
