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
- `field type`: see [`XB Data Model` doc](data-model.md)
- `field widget`: see [`XB Data Model` doc](data-model.md)

### 1.2 XB terminology

- `component`: see [`XB Components` doc](components.md)
- `Component config entity`: `component`s available for use in XB are tracked as config entities. They correspond 1:1 to `SDC`s.
- `component prop`: see [`XB Components` doc](components.md)
- `component slot`: see [`XB Components` doc](components.md)
- `component type`: see [`XB Components` doc](components.md)
- `component tree`: see [`XB Data Model` doc](data-model.md)
- `content type template`: the default `component tree` for a particular `content type`, which typically includes assigning the smallest units of `structured data` to particular `component prop`s, and uses `configuration entity dependencies` to ensure the necessary `component`s are present
- `structured data`: see [`XB Data Model` doc](data-model.md)
- `unstructured data`: see [`XB Data Model` doc](data-model.md)

## 2. Product requirements

This uses the terms defined above.

This adds to the product requirements listed in [`XB Components` doc](components.md).

(There are [more](https://docs.google.com/spreadsheets/d/1OpETAzprh6DWjpTsZG55LWgldWV_D8jNe9AM73jNaZo/edit?gid=1721130122#gid=1721130122), but these in particular affect XB's supported components.)

- MUST be able to synchronize `component`s and `content type template`s from one site to another WITHOUT changes to Drupal deployment best practices
- MUST support auditability, assuming (to answer questions such as: which `field type` and `field widget` does a `component` use when it is instantiated, why is a given `SDC` not available as a `component` in XB, et cetera)


## 3. Implementation

This uses the terms defined above.


### 3.1 `Component config entity`

See:
- `\Drupal\experience_builder\Entity\Component`
- `\Drupal\experience_builder\Plugin\ComponentPluginManager`

One `Component config entity` is [automatically created (and updated](https://www.drupal.org/project/experience_builder/issues/3463999)
per `component` that is present and meets the criteria (see [`XB Components` doc, section 3.1.1](components.md#3.1.1)).

When a `component` does not meet the criteria, the _reasons_ for that are tracked and presented in the UI.

The `Component` config entity contains:
- the `component` ID (currently always prefixed with `sdc+` because it only supports `SDC`-powered `component`s, but that will change, see [`XB Components` doc, section 3.2](components.md#3.2))
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


#### 3.2 Other configuration entities

Nothing yet, this will change when support for [`content type template`s is added later](https://www.drupal.org/project/experience_builder/issues/3455629)
