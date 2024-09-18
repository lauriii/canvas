# The Experience Builder Data Model

In the rest of this document, `Experience Builder` will be written as `XB`.

This builds on top of the [`XB Components` doc](components.md). Please read that first.

It also builds on top of the [`XB Config Management` doc](config-management.md), which itself refers back to this one
for a few things. The data model is built on top of the configuration architecture.

**Also see the [diagram](diagrams/data-model.md).**

## Finding issues 🐛, code 🤖 & people 👯‍♀️
Related XB issue queue components:
1. [Data model](https://www.drupal.org/project/issues/experience_builder?component=Data+model)
2. [Shape matching](https://www.drupal.org/project/issues/experience_builder?component=Shape+matching) (see section
  3.1.2 below, and specifically 3.1.2.a)

Those issue queue components also have corresponding entries in [`CODEOWNERS`](../CODEOWNERS).

If anything is unclear or missing in this document, create an issue in one of those issue queue components and assign it
to one of us! 😊 🙏

## 1. Terminology

### 1.1 Existing Drupal Terminology that is crucial for XB

- `computed field prop`: not every `field prop` has their value _stored_, some may have their value _computed_ (for example: the `file_uri` field type's `url` prop)
- `base field`: a `field instance` that exists for _all_ bundles of an entity type, typically defined in code
- `bundle field`: a `field instance` that exists for _some_ bundles of an entity type, typically defined in config
- `content entity`: an entity that can be created by a Content Creator, containing various `field`s, potentially including the `XB field type`, of a particular entity type (e.g. "node")
- `content type`: a definition for content entities of a certain entity type and bundle, and hence every `content entity` of this bundle is guaranteed to contain the same `bundle field`s
- `data type`: Drupal's smallest unit of representing data, defines semantics and typically comes with validation logic and convenience methods for interacting with the data it represents ⚠️ Not all data types in Drupal core do what they say, see `\Drupal\experience_builder\Plugin\DataTypeOverride\UriOverride` for example. ⚠️
- `field`: synonym of `field item list`
- `field prop`: a property defined by a `field type`, with a value for that property on such a `field item`, represented by a `data type`. Often a single prop exists (typically: `value`), but not always (for example: the `image` field type: `target_id`, `entity`, `alt`, `title`, `width`, `height` — with `entity` a `computed field prop`)
- `field instance`: a definition for instantiating a `field type` into a `field item list` containing >=1 `field item`
- `field item`: the instantiation of a `field type`
- `field item list`: to support multiple-cardinality values, Drupal core has opted to wrap every `field item` in a list — even if a particular `field instance` is single-cardinality
- `field type`: metadata plus a class defining the `field prop`s that exist on this field type, requires a `field instance` to be used
- `field widget`: a class that uses Form API to specify the editing UX for a `field type`
- `SDC`: see [`XB Components` doc](components.md)
- `view mode`: view modes lets a `content entity` be displayed in multiple ways

### 1.2 XB terminology

- `component`: see [`XB Components` doc](components.md)
- `component instance`: a UUID uniquely identifying this instance + component + values for each required `component prop` (if any) + optionally values for its `component slot`s (if any)
- `component prop`: see [`XB Components` doc](components.md)
- `component slot`: see [`XB Components` doc](components.md)
- `component tree`: a tree of `component instance`s, by placing >=1 `component instance`s in a particular order in another `component instance`'s slot
- `component tree field type`: XB's field type that allows storing a `component tree` ⚠️ This is currently limited to the "default" `view mode`, and hence one component tree per `content entity`. ⚠️
- `component tree root`: the root of the `component tree` is the special case: it does not exist in another `component`, but it behaves the same as any other `component slot`
- `component type`: see [`XB Components` doc](components.md)
- `conjured field`: a `field instance` that is not backed by code nor config, but generated dynamically to edit/store a value for a `component prop` as `unstructured data`
- `content type template`: see [`XB Config Management` doc](config-management.md).
- `layout`: synonym of `component tree`
- `prop expression`: a (compact) string representing what context (entity type+bundle or field type) is required for retrieving one or more properties stored inside of that context; also has a typed PHP object representation to facilitate logic
- `prop shape`: see [`XB Components` doc](components.md)
- `prop source`: a source for retrieving a prop value
  - `static prop source`: a `prop source` powered by a `conjured field` (i.e. `unstructured data`)
  - `dynamic prop source`: a `prop source` powered by a `base field` or `bundle field` (i.e. `structured data`)
  - TBD: `remote prop source`: a `prop source` powered by a remote source ("external data"), i.e. data stored outside Drupal
- `structured data`: the data model defined by a Site Builder in a `content type`, and whose smallest units are `field props` — queryable by Views
- `unstructured data`: the ad-hoc data used to populate `component prop`s that are not populated using `unstructured data` — NOT queryable by Views, this should be minimized/discouraged
- `XB field`: an instance of the `component tree field type`
- `XB field type`: see `component tree field type`

## 2. Product requirements

This uses the terms defined above.

This adds to the product requirements listed in [`XB Components` doc](components.md) and [`XB Config Management` doc](config-management.md).

(There are [more](https://docs.google.com/spreadsheets/d/1OpETAzprh6DWjpTsZG55LWgldWV_D8jNe9AM73jNaZo/edit?gid=1721130122#gid=1721130122), but these in particular affect XB's data model.)

- MUST have validation logic that generates consistent validation error messages for either content (a `component tree` created by the Content Creator and stored in a `content entity`) or config (a `component tree` created by the Site Builder and stored in a `content type template`)
- MUST allow continuing to use existing Drupal functionality (notably: `field type`s and `field widget`s)
- SHOULD encourage Content Creators to use `structured data` whenever possible, `unstructured data` should be minimized except where necessary
- MUST be able to facilitate changes in `component prop`s (i.e. schema changes, that may result in a changed `prop shape`)
- MUST support both symmetric and asymmetric translations (same vs different `layout` per translation, respectively)
- SHOULD facilitate real-time collaborative editing

## 3. Implementation

This uses the terms defined above.

### 3.1 Data Model: from Front-End Developer to an XB data model that empowers the Content Creator

Given a component developed by a [Front-End Developer](diagrams/structurizr-SystemContext-001.md): how does XB allow a
Content Creator to place a `component instance` in the `component tree`, specify values for the `component prop`s and
`component slot`s?

#### 3.1.1 Interpreting `component`s: `prop shapes`

See `\Drupal\experience_builder\PropShape\PropShape`.

The `component slot`s part is simple: any other `component instance` can be placed there (⚠️this will change, see
product requirements).

The `component prop` part is complex: each `component prop` has a schema (⚠️ this is true for `SDC`, but TBD how this
will be handled for other component types, see product requirements) that defines the primitive type (string, number,
integer, object, array or boolean), with typically additional restrictions (e.g. a  string containing a URI vs a date,
or an integer in a certain range). That primitive type plus additional restrictions identifies a unique `prop shape`.

#### 3.1.2 Finding fitting `field type`: `conjured field`s and `field instance`s

Per the product requirements, existing `field type`s and `field widget`s MUST be used, and ideally `structured data`
SHOULD be used.  But `field type`s can be configured, and depending on the configured settings, they may support rather
different `prop shape`s. For example: Drupal's "datetime" `field type` can, depending on settings, store either:

- date only
- date and time

So, the settings for a `field type` are critical: a `field type` alone is insufficient. How can `XB` determine the
appropriate field settings for a `prop shape`? And what about existing `structured data` versus `unstructured data`?

⚠️ _Why even have `unstructured data`? Why not create `structured data` to populate all `component props`?_, you might
ask. Because:

- a `component tree` likely changes significantly — with specific `component`s being used at some time, but not another
- `structured data` requires `base field`s or `bundle field`s, and once in use, they cannot be removed
- therefore capturing all values for `component prop`s as `structured data` would cause many new `bundle field`s to be
  created that may shortly thereafter no longer be used
- plus, not all `component prop`s contain meaningful information to query — many contain purely _presentational_
  information such as the width of a column, the icon to use, et cetera
- in other words: `component prop`s should be populated by `structured data` if they contain _semantical_ information,
  otherwise it is _presentational_ information and hence `unstructured data` is more appropriate

##### 3.1.2.a `structured data` → matching `field instance`s ⇒ `dynamic prop source`

See:
- `\Drupal\experience_builder\ShapeMatcher\SdcPropToFieldTypePropMatcher`
- `\Drupal\experience_builder\JsonSchemaInterpreter\SdcPropJsonSchemaType::toDataTypeShapeRequirements()`

All `structured data` in every `content entity` in Drupal is found in `base field`s and `bundle field`s. These already
have field settings defined. Hence `XB` must **match** a `field instance` for a given `prop shape`.

How can this reliably be matched? Drupal's validation constraints for `field type`s and `data type`s determine the
precise shapes of values that are allowed … exactly like a `prop shape` (i.e. the JSON schema for a `component prop`)!

Hence the matching works like this:
1. transform the JSON schema of a `prop shape` to the equivalent primitive `data type` + validation constraints (see
   `SdcPropJsonSchemaType::toDataTypeShapeRequirements()`)
2. iterate over all `field instance`s in the site, and compare the previous step's `data type` + validation constraints
   to find a match

Finally, while the `prop shape` may be the same for many `component prop`s, that same `prop shape` may be required for
one `component`'s `component prop`, but optional for another. So an additional filtering step is required for optional
versus required occurrences of a `prop shape`:
3. if a `component prop` is required, the matching `field instance`s must also be marked as required

The found `field instance` can then be used in a `dynamic prop source`, that can be _evaluated_ to retrieve the stored
value that fits in the `prop shape`.

See `\Drupal\experience_builder\PropSource\DynamicPropSource`.

⚠️ **Multiple** bits of `structured data` may be able to fit into a given `prop shape`. All viable choices are
suggested by `\Drupal\experience_builder\ShapeMatcher\FieldForComponentSuggester`. The Content Creator or Site Builder
will choose one.

##### 3.1.2.b `unstructured data` → generating `conjured field`s ⇒ `static prop source`

See:
- `\Drupal\experience_builder\JsonSchemaInterpreter\SdcPropJsonSchemaType::computeStorablePropShape()`
- `\Drupal\experience_builder\PropShape\StorablePropShape`
- `hook_storage_prop_shape_alter()`

For any `unstructured data`, no field settings exist yet, so the appropriate settings for a `prop shape` must be
generated. `SdcPropJsonSchemaType::computeStorablePropShape()` contains logic to that relies only on `field type`s
available in Drupal core. Unlike for `structured data`, no additional complexity is necessary for required versus
optional `component prop`s.

Contributed modules can implement `hook_storage_prop_shape_alter()` to make different choices.

The computed `\Drupal\experience_builder\PropShape\StorablePropShape` can be used to create a `static prop source`
(which contains all information for the `conjured field` that powers it), that can be _evaluated_ to retrieve the stored
value that fits in the `prop shape`.

See `\Drupal\experience_builder\PropSource\StaticPropSource`.

⚠️ When choosing to use `unstructured data` to populate a `component prop`, XB decides
using the aforementioned logic what `field type`, `field widget` et cetera to use. Only when using `structured data`,
there is a need for an additional choice (see the `FieldForComponentSuggester` mentioned in 3.1.2.a).

#### 3.1.3 `prop expression`s: evaluating a `dynamic prop source` or `static prop source`

See
- `\Drupal\experience_builder\PropExpressions\StructuredData\Evaluator`
- `\Drupal\experience_builder\PropExpressions\StructuredData\StructuredDataPropExpressionInterface`
- `\Drupal\experience_builder\PropExpressions\StructuredData\FieldPropExpression`
- `\Drupal\experience_builder\PropExpressions\StructuredData\FieldTypePropExpression`
- `\Drupal\Tests\experience_builder\Unit\PropExpressionTest`

Many `field type`s contain a single `field prop` (typically named "value"), but not all. Most `field type`s have one
required "main prop", many have additional optional props or even computed props.

To reliable retrieve the value from a `static prop source` or `dynamic prop source`, the `field item` alone is
insufficient: `XB` needs to know exactly which `field prop`(s) to retrieve from a `field item`. Plus, it may need to
arrange those retrieved values in a particular layout (for `prop shape`s that use the "object" primitive type the right
key-value pairs must be assembled).

To express that, `prop expression`s exist, which define:

1. what context they need: a `field item` of a certain `field type`, or a `content entity` of a certain `content type`
2. what `field prop`s they must retrieve in that context, possibly following entity references
3. what the resulting shape is: either a single value (typically) or a list of key-value pairs — in the latter case the
   expected keys are specified also

`prop expression`s have 2 representations:

- a string representation, to simplify both debugging and storing them (both of those benefit from terseness) — to
  convert to the other representation: `StructuredDataPropExpression::fromString()`)
- a typed PHP object representation, to simplify logic interacting with them — to convert to the other representation:
  cast to string using `(string)`)

Examples:
- `ℹ︎␜entity:node:article␝title␞99␟value` declares it evaluates an "article" `content entity`, and returns the "value"
  prop of the 100th `field item` in the "title" `field`
- `ℹ︎image␟{src↝entity␜␜entity:file␝uri␞␟url,alt↠alt}` declares it evaluates an "image" `field item`, and returns
  two key-value pairs:
  - the first one being "src" for which the first "url" `field prop` of the "uri" `field` on the "file"
    `content entity` that is referenced by the "image" `field type`
  - the second one being "alt", which can be retrieved directly from the "image" `field item`

For more examples, see `\Drupal\Tests\experience_builder\Unit\PropExpressionTest`.

### 3.2 Data Model: storing a component tree

See `\Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem` + its validation constraint.

XB defines a new `XB field type` with two `field prop`s, that each have their own `data type`:
- _tree_ — see 3.2.1
- _props_ — see 3.2.2

Storing this as two separate `field prop`s simplifies supporting both symmetric and asymmetric translations:
- the _props_ `field prop` SHOULD always be translatable
- the _tree_ `field prop` can be either:
  1. marked translatable for _asymmetric translations_ (a different `component tree` per `content entity` translation)
  2. marked untranslatable for _symmetric translations_ (same `component tree` for all `content entity` translations)

(Drupal's Content Translation module natively supports configuring this.)

#### 3.2.1 The `field prop` storing the tree structure

See `\Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure` + its validation constraint.

The `component tree`'s _tree_ `field prop` has a representation that minimizes nesting, which simplifies both validation
as well as changes to `component prop`s (simpler JSON querying).

The _tree_ `field prop` is stored as a JSON blob, and always meets the following requirements:
1. the root UUID is present
2. any top-level UUID appears as a UUID in a _parent branch/tree_ (except its own branch), _if_ the `component` for that
   UUID (referring to a `component instance`) is a component that has >=1 `component slot`s
3. `component slot`s of every `component`: the stored slot names (`firstColumn` and `secondColumn` in the example below)
   MUST be actually existing slot names for this particular `component`
4. the ordering in each array (the component instances under the root UUID and under each slot name) is meaningful,
   because the `component instance`s are positioned in a particular order

Example:
```json
{
  "ROOT_UUID": [
    {"uuid": "uuid-root-1", "component": "provider:two-col"},
    {"uuid": "uuid-root-2", "component": "provider:marquee"},
    {"uuid": "uuid-root-3", "component": "provider:marquee"}
  ],
  "uuid-root-1": {
    "firstColumn": [
      {"uuid": "uuid4-author1", "component": "provider:person-card"},
      {"uuid": "uuid2-submitted", "component": "provider:elegant-date"}
    ],
    "secondColumn": [
      {"uuid": "uuid5-author2", "component": "provider:person-card"}
    ]
  },
  "uuid-root-2": {
    "content": [
      {"uuid": "uuid4-author3", "component": "provider:person-card"}
    ]
  }
}
```

#### 3.2.2 The `field prop` storing the props values

See `\Drupal\experience_builder\Plugin\DataType\ComponentPropsValues`.

_This uses 3.1._

The `component tree`'s _props_ `field prop` has a trivial representation that could easily change. It is stored as a
JSON blob, and meets the following requirements:
1. it contains a list of key-value pairs, with keys corresponding to `component instance`s and values containing JSON
   representations of `prop source`s keyed by the component prop name
2. order is irrelevant everywhere — meaning moving a `component instance` to a different location in the
   `component tree` requires only changes to _tree_, not to _props_

Note: this simplifies different (symmetric) translation strategies: it's trivial to either reuse another translation's
_props_ `field prop` (to show what to translate from) or not reuse anything at all — that needs only array intersection.

Note: a welcome bonus is that when real-time collaborative editing is eventually added, one user can move a
`component instance` while another edits the _props_ of that same `component instance`, without causing a conflict.

No validation is necessary for this `field prop`: all required `component props` need a `prop source`, and the
`prop source`s must resolve. Both are more easily validated at the `field item` level of the `XB field type`, not at the
`field prop` level.

Example, that populates only the two "marquee" `component instance`s in the _tree_ example above. It uses one
`static prop source` and one `dynamic prop source`.
```json
{
  "uuid-root-2": {
    "text": {
      "sourceType": "dynamic",
      "expression": "ℹ︎␜entity:node:article␝title␞␟value"
    }
  },
  "uuid-root-3": {
    "text": {
      "sourceType": "static:field_item:string",
      "value": "Hello, world!",
      "expression": "ℹ︎string␟value"
    }
  }
}
```

(Note that here the string representation of a `prop expression` is used, which allows more compact storage; when logic
interacts with these, it will transform them to the typed PHP object representation first.)

### 3.2.3 Validation

Assuming the _tree_ `field prop` has already been validated, a `component tree` described in an `XB field` then is valid
when: for each `component instance` in the _tree_ `field prop`:
1. a counterpart  in the _props_ `field prop` exists (the `component instance`'s UUID is an existing key)
2. resolving the stored `prop source`s, resulting in values to be passed to the corresponding `component prop`s
3. the `SDC` infrastructure's `\Drupal\Core\Theme\Component\ComponentValidator::validateProps()` does not throw an exception

### 3.2.4 Facilitating `component props` changes

When a `component` evolves, _some_ `component props` cannot happen without also updating the stored `component tree`. In
other words: an upgrade path is necessary if a Front-End Developer makes certain drastic changes:
- renaming a `component prop`
- changing the schema of a `component prop`
- adding a new _required_ `component prop`

Here too, storing the _tree_ and _props_ as separate `field props` is helpful. An upgrade path for a `component` would
require logic somewhat like this:

1. SQL query to search the _tree_ JSON blob for uses of this `component`, capture the UUIDs. If 0 matches: break.
2. If >0 matches, PHP logic computes the necessary changes.
3. Insert the updated _props_ JSON blob.

The above sequence assumes doing this per-entity. But this can actually be done _per entity-type_, or more precisely:
per `XB field`. So if the `XB field type` is only used for one entity type but is used in many bundles (i.e. many
different `content entity type`s), then a single query can find all `component instances` of the evolving `component`.
After that point, the typical Drupal update path best practices apply. The key observation here: it is possible to
efficiently find all uses of a `component`.

### 3.3 Data Model: rendering a stored `component tree`

See `\Drupal\experience_builder\Plugin\DataType\ComponentTreeHydrated`.

_This uses 3.2.1, 3.2.2 and 3.2.3._

Thanks to the validation in 3.2.3, it is guaranteed that each individual `component instance` _can_ be rendered. But the
goal is of course to render a `component tree` (not `component instance`s), by starting at the root and rendering each
`component instance` in the specified `component slot`.

To hydrate the stored `component tree`:
1. resolve the `prop source`s stored in the _props_ `field prop` (3.2.2) for each `component instance`, resulting in a
   list of renderable `component instance`s
2. transform that list to a tree by respecting the _tree_ `field prop` (3.2.1), by placing nested `component instance`s
   in the specified `component slot` of the specified parent `component instance` (special case: the root)

To render the stored `component tree`, it must first be hydrated it (see above), after which it can be
converted to a render array.
