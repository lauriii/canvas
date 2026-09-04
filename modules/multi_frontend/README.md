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

  All 28 pass on Drupal core 11.4.4: 20 in `ComponentProducerTest`, run in
  three batches of 6, 7 and 7 (91, 111 and 98 assertions), and 8 in
  `CacheabilityNormalizerTest` (20 assertions). Every method in both files was
  executed; the batches exist only for the memory reason below. The two
  reported issues are deprecations from core's own `TwigSandboxPolicy` against
  twig 3.28, which any Twig render triggers.

  This count has gone stale twice. Re-run them and re-count rather than
  trusting the number here. The only
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
  `Surrogate-Key: config:filter.format.basic_html file:1 media:1`, and
  `/page-api/node/1` reports
  `"varies": {"public": false, "on": ["accept-language", "cookie"]}`.
  Per-node precision is real: on `/page-api/photos` the card with a caption
  carries the text format's tag and the card without one does not.
- **Coding standards** pass. `phpcs.xml` gains one exemption, with a comment:
  Canvas requires kernel tests to extend its own base class, and this module
  cannot without acquiring the dependency it exists to avoid.
- **Two independent review passes** found sixteen real defects between them,
  all fixed and covered. A security pass found three that mattered: a text
  value stored without a format was returned raw into a prop declared as HTML,
  where core renders nothing; `ip`, `theme`, `timezone` and a bare `headers`
  context were all reported as safe for a shared cache, which is precisely the
  failure the class was written to prevent; and the schema endpoints built
  absolute URLs from the request Host without varying on it, so one
  unauthenticated request could poison the cached catalog a build toolchain
  follows. Each has a regression test.

  It also corrected a claim made here: "discarded `GeneratedUrl` cacheability"
  was listed below as fixed, and it had been fixed only in the test fixture.
  All four production call sites still had it. They are fixed now.
- **A code review** of the first push found ten real defects, all fixed and
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

## Forms

A form is not a component and does not pretend to be one. A component
renders; a form is a resource with a submit endpoint, so it gets a value
schema, a separate presentation map, and an endpoint that validates.

Exposure is **opt-in per form**, the opposite of the choice made for
components. Producing props is a read; a form endpoint writes, and Drupal has
hundreds of form classes of which most are administrative.

```php
#[Hook('multi_frontend_form_info')]
public function formInfo(): array {
  return [
    'album.signup' => [
      'class' => '\Drupal\album\Form\SignupForm',
      'label' => 'Newsletter signup',
      'permission' => NULL,
    ],
  ];
}
```

`GET /form-api/album.signup`:

```json
{
  "id": "album_signup_form",
  "schema": {
    "$schema": "http://json-schema.org/draft-07/schema#",
    "type": "object",
    "properties": {
      "email": {"type": "string", "format": "email", "maxLength": 254},
      "frequency": {"type": "string", "enum": ["monthly", "quarterly"],
                    "meta:enum": {"monthly": "Monthly", "quarterly": "Quarterly"}},
      "name": {"type": "string", "maxLength": 64}
    },
    "required": ["email", "frequency"],
    "additionalProperties": false
  },
  "ui": {
    "email": {"widget": "email", "label": "Email address",
              "description": "We send one update a month.",
              "placeholder": "you@example.com", "weight": 0}
  },
  "unsupported": ["avatar (managed_file)"]
}
```

`POST /form-api/album.signup` with `{"values": {...}}` returns violations as
JSON pointers:

```json
{"status": "invalid",
 "violations": [{"path": "/email", "message": "That domain is not accepted."}],
 "messages": []}
```

Four things here are deliberate.

**`unsupported` names what the contract cannot express.** Every prior attempt
dropped those elements silently and handed the client a form that rendered
completely and could not work. A client seeing `managed_file` in that list can
render natively, fall back to a server-rendered form, or refuse.

`unsupported` covers three kinds of gap, not just unknown element types:

| Reported as | When |
| --- | --- |
| `avatar (managed_file)` | the element type has no schema projection |
| `note (duplicate element name)` | two elements at different depths share a name. Values are flat, so the second would overwrite the first and the schema would describe only one of them |
| `nested (nested #tree values)` | a `#tree` subtree nests its values, which a flat schema cannot describe, so its fields are not published |

