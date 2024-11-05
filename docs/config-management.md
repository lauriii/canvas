# Experience Builder Config Management

In the rest of this document, `Experience Builder` will be written as `XB`.

This builds on top of the [`XB Components` doc](components.md). Please read that first.

It also refers to the [`XB Data Model` doc](data-model.md), which itself refers back to this one for a few things.
The configuration architecture is designed to serve/facilitate the data model.

**Also see the [diagram](diagrams/data-model.md).**

## Finding issues 🐛, code 🤖 & people 👯‍♀️
Related XB issue queue components:
- [Config management](https://www.drupal.org/project/issues/experience_builder?component=Config+management)

That issue queue component also has corresponding entries in [`CODEOWNERS`](../CODEOWNERS).

If anything is unclear or missing in this document, create an issue in one of those issue queue components and assign it
to one of us! 😊 🙏

## 1. Terminology

### 1.1 Existing Drupal Terminology that is crucial for XB

- `configuration entity dependencies`: [configuration entities may declare dependencies on modules, themes or another config entity](https://www.drupal.org/docs/drupal-apis/configuration-api/configuration-entity-dependencies)
- `configuration validation`: the ability to [thoroughly validate](https://www.drupal.org/project/drupal/issues/2164373) configuration
- `SDC`: a [Single-Directory Component](https://www.drupal.org/project/sdc)
- `Block`: a [block plugin](https://www.drupal.org/docs/drupal-apis/block-api/block-api-overview) — ⚠️ not to be confused with the identically named config entities!
- `field type`: see [`XB Data Model` doc](data-model.md)
- `field widget`: see [`XB Data Model` doc](data-model.md)
- `page template`: a Drupal theme's template in which every `theme region` is rendered
- `page.html.twig`: see `page template`
- `PageDisplayVariant`: Drupal is architected to allow multiple implementations to decorate/lay out the _main content_
  that is  computed by a route's controller. Such implementations are `PageDisplayVariant` plugins.
- `theme region`: a Drupal theme exposes multiple regions to Drupal, to render things (historically: "blocks") into; the
  surrounding markup is defined in the Drupal theme's `page.html.twig`. This is conceptually identical to
  `component slot`s.

### 1.2 XB terminology

- `component`: see [`XB Components` doc](components.md)
- `Component config entity`: `component`s available for use in XB are tracked as config entities. They correspond 1:1 to eligible
  `SDC`s and `Block`s.
- `Component Source Plugin`: `component`s have a translation layer (per `component type`) between the `Component` config entity and the actual plugin that
  generates output, e.g. `SingleDirectoryComponent` (`sdc`-prefixed) and `BlockComponent` (`block`-prefixed).
- `component prop`: see [`XB Components` doc](components.md)
- `component slot`: see [`XB Components` doc](components.md)
- `component type`: see [`XB Components` doc](components.md)
- `component tree`: see [`XB Data Model` doc](data-model.md)
- `content type template`: the default `component tree` for a particular `content type`, which typically includes assigning the smallest units of `structured data` to particular `component prop`s, and uses `configuration entity dependencies` to ensure the necessary `component`s are present
- `PageTemplate config entity`: stores a `component tree` for every `theme region` in a given Drupal theme
- `structured data`: see [`XB Data Model` doc](data-model.md)
- `unstructured data`: see [`XB Data Model` doc](data-model.md)

## 2. Product requirements

This uses the terms defined above.

This adds to the product requirements listed in [`XB Components` doc](components.md).

(There are [more](https://docs.google.com/spreadsheets/d/1OpETAzprh6DWjpTsZG55LWgldWV_D8jNe9AM73jNaZo/edit?gid=1721130122#gid=1721130122), but these in particular affect XB's supported components.)

- MUST be able to synchronize `component`s and `content type template`s from one site to another WITHOUT changes to Drupal deployment best practices
- MUST be able to populate a `theme`'s `page template` using XB `component`s
- MUST support auditability, assuming (to answer questions such as: which `field type` and `field widget` does a `component` use when it is instantiated, why is a given `SDC` not available as a `component` in XB, et cetera)


## 3. Implementation

This uses the terms defined above.

A HTTP API is provided to list, read, create, update and delete _some_ of these config entities. This HTTP API is
designed to only be used by XB's (client-side) UI.

XB intentionally does not use Drupal core's [JSON:API module](https://jsonapi.org/), because:
-  requiring the Drupal JSON:API module to be installed is excessive
-  XB's HTTP API does not need pagination support
-  XB tracks all available Components as config entities, but those actually do not need to be exposed in full; there's
   no need to modify them from the  client-side UI, and there already is the `/xb-components` controller for that which
   enriches it with additional metadata, matching the UI's needs
- XB's HTTP API does not need to surface relationships between XB's config entities — that mostly makes sense for
  _content entity_ relationships (i.e. "entity references")

See the `experience_builder.api.config.*` routes.


### 3.1 `Component config entity`

See:
- `\Drupal\experience_builder\Entity\Component`
- `\Drupal\experience_builder\Plugin\ComponentPluginManager`

One `Component config entity` is [automatically created (and updated](https://www.drupal.org/project/experience_builder/issues/3463999)
per `component` that is present and meets the criteria (see [`XB Components` doc, section 3.1.1](components.md#3.1.1)).

When a `component` does not meet the criteria, the _reasons_ for that are tracked and presented in the UI.

The `Component` config entity contains:
- the `component` ID, with the prefix (the first ID part) identifying the `Component Source Plugin`, and the
  remainder being used by that plugin (typically to allow a `Component Source Plugin` to provide >1 `component`)
- the `source`: a `Component Source Plugin` ID. ⚠️ This will eventually become extensible; currently only `sdc` or `block`.
- the `settings`: each `Component Source Plugin` MAY need to store component settings, and each has different needs:
  - `SDC` component type: `props`, to configure what field type, widget and so on to use to store and edit the SDC's props.
  - `Block` component type: `settings`, to store block settings, if any. For example: which menu to display in a menu block.
- the `status`: `true` conveys it is available for XB Content Creators, `false` conveys it once was available, but not
  anymore (either because it was explicitly disabled by the Site Builder, or because the underlying SDC was marked as
  "obsolete"). Existing content can then continue to use disabled `Component`s (in other words: nothing breaks), while
  new content must use the most current Site Builder-curated list of `Component`s.
- which `field type` and `field widget` must be used to populate it with `unstructured data` — for algorithmic details,
 see [`XB Data model`, section 3.1: "from Front-End Developer to an XB data model that empowers the Content Creator](./data-model.md#3.1)
- `config entity dependencies` on the modules providing the `field type` and `field widget`

These config entities are therefore the foundations that enable XB to work reliably, and allow:
- auditing (listing which components are available to XB and reasons why components are unavailable, tracking changes in
  computed `field type` and `field widget` for a component prop — see [`XB Data model`, section 3.1.2.b](./data-model#3.1.2.b))
- dependency-checking (this config entity's dependencies on other modules, as well as other config entities depending on
  this config entity, but also ensuring the necessary code is present, such as `field type` and `field widget` plugins)
- exporting, importing, synchronizing from one environment or site to another
- validating: the ability to be 100% confident that all dependencies are satisfied, and all criteria are still met (see
  [`XB Components` doc, section 3.1.1](components.md#3.1.1)).

UI routes:
- available `component`s: `/admin/structure/component`
- unavailable `component`s: `/admin/structure/component/status`


### 3.2 `PageTemplate config entity`

See:
- `\Drupal\experience_builder\Entity\PageTemplate`
- `\Drupal\experience_builder\Plugin\DisplayVariant\PageTemplateDisplayVariant`

One `PageTemplate config entity` may be created per Drupal theme. This allows using XB instead of the Block module's
"Block Layout" functionality (at `/admin/structure/block`) to populate the `theme region`s of the Drupal theme's
`page.html.twig`.

⚠️ This means it is currently not possible to have a a different `PageTemplate config entity` per route/URL/…, which the
"Block Layout" functionality solved using "visibility conditions". This will be covered in the future by XB's product
requirement [`41. Conditional display of components`](https://docs.google.com/spreadsheets/d/1OpETAzprh6DWjpTsZG55LWgldWV_D8jNe9AM73jNaZo/edit?gid=1721130122#gid=1721130122&range=B53),
which will be XB's generalized equivalent to Drupal core's Block module's "visibility conditions".

⚠️ This means it is currently not possible to have a draft/non-live `PageTemplate config entity` (just like is the case
for "Block Layout" functionality). This will be covered in the future by XB's product requirements [`37. Revisionable templates`](https://docs.google.com/spreadsheets/d/1OpETAzprh6DWjpTsZG55LWgldWV_D8jNe9AM73jNaZo/edit?gid=1721130122#gid=1721130122&range=B49)
and [`55. Workspaces`](https://docs.google.com/spreadsheets/d/1OpETAzprh6DWjpTsZG55LWgldWV_D8jNe9AM73jNaZo/edit?gid=1721130122#gid=1721130122&range=B62).

Once a theme has an XB `PageTemplate config entity` defined, it overrides the block layout (if any).
Strict validation is imposed on a `PageTemplate config entity`, to ensure that essential information is displayed as
expected:
1. exactly one `component` is present that implements `MainContentBlockPluginInterface`, to ensure the content of the
   route controller is displayed on the page
2. exactly one `component` is present that implements `TitleBlockPluginInterface`, to ensure the title of the route
   controller is displayed on the page
2. exactly one `component` is present that implements `MessagesBlockPluginInterface`, to ensure messages are displayed
   on the page

That means that when this is used, the Block module is in principle unnecessary. However, Drupal admin themes typically
rely on the Block module to provide the intended administrative User Experience, which makes that impractical.

See `\Drupal\block\Plugin\DisplayVariant\BlockPageVariant`.

⚠️ Still to be built:
- a UI to configure `PageTemplate` config entities
- support for blocks-as-components in general


### 3.3 Other configuration entities

Nothing yet, this will change when support for [`content type template`s is added later](https://www.drupal.org/project/experience_builder/issues/3455629)
