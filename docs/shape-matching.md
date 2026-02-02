
# Drupal Canvas's Shape Matching

In the rest of this document, `Drupal Canvas` will be written as `Canvas`.

This builds on top of the [`Canvas Components` doc](components.md). Please read that first.

**Also see the [diagram](diagrams/data-model.md).**

## Finding issues 🐛, code 🤖 & people 👯‍♀️
Related Canvas issue queue components:
- [Shape matching](https://www.drupal.org/project/issues/canvas?component=Shape+matching) (see section
   3.1.2 below, and specifically 3.1.2.a)
- [Redux-integrated field widgets](https://www.drupal.org/project/issues/canvas?component=Redux-integrated+field+widgets)
- [Data model](https://www.drupal.org/project/issues/canvas?component=Data+model)

Those issue queue components also have corresponding entries in [`CODEOWNERS`](../CODEOWNERS).

If anything is unclear or missing in this document, create an issue in one of those issue queue components and assign it
to one of us! 😊 🙏

## 1. Terminology

### 1.1 Existing Drupal Terminology that is crucial for Canvas

- `computed field prop`: not every `field prop` has their value _stored_, some may have their value _computed_ (for example: the `file_uri` field type's `url` prop)
- `base field`: an `entity field` that exists for _all_ bundles of an entity type, typically defined in code
- `bundle field`: a `field instance` that exists for _some_ bundles of an entity type, typically defined in config
- `content entity`: an entity that can be created by a Content Creator, containing various `field`s of a particular entity type (e.g. "node")
- `content type`: a definition for content entities of a certain entity type and bundle, and hence every `content entity` of this bundle is guaranteed to contain the same `bundle field`s
- `data type`: Drupal's smallest unit of representing data, defines semantics and typically comes with validation logic and convenience methods for interacting with the data it represents ⚠️ Not all data types in Drupal core do what they say, see `\Drupal\canvas\Plugin\DataTypeOverride\UriOverride` for example. ⚠️
- `entity field`: a definition on the entity type or bundle level for creating a `field instance` on such an entity — see `base field` and `bundle field`
- `field`: synonym of `field item list`
- `field prop`: a property defined by a `field type`, with a value for that property on such a `field item`, represented by a `data type`. Often a single prop exists (typically: `value`), but not always (for example: the `image` field type: `target_id`, `entity`, `alt`, `title`, `width`, `height` — with `entity` a `computed field prop`)
- `field instance`: a definition for instantiating a `field type` into a `field item list` containing >=1 `field item`
- `field item`: the instantiation of a `field type`
- `field item list`: to support multiple-cardinality values, Drupal core has opted to wrap every `field item` in a list — even if a particular `field instance` is single-cardinality
- `field type`: metadata plus a class defining the `field prop`s that exist on this field type, requires a `field instance` to be used
- `field widget`: see [`Redux-integrated field widgets` doc](redux-integrated-field-widgets.md)
- `SDC`: see [`Canvas Components` doc](components.md)

### 1.2 Canvas terminology

- `component`: see [`Canvas Components` doc](components.md)
- `component input`: see [`Canvas Components` doc](components.md)
- `Component Source Plugin`: see [`Canvas Components` doc](components.md)
- `component type`: see [`Canvas Components` doc](components.md)
- `conjured field`: a `field instance` that is not backed by code nor config, but generated dynamically to edit/store a value for a `component input` as `unstructured data`
- `prop expression`: a (compact) string representing what context (entity type+bundle or field type) is required for retrieving one or more properties stored inside of that context; also has a typed PHP object representation to facilitate logic
- `prop shape`: a normalized representation of the schema for a `component input`, without metadata that does not affect the _shape_: a title or description does not affect what values _fit into this shape_; only necessary for `Component Source Plugins` that DO NOT provide their own input UX.
- `prop source`: a source for retrieving a prop value
  - `static prop source`: a `prop source` powered by a `conjured field` (i.e. `unstructured data`)
  - `dynamic prop source`: a `prop source` powered by a `base field` or `bundle field` (i.e. `structured data`)
  - `host entity URL prop source`: a `prop source` that generates various URLs for the `content entity` that contains it (e.g., the entity's canonical URL)
  - TBD: `remote prop source`: a `prop source` powered by a remote source ("external data"), i.e. data stored outside Drupal
- `structured data`: the data model defined by a Site Builder in a `content type`, and whose smallest units are `field props` — queryable by Views
- `unstructured data`: the ad-hoc data used to populate `component input`s that are not populated using `unstructured data` — NOT queryable by Views, this should be minimized/discouraged
- `well-known prop shape`: a `prop shape` that is _named_: one that is defined in a module's or theme's `/schema.json` file and is then referenced (`$ref`) from the JSON schema for that `component input`.

## 2. Product requirements

This uses the terms defined above.

This adds to the product requirements listed in [`Canvas Components` doc](components.md).

(There are [more](https://docs.google.com/spreadsheets/d/1OpETAzprh6DWjpTsZG55LWgldWV_D8jNe9AM73jNaZo/edit?gid=1721130122#gid=1721130122), but these in particular affect Canvas's data model.)

- MUST allow continuing to use existing Drupal functionality (notably: `field type`s and `field widget`s for `Component Source Plugin`s that do not have their own input UX)
- SHOULD encourage Content Creators to use `structured data` whenever possible, `unstructured data` should be minimized except where necessary
- MUST be able to facilitate changes in `component input`s (i.e. schema changes, that may result in a changed `prop shape`)

## 3. Implementation

This uses the terms defined above.

### 3.1 Data Model: from Front-End Developer to a Canvas data model that empowers the Content Creator

⚠️ This only applies to `component`s originating from a `Component Source Plugin` that DO NOT have an input UX (such as
`SDC`), for others the UX and storage are both simply the existing one, and NOTHING in this document applies! ⚠️

#### 3.1.1 Interpreting `component`s without input UX: `prop shapes`

See `\Drupal\canvas\PropShape\PropShape`.

Each `component input` must have a schema that defines the primitive type (string, number,  integer, object, array or
boolean), with typically additional restrictions (e.g. a  string containing a URI vs a date,  or an integer in a certain
range). That primitive type plus additional restrictions identifies a unique `prop shape`.

Some `prop source`s only support particular `prop shape`s, others may be able to support virtually any `prop shape`.

#### 3.1.2 Finding fitting `field type`: `conjured field`s and `entity fields`s

Per the product requirements, existing `field type`s and `field widget`s MUST be used, and ideally `structured data`
SHOULD be used.  But `field type`s can be configured, and depending on the configured settings, they may support rather
different `prop shape`s. For example: Drupal's "datetime" `field type` can, depending on settings, store either:

- date only
- date and time

So, the settings for a `field type` are critical: a `field type` alone is insufficient. How can `Canvas` determine the
appropriate field settings for a `prop shape`? And what about existing `structured data` versus `unstructured data`?

⚠️ _Why even have `unstructured data`? Why not create `structured data` to populate all `component input`s?_, you might
ask. Because:

- `structured data` requires `base field`s or `bundle field`s, and once in use, they cannot be removed
- therefore capturing all values for `component input`s as `structured data` would cause many new `bundle field`s to be
  created that may shortly thereafter no longer be used
- plus, not all `component input`s contain meaningful information to query — many contain purely _presentational_
  information such as the width of a column, the icon to use, et cetera
- in other words: `component input`s should be populated by `structured data` if they contain _semantical_ information,
  otherwise it is _presentational_ information and hence `unstructured data` is more appropriate

##### 3.1.2.a `structured data` → matching `entity fields`s ⇒ `dynamic prop source`

See:
- `\Drupal\canvas\ShapeMatcher\JsonSchemaFieldInstanceMatcher`
- `\Drupal\canvas\JsonSchemaInterpreter\JsonSchemaType::toDataTypeShapeRequirements()`
- `\Drupal\canvas\PropExpressions\StructuredData\EntityFieldBasedPropExpressionInterface`
- `\Drupal\canvas\PropSource\DynamicPropSource`

All `structured data` in every `content entity` in Drupal is found in an `entity field` (a `base field`s or
`bundle field`). These already have field settings defined. Hence `Canvas` must **match** a `field instance` for a given
`prop shape`.

How can this reliably be matched? Drupal's validation constraints for `field type`s and `data type`s determine the
precise shapes of values that are allowed … exactly like a `prop shape` (i.e. the JSON schema for a `component input`)!

Hence the matching works like this:
1. transform the JSON schema of a `prop shape` to the equivalent primitive `data type` + validation constraints (see
   `JsonSchemaType::toDataTypeShapeRequirements()`)
2. iterate over all `entity field`s in the site, and compare the previous step's `data type` + validation constraints
   to find a match

Finally, while the `prop shape` may be the same for many `component input`s, that same `prop shape` may be required for
one `component`'s `component input`, but optional for another. So an additional filtering step is required for optional
versus required occurrences of a `prop shape`:
3. if a `component input` is required, the matching `entity field`s must also be marked as required

The found `entity field`s can then be used in a `dynamic prop source`, that can be _evaluated_ to retrieve the stored
value that fits in the `prop shape`.
ℹ️ A `dynamic prop source` may specify a single "adapter" plugin (which must take a single input), which allows
Canvas to suggest field properties that _conceptually_ fit perfectly, but don't _technically_ fit, a particular `prop shape`.
For example: "timestamp" fields (which contain UNIX timestamps) can be made available to props that have the
`type: string, format: date` shape, by using the `unix_to_date` adapter plugin.

See `\Drupal\canvas\PropSource\DynamicPropSource`.

⚠️ **Multiple** bits of `structured data` may be able to fit into a given `prop shape`. All viable choices are
suggested by `\Drupal\canvas\ShapeMatcher\PropSourceSuggester`, with irrelevant choices omitted (such as the
"Default translation" boolean, or the "Revision log message" prose string). The Content Creator or Site Builder will
choose one.

ℹ️ The completeness of this is tested by `\Drupal\Tests\canvas\Kernel\EcosystemSupport\FieldTypeSupportTest`.

##### 3.1.2.b `unstructured data` → generating `conjured field`s ⇒ `static prop source`

See:
- `\Drupal\canvas\JsonSchemaInterpreter\JsonSchemaType::computeStorablePropShape()`
- `\Drupal\canvas\PropShape\StorablePropShape`
- `\Drupal\canvas\PropShape\CandidateStorablePropShape`
- `\Drupal\canvas\PropShape\PropShape::standardize()`
- `hook_canvas_storable_prop_shape_alter()`
- `\Drupal\canvas\PropExpressions\StructuredData\FieldTypeBasedPropExpressionInterface`
- `\Drupal\canvas\PropSource\StaticPropSource`

For any `unstructured data`, no field settings exist yet, so the appropriate settings for a `prop shape` must be
generated. `JsonSchemaType::computeStorablePropShape()` contains logic to that relies only on `field type`s
available in Drupal core. Unlike for `structured data`, no additional complexity is necessary for required versus
optional `component input`s.

Contributed modules can implement `hook_canvas_storable_prop_shape_alter()` to make different choices. Such hooks
receive a `\Drupal\canvas\PropShape\CandidateStorablePropShape` value object, which contains:
- the precise storage decision: field type, storage settings, instance settings and cardinality
- the corresponding UX decision: field widget
- the cacheability that led to this decision — e.g. the presence of certain config

Any `component input` whose `prop shape` corresponds to a `well-known prop shape` may cause _two_ invocations of that
alter hook:
1. once for the `well-known prop shape` (so: `"type": "string|object|…", "$ref": "json-schema-definitions://…"`), if any
2. once for the resolved equivalent (with `$ref` resolved) — unless the above already found a result

The computed `\Drupal\canvas\PropShape\StorablePropShape` can be used to create a `static prop source`
(which contains all information for the `conjured field` that powers it), that can be _evaluated_ to retrieve the stored
value that fits in the `prop shape`.

See `\Drupal\canvas\PropSource\StaticPropSource`.

💡 Whenever the cacheability that led to a decision is invalidated (e.g. some config was saved), these hooks are executed
again, to ensure an up-to-date decision for how to store props of the given `prop shape`.
Note: if the result is  different, a [new version is added to the `Component config entity`](config-management.md#3.1).

⚠️ When choosing to use `unstructured data` to populate a `component input`, Canvas decides
using the aforementioned logic what `field type`, `field widget` et cetera to use. Only when using `structured data`,
there is a need for an additional choice (see the `PropSourceSuggester` mentioned in 3.1.2.a).

#### 3.1.3 `prop expression`s: evaluating a `dynamic prop source` or `static prop source`

`prop expression`s are one of the lowest level building block of how Canvas works: it's similar to Drupal core's "token"
system, but more precise (it can return non-string values) and powerful (they can be chained).

Only developers of field types ever have to understand them in detail. Site Builders and Content Creators only interact
with them  indirectly: Site Builders choose `dynamic prop source`s or `static prop source`s to populate
`component input`s, and those `prop source`s contain `prop expression`s that define how to retrieve the actual value(s).

So, _if_ you're going to implement `hook_canvas_storable_prop_shape_alter()`, you will need to have at least a basic
understanding of `prop expression`s, because you may need to read or modify them. The classes and tests mentioned below
should help with that.

See
- `\Drupal\canvas\PropExpressions\StructuredData\Labeler`
- `\Drupal\canvas\PropExpressions\StructuredData\Evaluator`
- `\Drupal\canvas\PropExpressions\StructuredData\EntityFieldBasedPropExpressionInterface`
- `\Drupal\canvas\PropExpressions\StructuredData\FieldTypeBasedPropExpressionInterface`
- `\Drupal\canvas\PropExpressions\StructuredData\ScalarPropExpressionInterface`
- `\Drupal\canvas\PropExpressions\StructuredData\ObjectPropExpressionInterface`
- `\Drupal\canvas\PropExpressions\StructuredData\ReferencePropExpressionInterface`
- `\Drupal\Tests\canvas\Unit\PropExpressionTest::testFromString()`
- `\Drupal\Tests\canvas\Unit\PropExpressionTest::testToString()`
- `\Drupal\Tests\canvas\Kernel\PropExpressionKernelTest::testLabel()`
- `\Drupal\Tests\canvas\Kernel\PropExpressionKernelTest::testCalculateDependencies()`

Many `field type`s contain a single `field prop` (typically named "value"), but not all. Most `field type`s have one
required "main prop", many have additional optional props or even computed props.

To reliable retrieve the value from a `static prop source` or `dynamic prop source`, the `field item` alone is
insufficient: `Canvas` needs to know exactly which `field prop`(s) to retrieve from a `field item`. Plus, it may need to
arrange those retrieved values in a particular layout (for `prop shape`s that use the "object" primitive type the right
key-value pairs must be assembled).

To express that, `prop expression`s exist, which define:

- what context they need as a starting point (**Starting point** column), either:
  - `field item` or `field item list` of a certain `field type`: field type-based prop expressions
  - or a `content entity` of a certain `content type` containing a particular `entity field`: entity field-based prop
    expressions
2. optionally for `entity field`s: specify a delta to determine which `field item` from a `field item list` to use. The
   absence of a delta  is interpreted as "everything please". For a `field item list` configured for single cardinality
   that would be a single value, versus an array of values for multiple cardinality.
3. what `field prop`s they must retrieve in that context, possibly following entity references (**Reference** column)
4. what the **evaluation result shape** is: either
   - a single scalar value (most common), or a list ("array") of scalar values
   - or a list of key-value pairs: an "object", or a list ("array") of such key-value pairs

| prop expression class                | starting point | reference | evaluation result shape |
|--------------------------------------|:--------------:|:---------:|:-----------------------:|
| `FieldPropExpression`                | entity field   |    ❌    |         scalar          |
| `FieldObjectPropExpression`          | entity field   |    ❌    |         object          |
| `ReferenceFieldPropExpression`       | entity field   |    ✅    |            ❌           |
| `FieldTypePropExpression`            | field type     |    ❌    |         scalar          |
| `FieldTypeObjectPropExpression`      | field type     |    ❌    |         object          |
| `ReferenceFieldTypePropExpression`   | field type     |    ✅    |            ❌           |

`prop expression`s have 2 representations:

- a string representation, to simplify both debugging and storing them (both of those benefit from terseness) — to
  convert to the other representation: `StructuredDataPropExpression::fromString()`)
- a typed PHP object representation, to simplify logic interacting with them — to convert to the other representation:
  cast to string using `(string)`)

Examples:
- `ℹ︎␜entity:node:article␝title␞99␟value` declares it evaluates an "article" `content entity`, and returns the "value"
  prop of the 100th `field item` in the "title" `field`. When the Site Builder constructs a content template, they are
  presented with the corresponding label: "Title␞100th item". This is a hierarchical label; the semantical hierarchy
  markers such as `␞` are never shown to the end user.
- `ℹ︎image␟{src↝entity␜␜entity:file␝uri␞␟url,alt↠alt}` declares it evaluates an "image" `field item`, has no
  corresponding label (because it is for a `static prop source` and hence never needs to be explained/presented to a
  Canvas user), and returns two key-value pairs:
  - the first one being "src" for which the first "url" `field prop` of the "uri" `field` on the "file"
    `content entity` that is referenced by the "image" `field type`
  - the second one being "alt", which can be retrieved directly from the "image" `field item`.
- `ℹ︎entity_reference␟entity␜[␜entity:media:image␝field_media_image␞␟src_with_alternate_widths][␜entity:media:remote_image␝field_media_oembed_image␞␟remote_image_web_uri]`
  declares it evaluates an entity reference `field item`, has no corresponding label (same reason as above), branches
  based on the bundle of the referenced `Media` `content entity`:
  - if it is of the "image" media type, it fetches the "src_with_alternate_widths" field property
  - if it is of the "remote_image" media type, it fetches the "remote_image_web_uri" field property
  - … but in either case, it returns a single value: an HTTP(S) URI pointing to an image

For more examples, see `\Drupal\Tests\canvas\Unit\PropExpressionTest`. To gain a deeper understanding of how these work,
put a breakpoint in its `::testFromString()` and `::testToString()` methods.
Note that their functionality that requires a working kernel has its test logic in
`\Drupal\Tests\canvas\Kernel\PropExpressionKernelTest`, but reuses the same test cases as the unit test. It is split to
keep maximally benefit from unit test speed when working on this. This improves maintainability/DX.


#### 3.1.4 Non-structured data `prop source`: `host entity URL prop source`

There may be additional `prop source`s that may be offered as suggestions to Site Builders and/or Content Authors to
populate `component input`s.

For the various URI `prop shape`s (see also [3.2.2](#3.2.2)!), there is the `host entity URL prop source`, which is able
to generate various URIs that point to the host entity (i.e. the containing `content entity`).

See:
- `\Drupal\canvas\PropSource\HostEntityUrlPropSource`
- `\Drupal\canvas\ShapeMatcher\PropSourceSuggester::matchHostEntityUrlPropSources()`


### 3.2 Additional functionality overlaid on top of the SDC JSON Schema

Drupal Canvas extends SDC JSON Schema to support additional prop shapes to complete the content editing experience.

#### 3.2.1 HTML Content with CKEditor 5 Integration

Drupal Canvas supports rich text editing for `prop shape`s through CKEditor 5 integration. This allows SDC
developers to define props that can contain formatted HTML content.

##### JSON Schema Extensions

Two additional metadata properties are used to indicate HTML content — one is part of the JSON Schema standard, the
other is a [custom annotation](https://json-schema.org/understanding-json-schema/reference/non_json_data#contentmediatype)
(which can be recognized by the `x-` prefix).

Example:
```yaml
heading:
  type: string
  contentMediaType: text/html
  x-formatting-context: inline
```

Explanation:
- `contentMediaType: text/html` - Indicates this is a prop expecting to receive HTML content
- `x-formatting-context: inline|block` - Optionally specifies the formatting context (`block` is the default):
  - `inline`: Only inline elements allowed (`<strong>`, `<em>`, `<u>`, `<a>`)
  - `block`: Both inline and block elements allowed (adds `<p>`, `<br>`, `<ul>`, `<ol>`, `<li>`)

##### Text Formats

To allow populating such props, Drupal Canvas provides two predefined text formats:

1. **Canvas HTML Inline Format**
   - Allows only inline elements: `<strong>`, `<em>`, `<u>`, `<a href>`
   - Appropriate for headings, labels, and other inline content

2. **Canvas HTML Block Format**
   - Allows both inline elements and block elements: `<p>`, `<br>`, `<ul>`, `<ol>`, `<li>`
   - Appropriate for longer content blocks, descriptions, etc.

##### Example Component with HTML Props

```yaml
props:
  heading:
    type: string
    title: "Heading"
    contentMediaType: text/html
    x-formatting-context: inline

  description:
    type: string
    title: "Description"
    contentMediaType: text/html
    # This is the default, so it can be omitted.
    x-formatting-context: block
```

#### 3.2.2 URIs pointing to particular types of targets and allowed URI schemes

Two additional metadata properties are used to indicate:
1. types of targets using `contentMediaType` (repurposing an existing part of the JSON Schema spec per [a JSON Schema
   spec issue](https://github.com/json-schema-org/json-schema-spec/issues/1557)
2. allowed URI schemes using `x-allowed-schemes` (which is another [custom annotation](https://json-schema.org/understanding-json-schema/reference/non_json_data#contentmediatype))

Example:
```yaml
heading:
  type: string
  format: uri
  contentMediaType: image/*
  x-allowed-schemes: [http, https]
```

Explanation:
- `contentMediaType: image/*` - Optional; indicates this is a prop expecting to receive a URI pointing to an image
  resource (using a wildcard MIME type). See
  [JSON Schema spec issue](https://github.com/json-schema-org/json-schema-spec/issues/1557) and Canvas'
  `UriTargetMediaTypeConstraint`.
- `x-allowed-schemes` - Optional; indicates which URI schemes are allowed for URIs passed into this shape. Specifying
  `[http, https]` conveys the URI must be resolvable by web browsers. (As opposed to something like Drupal's `public` or
  `private`, or other proprietary URI schemes.)
