# Multi front end

Reference implementation of the `core-multi-frontend` change: component
producers, an envelope, and published props schemas.

This module depends on nothing but Drupal core, deliberately. It is written to
be proposed to core, and it ships here because that is where the work is being
done, not because it belongs to Canvas.

Read `changes/core-multi-frontend/` in the specs repository for the proposal,
the design and the decisions this implements.

## What a module writes

Three things, and nothing else. No controller, no route, no normalizer, no
serializer, no cache bridge.

**1. The contract**, as an ordinary SDC props schema that describes data as
data. An ISO 8601 timestamp, not a formatted date. A URL with intrinsic
dimensions, not a rendered `<figure>`.

**2. The producer**, which turns the module's model into exactly those props:

```php
#[ComponentProducer(
  id: 'album.photo',
  component: 'album:photo',
  subject: 'entity:media',
)]
final class PhotoCardProducer extends ComponentProducerBase {

  public function produce(mixed $subject, ProducerContext $context): array {
    $context->addCacheableDependency($subject);
    $item = $context->field($subject, 'field_media_image')?->first();

    return [
      'title' => $subject->label(),
      'caption' => $context->formattedText($subject, 'field_caption'),
      'takenAt' => ...,
    ];
  }

}
```

Read fields through `ProducerContext`, never off the entity. `field()` applies
the field's own view access and records the result's cacheability;
`formattedText()` additionally runs the text format's filters. Both are things
the field formatter would have done, and a producer replaces the formatter.

**3. One call site:**

```php
$build = ProducedComponent::build('album.photo', $media);
```

## What core gives back

The same producer call feeds every output, which is what stops the HTML and
the data paths drifting:

| Output | How |
| --- | --- |
| Twig SDC render | `ProducedComponent::build()`, or `#type: produced_component` |
| One component as JSON | `GET /component-api/album.photo/{media}`, a route derived from the producer |
| A whole page as JSON | `GET /any/path?_wrapper_format=envelope`, or `GET /page-api/any/path` |
| Its props schema | `GET /component-api/schema/album.photo` |
| The catalog, for code generation | `GET /component-api/schema` |
| The envelope's own schema | `GET /component-api/schema/_envelope` |

The derived data route carries `_entity_access: media.view`, taken from the
producer's declared subject, so the HTTP endpoint can never be laxer than the
entity's own rules.

Page envelopes are produced by the route that already serves the path, using
core's existing main-content-renderer-by-wrapper-format mechanism. Access
checking, upcasting, redirects, error statuses and language negotiation are
therefore not reimplemented; they are the same request. `/page-api/{path}` is a
middleware alias that rewrites and re-dispatches, the way
`lupus_decoupled_ce_api` implements `/ce-api`.

## Two things this does differently from core

**Validation is unconditional on the data path.** Core validates SDC props
behind `assert()`, and core's own documentation tells sites to run production
with `zend.assertions=-1`. That is right for a Twig-only render and wrong for
a published contract, so `ProducerInvoker` validates by calling the validator,
not by asserting it.

**Cacheability crosses the boundary in a form a consumer can act on.** Tags and
max-age port onto any framework or CDN. Cache contexts do not: a context ID is
a Drupal plugin name a client cannot evaluate. They are still emitted for
Drupal-aware consumers, alongside `varies`, the derived and portable
conclusion: whether a shared cache may store the response, and which HTTP
dimensions it varies on. The mapping is deliberately conservative, so a context
it does not recognize makes a response private.

Drupal's `-1` max-age is emitted as `null`, because a client reading the
sentinel as an HTTP max-age would get it exactly backwards.

## Verified

Checks run against Drupal core 11.4.4 on the development site this was built
on:

