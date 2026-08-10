# Drupal Canvas Personalization: VWO

Segments Canvas personalization variants on [VWO](https://vwo.com) audiences,
so a page can serve a different variant to visitors VWO places in an audience.

This module adds one `SegmentCondition` plugin. It changes nothing in
`canvas_personalization`.

## What VWO actually offers, and what this means

VWO has **no API that answers "is visitor X in audience Y"**. Its audiences are
an evaluation *input* to a campaign or a feature flag, never a queryable
output:

- The REST API has no visitors, profiles, audiences, or segments endpoints.
- The one UUID-keyed endpoint,
  `GET /api/v2/accounts/{accountId}/uuid/{uuid}`, returns campaigns,
  variations, and session recordings, with no segment field at all. It is
  Enterprise-only and rate limited to **one request per second per token**,
  which rules it out of a page render regardless.
- Data360 segments are documented as post-segmentation for reports only, and
  the per-profile "Segments" view is not shipped.
- VWO's Segment.com integration imports audiences *into* VWO; it does not
  publish membership back out.

So an audience is modeled as a **VWO FME feature flag** whose targeting rule
defines it, and membership is that flag being enabled for the visitor. This is
the pattern VWO documents for server-side evaluation, and the only one that is
viable at render time: the FME SDK evaluates the flag locally against a
settings file it downloads, rather than calling VWO per decision.

Two limits follow, both VWO's and not Canvas':

- Only targeting the SDK can evaluate from the settings file applies.
  Behavioral and historical conditions, and VWO Testing or Data360 audiences,
  are not reachable from a server.
- Geolocation and user-agent targeting require VWO's self-hosted Gateway
  Service, which this module does not wire up.

## Setup

1. Install the SDK:

   ```
   composer require wingify/wingify-fme-php-sdk
   ```

2. Put the credentials in `settings.php` — never in configuration. Segment
   rules are exported with site configuration and readable over the segment
   HTTP API, so a secret placed in a rule leaks:

   ```php
   $settings['canvas_personalization_vwo'] = [
     'account_id' => 123456,
     'sdk_key' => getenv('VWO_SDK_KEY'),
   ];
   ```

3. In VWO, create a feature flag whose rule targets the audience you want.

4. In Drupal, add a **VWO audience** rule to a segment and enter the flag key.
   Then use that segment on a personalization variant.

The status report reports missing credentials or a missing SDK, but only once
an enabled segment actually uses a VWO rule. Without that report the failure is
invisible: an unconfigured integration just serves everyone the default
variant.

## Settings

`canvas_personalization_vwo.settings`:

| Key | Default | What it does |
|---|---|---|
| `cookie_name` | `_vwo_uuid_v2` | The VWO identity cookie. Accounts created from 2026-06-14 write `_wingify_uuid_v2`, and an account may configure a cookie prefix. |
| `membership_ttl` | `300` | How long one visitor's answer is reused. Also the max-age the condition declares, so it bounds how long a personalized page stays cached. |
| `failure_ttl` | `60` | How long a failed lookup is remembered, so a VWO outage costs one attempt per visitor per this many seconds rather than one per render. |
| `settings_ttl` | `300` | How long VWO's account-wide settings file is reused. Flag changes made in VWO take up to this long to reach the site. |
| `timeout_ms` | `2000` | Hard ceiling on any call to VWO. The SDK's own default is 50000, which would hang a page render. SDK retries are disabled so this is the whole budget: left on, the SDK would retry three times with 2, 4, and 8 second synchronous sleeps between attempts. |

## What this does to page caching

Plainly, for an operator:

- **A page with a VWO-segmented variant is no longer served by Drupal's
  internal page cache.** The condition varies by the VWO cookie, which that
  cache cannot see, so `SegmentAwareResponsePolicy` excludes the response. It
  is still served from `dynamic_page_cache`, which does not re-render the page,
  but it does bootstrap Drupal. Expect the cost of a dynamic page cache hit,
  not of a full render, and not of a page cache hit.
- **Those cached entries are per visitor, not shared.** The declared context is
  `cookies:_vwo_uuid_v2`, and that cookie is a unique per-visitor identifier —
  so every visitor gets their own `dynamic_page_cache` entry for the page. On a
  site with a lot of anonymous traffic this is a large number of low-value
  cache entries. The declaration is honest and correct: a wrong variant can
  never be served. But if you personalize a high-traffic page on a VWO
  audience, size the cache backend for it, and prefer personalizing pages whose
  anonymous traffic is modest.
- **Nothing changes for pages without a personalized variant.**
- A CDN in front of Drupal must either not cache these pages or vary on the
  same cookie.

## Degradation

Every failure resolves to "not a member", so the visitor gets the default
variant. Never an error page, never a wrong variant, never a render blocked on
VWO:

| Situation | Result | VWO called? |
|---|---|---|
| No VWO cookie (first visit, bot, opted-out visitor, VWO's localStorage identity mode) | Default variant | No |
| Cookie present but not a UUID VWO would accept | Default variant | No |
| Membership already resolved for this visitor within `membership_ttl` | Cached answer | No |
| VWO unreachable, erroring, or slower than `timeout_ms` | Default variant, logged, negatively cached for `failure_ttl` | Once per `failure_ttl` |
| SDK not installed, or credentials missing | Default variant, reported on the status report | No |

A **negated** VWO rule inverts this: when VWO cannot be reached it matches
everyone. That is a real choice, not an accident — prefer a non-negated rule
for anything you would not want shown site-wide during a VWO outage.

## Testing

`tests/modules/canvas_personalization_vwo_test` replaces
`VwoAudienceResolverInterface` with a stub scripted through state, so the
caching, timeout, and fail-closed behavior can be exercised without a VWO
account. Everything except whether VWO itself reports a given flag enabled for
a given visitor is covered that way.