The last two are the ones that would otherwise be silent: nothing errors, the
form renders, and the payload the client builds is simply not the payload the
form reads.

**Elements describe themselves.** `FormDescriber` holds a map for core's own
element types, but it is only a fallback. Any element plugin implementing
`JsonSchemaFormElementInterface` answers for itself, so contrib extends the
contract without patching it. This is the inversion core asked for on issue
2913372 in 2017 and nobody built. With 78 element types registered on this
site — 58 from `Drupal\Core`, 20 declared by modules, nine of those contrib —
the central mapping every previous attempt used is unmaintainable by
construction.

**Submission runs the form's own handlers**, through
`FormBuilder::submitForm()`. Validation is neither re-implemented here nor
pushed onto the client, and the business logic in submit handlers runs — the
trap that makes a contact form submitted through the entity API never send its
email.

**Element access is enforced, against core's default.**
`FormState::$programmed_bypass_access_check` defaults to TRUE, so the obvious
implementation of this endpoint lets any client set values for elements hidden
behind `#access` — core's own comment on that branch warns such submissions
"may bypass access restriction and be treated as high-privilege users". The
submitter turns it off, and the describer does not publish inaccessible
elements. Both are tested.

**CSRF is core's existing header check**, `_csrf_request_header_token`, with
the token from `/session/token`. Verified: authenticated POST without the
header is 403; with it, validation runs. Anonymous needs no token, which is
correct and is what lets a public signup form work.

`#states` is not emitted. It is fully serializable, but its keys are jQuery
selectors against a DOM the client did not render, and it is presentation-only,
so omitting it costs no correctness. The one shipped implementation of this
contract, `webform_jsonschema`, supported roughly a twentieth of what `#states`
expresses and is now unmaintained.

### Rate limiting is the form's job, and that is an argument for this design

Nothing in the endpoint throttles submissions. That is deliberate rather than
overlooked: because submission runs the form's own validation, a form that
uses core's flood service is protected over HTTP exactly as it is in a browser.
`UserLoginForm` is the demonstration — exposed through this contract, it still
gets core's login flood control, because `validateAuthentication()` runs.

The corollary is the honest one: a form with no flood control of its own has
none here either, and a public write endpoint is a more attractive target than
a page with a form on it. A form exposed this way should carry its own flood
or captcha handling, and that is the same advice as for any Drupal form
reachable by anonymous users.

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
| `#attached` on a fallback node | **dropped.** An `html` node is `{type, markup, cacheability}`, with nowhere to put libraries or `drupalSettings`, so markup that needed JavaScript to work arrives inert. Cacheability does survive. This is the practical ceiling on "unconverted modules keep working" |
| Error responses in envelope format | **not done.** A 403 or 404 through `/page-api` is core's `text/plain`, so a client calling `res.json()` throws. The published envelope schema describes 200 responses only |
| `Vary` and `Cache-Control` from `CacheabilityHeaders` | **partly.** `Surrogate-Key` crosses. On a site with the internal page cache disabled, core's `FinishResponseSubscriber` marks responses not cacheable and strips `Vary`, so only the body's `varies` survives. Read the body, not the headers, for variation |
| Form contract: schema, UI hints, submission, CSRF, `unsupported` | done |
| Self-describing form elements (`JsonSchemaFormElementInterface`) | done |
| Multi-step form wizards | **not done.** Core's `FormCache` is session-bound and CSRF-stamped, so a stateless wizard needs an explicit page protocol |
| File uploads, `#ajax`, entity autocompletes in forms | **not done**, reported through `unsupported` |
| Entity forms (node add/edit) | **not done.** They need `EntityFormBuilder` and an entity to build against, so `FormBuilder` cannot reach them. A definition naming one fails 422, not 500 |
| Block producers without a page envelope | **partly.** `subject: 'block:*'` works inside a page, but gets no derived route: a block is a singleton per configuration, not an addressable entity |
| The `front_end` extension type | **not done**, a separate milestone |
| `typegen` and framework adapters | **not done**, and not core's to ship |

The first two are the containment problem, which the design names as the
highest-risk item in the plan. Until they land, the ratio of `component` nodes
to `html` nodes on a stock page stays low, and that ratio is the number the
whole on-ramp claim rests on.