- **Kernel tests** cover the producer feeding both the Twig render and the
  envelope node, serializable values, cacheability collected during production,
  cache keys computable before the producer runs, a render cache hit never
  reaching the producer, per-field access, text-format filtering, unconditional
  prop validation, the two-node union, slots holding nodes, byte-identity
  between a component fetched alone and the same node read from a page, and the
  published schema.

  All 22 pass on Drupal core 11.4.4, run in batches for the reason below:
  `ComponentProducerTest` in three batches of 5, 7 and 4 (71, 104 and 68
  assertions) and `CacheabilityNormalizerTest` with 6 (12 assertions). The only
  reported issues are two deprecations raised by core's own
  `TwigSandboxPolicy` against twig 3.28, which any Twig render triggers.

  Note that Canvas CI does **not** run them: its Kernel job is scoped to
  `tests/src/Kernel/Config` on purpose, to prove the suite executes without a
  multi-hour run. A green Kernel check on a pull request says nothing about
  this directory. Run them explicitly, and in batches on a memory-constrained
  machine, because a single invocation of all of them under
  `RunTestsInSeparateProcesses` needs more than a 1 GiB container has:

      vendor/bin/phpunit -c web/core/phpunit.xml.dist \
        --filter 'testFieldAccessIsApplied|testFormattedTextIsFiltered' \
        <path>/modules/multi_frontend/tests/src/Kernel/ComponentProducerTest.php
- **A real front end.** An Astro site was built against a live site and
  measured against JSON:API doing the same job: 7 lines of data code against
  31, 1,600 bytes against 3,287, no hand-written types against all of them,
  and zero client changes when the module added a field against two. It loses
  to JSON:API on filtering, sorting and pagination, and on day-one entity
  coverage, both by design (D7). The comparison, including two silent failures
  the JSON:API version produced, is in the change's `examples.md`.
- **Live endpoints**, all returning 200 with the documented shape:
  `/component-api/schema`, `/component-api/schema/album.photo`,
  `/component-api/schema/_envelope`, `/component-api/album.photo/1`, and
  `/page-api/node/1`. The last returns a page envelope whose single region
  holds one `html` node, which is exactly what an unconverted site should
  return and is the number the on-ramp has to move.
- **Cacheability on the wire**: `/component-api/album.photo/1` emits
  `Surrogate-Key: file:1 media:1`, and `/page-api/node/1` reports
  `"varies": {"public": false, "on": ["cookie"]}` because the page's contexts
  include `user.permissions`.
- **Coding standards** pass. `phpcs.xml` gains one exemption, with a comment:
  Canvas requires kernel tests to extend its own base class, and this module
  cannot without acquiring the dependency it exists to avoid.
- **A Copilot review** of the first push found ten real defects, all fixed and
  covered: `#access` bypassed by the envelope walk, container splitting that
  dropped `#prefix`/`#attached`/`#cache`, three render-cache-key collisions
  (unsaved entities, revisions, attributes), props that were never checked for
  the JSON round trip the interface promises, child nodes rebuilt from props
  alone, empty props encoding as `[]` where the schema requires an object,
  route names that collapsed two producers onto one name, language contexts wrongly treated as URL-borne,
  and discarded `GeneratedUrl` cacheability. `#variant` support was removed
  rather than repaired: the envelope had no way to express it, so the same
  call site would have selected a variant in Twig and not in JSON.
- **Static analysis** reports only `Unused ...::__construct` findings on
  classes wired through `services.yml` and `routing.yml`. Analyzing an existing
  Canvas controller directory on its own produces identical findings, so this
  is an artifact of analyzing a narrow path rather than a defect.

## What is not implemented here

Honest scope. This is the vertical slice, not the whole plan.

| Plan item | State |
| --- | --- |
| Producer registry, render element, validation | done |
| Envelope, both node types, per-node cacheability | done |
| Derived data routes, schema publication, draft-07 | done |
| Page envelope by wrapper format, `/page-api` alias | done |
| **Lifting** a produced component out of an unconverted ancestor | **not done**. The walk descends only through plain containers, so a component inside a block or a field formatter is rendered into that subtree's markup and arrives inside an `html` node |
| Interleaving conversion with `#pre_render` expansion | **not done**. A `#pre_render` that creates a produced component is not seen |
| Regions other than `content` | **not done**. They come from the active theme's block layout, which is theme-scoped config |
| `#lazy_builder` over the data path | **not done**, and out of scope by design |
| The `front_end` extension type | **not done**, a separate milestone |
| `typegen` and framework adapters | **not done**, and not core's to ship |

The first two are the containment problem, which the design names as the
highest-risk item in the plan. Until they land, the ratio of `component` nodes
to `html` nodes on a stock page stays low, and that ratio is the number the
whole on-ramp claim rests on.
