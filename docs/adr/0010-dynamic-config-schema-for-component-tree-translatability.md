# 10. Symmetrical translations of config-defined component trees: use dynamic config schema per component instance

Date: 2026-04-22

Issue: <https://www.drupal.org/project/canvas/issues/3582478>

## Status

Accepted

## Context

Canvas component trees are stored in both content entities (Canvas `Page`, core's `Node`) and config entities (Content Templates, Patterns). Content entity translation uses Drupal's `content_translation` system with column groups on the `ComponentTreeItem` field type. This already works since August 2024 ([#3454257](https://www.drupal.org/project/experience_builder/issues/3454257)) and was evolved when the Canvas field type was rearchitected per [ADR #6](0006-One-field-row-per-component-instance.md). It does NOT yet support per-component-instance translatability of actually translatable inputs ([#3583684](https://www.drupal.org/project/canvas/issues/3583684)).

Each component type has a different set of inputs with different types. SDC components have JSON Schema-derived props. Block components have block plugin settings. Code components have explicitly defined props. There is no single static schema that can describe all possible component inputs.

Furthermore, translatability is not just shape-dependent but also value-dependent: the same SDC prop (e.g. `heading`, `type: string`) may be populated by a `StaticPropSource` (content author typed "Hello world" — translatable) or by an `EntityFieldPropSource` (linked to a field on the host entity — NOT translatable, because the field's own translation handles it).

### 1. `type: ignore` is incompatible with how "language config overrides" work

Config translations are implemented by Drupal core as `LanguageConfigFactoryOverride`, using the "config override" system.

This is based on _property paths_ in config entities, and merging the overrides (for languages or e.g. domain-specific overrides) with the default config.

Because Canvas' config-defined component instances' `inputs` were defined as `type: ignore` (because the shape is dynamic and not known at schema definition time), `LanguageConfigOverride`s could not selectively target individual inputs of a component instance.

`type: ignore` meant the entire `inputs` value was stored and loaded as a JSON blob — a scalar string from the perspective of the config system, not a PHP array. Config overrides work by merging at property paths; that requires a tree of arrays, not a blob. In other words: as long as this remained `type: ignore`, the only way to override component instance inputs was to override the entire `inputs` blob.

### 2. `type: ignore` is incompatible with how `config_translation` and TMGMT discover translatable config properties

Config entity translation uses Drupal core's `config_translation` module, which is entirely schema-driven: only config schema elements with `translatable: true` are offered for translation. The translation pipeline relies on the typed config schema to discover and save translatable values:
- `config_translation`: `\Drupal\config_translation\ConfigMapperManager::findTranslatable()` requires `translatable: true` to decide which config (simple or entity) is translatable, after that `ConfigTranslationFormBase::createFormElement()` is called recursively by `ListElement` to generate the appropriate form (relying on `form_element_class`); when writing translations back, `ListElement::setConfig()` relies on the same schema to know how to write the translated value back to the correct place in the config structure, and (crucially!) omit any non-translatable values (e.g. a `type: string, format: date-time` SDC prop or a block component's `label_display` setting)
- [TMGMT](https://drupal.org/project/tmgmt)'s `DefaultConfigProcessor::extractTranslatables()`also relies on `translatable: true`

If the entire `inputs` blob is `type: ignore`, individual inputs are not even discoverable, let alone being able to mark specific ones as translatable.

### 3. Validation

A related problem is the [12-year old core bug #2270399](https://www.drupal.org/project/drupal/issues/2270399): `LanguageConfigOverride`s are **never validated!** `LanguageConfigOverride::save()` only checks that values are scalars, arrays, or `NULL` — not objects. There is no schema validation, no constraint validation, and no check that translated values are structurally compatible with the base config. `ConfigSchemaChecker` (used in tests) explicitly skips non-default collections. `ConfigTranslationFormBase` has no `validateForm()` method. Even `Config::setOverriddenData()` at read time does a plain `NestedArray::mergeDeepArray()` with no validation. The only safeguard is `ConfigFactoryOverrideBase::filterOverride()`, which prunes override keys that no longer exist in the base config — but does not validate override values. For Canvas, this matters acutely: every component instance input depends on a specific component type and version, making malformed overrides far more likely to cause runtime breakage than for ordinary core config entities.

### 4. Possible approaches

Three alternative approaches were evaluated:

1. **Keep `type: ignore` + custom `LanguageConfigFactoryOverrideInterface` implementation + custom `FormElement` classes**: custom factory has complex maintainability and ecosystem compatibility risks, would require lots of custom code in both core's `config_translation` (would require a super complex `FormElement` class) and contrib's `tmgmt` (would require a custom `ConfigProcessorInterface` implementation). Rejected: enormous amount of work, high risk of breakage.
2. **Canvas Workbench UI**: a separate translation interface outside Drupal's existing infrastructure. Rejected: all translation activity must happen within a live Drupal site.
3. **Drop `type: ignore`**: dynamic schema with a single custom `FormElement` class (`CanvasStaticPropSourceFieldWidget`) that reuses Canvas's field widget infrastructure for all SDC/code component prop shapes, while block component instance translatability is reused exactly as it is for blocks in core.

## Decision

### 1. Allow any `ComponentSource` plugin to define a `ComponentInstanceInputsConfigSchemaGeneratorInterface`

Such `inputs_config_schema_generator`s handle generating a config schema for component instances, which enables both predictable:
- config exports
- (config) translation support for component instances' inputs

The default/fallback implementation MUST be able to provide predictable config exports for any component source plugin automatically. Automatic translation support is impossible, because that requires both understanding how that source stores its explicit inputs and generating an editing UX, which may require a custom Configuration Translation `form_element_class`.

### 2. Config-defined component instances' `inputs` need a dynamic mapping

Use Drupal's config schema `class` property to resolve `inputs` dynamically at runtime:

```yaml
inputs:
  type: mapping
  class: '\Drupal\canvas\Config\Schema\ComponentInputsMapping'
```

`ComponentInputsMapping extends Mapping` is the sole composer of the final config schema mapping. It generates its mapping definition in the constructor by:

1. Reading the parent `canvas.component_tree_node` value to obtain `component_id`, `component_version`, and the actual `inputs` values.
2. Loading the referenced Component version (a `Component` config entity, with the correct version of it loaded)
3. Loading the `component source` for that Component version
3. Instantiating `component source`'s `inputs_config_schema_generator` (a `ComponentInstanceInputsConfigSchemaGeneratorInterface` implementation)
4. Calling a method on that generator to determine the default config schema mapping definition for that Component version; any input that is translatable needs to either specify config schema types (such as `type: string`, `type: label`, etc.), or use `type: ignore` for complex types. In that case, `translatable: true` should be set on those that are translatable, along with a `form_element_class` to let the Drupal core Configuration Translation UI generate a translation UI.
5. Calling another method on that generator to refine that mapping definition based on the actual input values of the component instance, to:
  - apply value-based translatability (e.g. if the value is a `StaticPropSource`, mark it translatable; if it's an `EntityFieldPropSource`, mark it non-translatable)
  - inject metadata into the data definition that may be needed for the custom `form_element_class`, if any

This is prior art in Canvas: `JsonSchemaObject` (used for code component prop examples in `Component` config entities) already uses the same `class:` mechanism to generate dynamic config schema.

### 3. Symmetrical translations of content-defined component trees should use the same infrastructure

Solving content translation is out of scope for this ADR. However, it is important that content entity translation does not compute translatability using different logic. That would create a risk of divergence.

`content_translation` at minimum needs to know the subset of inputs that are translatable, to:
1. validate that translations only override translatable inputs
2. synchronize non-translatable inputs from the default translation

Therefore, `ComponentTreeItem`'s `inputs` field property MUST provide a public method such as `::getTranslatableInputKeys()` that allows enumerating which key-value pairs in `inputs` are translatable. In other words: it MUST provide a simplified view of what `ComponentInstanceInputsConfigSchemaGeneratorInterface` provides: the full detail of that is only necessary for config translation.

### 4. Default: `FallbackComponentInstanceInputsConfigSchemaGenerator` considers all inputs non-translatable by default

`FallbackComponentInstanceInputsConfigSchemaGenerator` must generate a correct and complete mapping based on `getDefaultExplicitInput()` — every input key is typed as `type: ignore` (not translatable). New component source plugins that do not provide their own `ComponentInstanceInputsConfigSchemaGeneratorInterface` automatically use this fallback generator. They will get an config schema describing the different explicit inputs automatically, just without any them becoming translatable.

### 5. Simple case: `BlockComponent` reuses existing block plugin's config schema

`BlockComponentInstanceInputsConfigSchemaGenerator` must forward the actual `block.settings.<plugin_id>` config schema mapping, inheriting native block translatability (e.g. `label` is `type: label` and translatable; `label_display` is `type: string` and not).

Note: _every_ block plugin has a translatable `label` setting, because every block plugin is forced to have that setting thanks to the `block.settings.*` config schema type. Most block plugins do not have any additional translatable settings; this makes sense because most block plugins are about executing logic, and offer the content author some useful dials and knobs to control that logic. N results, the Zth menu level, a TRUE/FALSE decision … those are the typical settings for block plugins: integers and booleans that are _not_ translatable, because all translations want the same logic to be executed.

Note: until [#3572850](https://www.drupal.org/project/canvas/issues/3572850) happens, this means every block component instance will have a translatable `label` setting, but it will never have been populated by a content author; it'll have been specified whenever the `Component` config entity was created/updated (in `BlockComponentDiscovery::computeComponentSettings()`).

### 6. Complex case: SDC and code components use shape-based and value-based translatability, and reuse Canvas' field widget infrastructure

`JsonSchemaPropsComponentInstanceInputsConfigSchemaGenerator` (SDCs, code components) must mark props using a translatable prop shape to `translatable: true` with `form_element_class` of `CanvasStaticPropSourceFieldWidget`.

This `CanvasStaticPropSourceFieldWidget` class must reuse Canvas's existing `StaticPropSource::getWidget()` to generate a consistent form UX (field widgets) and Canvas' existing `::optimizeExplicitInput()` to consistently simplify the field type-and-widget data structure to a simpler subset, exactly the same as how the default translation's component tree would have been generated. This enables it to work correctly for all prop shapes and hence field types automatically, whether single-property (e.g. `StringItem` → scalar string) or multi-property (e.g. `TextLongItem` → `{value, format}`, `LinkItem` → `{uri, title, options}`).

### 7. Canvas should validate `LanguageConfigOverride`s even if core does not

Fixing the 12-year old core bug might not be feasible, but Canvas could layer its own validation to at least protect Canvas' config translations. An analysis and plan are available at [#3583854](https://www.drupal.org/project/canvas/issues/3583854).

## Consequences

**Benefits:**

- **Minimal custom integration code for `config_translation`**: `ListElement` traverses `ComponentInputsMapping` natively because it is a standard `Mapping` with `translatable: true` and `form_element_class` attributes. A single `CanvasStaticPropSourceFieldWidget extends FormElementBase` class handles ALL Canvas prop types by delegating to the existing field widget infrastructure (`StaticPropSource::getWidget()`). This avoids the N-classes-per-field-type explosion feared in [#3584178](https://www.drupal.org/project/canvas/issues/3584178).
- **Per-instance translatability**: the same component can have a prop translatable in one instance (static value typed by content author) and non-translatable in another (linked to an entity field). This is determined at schema resolution time, not at form rendering time.
- **Extensible to new component sources**: any new `ComponentSource` plugin only needs to implement a `ComponentInstanceInputsConfigSchemaGeneratorInterface` to participate in translation. If it does not, it inherits `FallbackComponentInstanceInputsConfigSchemaGenerator`'s correct-but-non-translatable mapping and opts out of translation without extra work.
- **Component sources with props described by JSON schema reuse field widgets**: adding translatability for additional prop shapes (e.g. `format: uri` for link props, `contentMediaType: text/html` for rich text) required only adding conditions to `JsonSchemaPropsComponentInstanceInputsConfigSchemaGenerator` — the field widget is automatically correct because `CanvasStaticPropSourceFieldWidget` delegates to Canvas's widget infrastructure.
- **Does not corner Canvas into symmetric-only translation**: [#3583945](https://www.drupal.org/project/canvas/issues/3583945) demonstrates that asymmetric per-config-entity translation is achievable on top of this architecture with minimal config schema changes. A proof-of-concept patch shows that the same `configSchemaInfoAlter()` mechanism used by `canvas_dev_translation`'s `MakeComponentTreeTranslatable` (which makes all component trees on a site asymmetrically translatable) can be applied granularly per config entity — so, for example, a single content template could opt into asymmetric translation while all others stay symmetric. The existing test suite passes with that patch applied on top of this work.
- **Single source of truth for translatability**: `::getTranslatableInputKeys()` reuses the same `ComponentInstanceInputsConfigSchemaGeneratorInterface` interface for use cases besides config translation.

**Trade-offs:**

- **Runtime schema generation**: the `Component` config entity is loaded in the `ComponentInputsMapping` constructor. This is the same cost already incurred by `ValidComponentTreeItem` constraint validation and by `JsonSchemaObject`.
- **Counterintuitive vs DRY**: it is counterintuitive to be expected to implement `ComponentInstanceInputsConfigSchemaGeneratorInterface` when not translating config-defined component trees; i.e. when only using symmetrical translations for content-defined component trees. The alternative would be duplication.
- **Robustness principle applied**: if the referenced `Component` entity does not exist or the version is invalid, `ComponentInputsMapping` falls back to an empty mapping rather than throwing. Validation constraints catch these errors separately.
- **Block translation fidelity depends on upstream schema quality**: `BlockComponent` forwards whatever config schema the block plugin declares. If a block plugin's schema incorrectly marks something as translatable (or not), Canvas inherits that.
- **SDC/code component shape-based translatability**: By default, only plain strings (`type: string`), HTML strings (`type: string, contentMediaType: text/html`), and URI strings (`type: string, format: uri`) are translatable. This is because core's SDC subsystem itself lacks translatability metadata. Until that time, this hardcoded set will have to do, augmented with an alter hook to allow component developers and site owners to deviate from this default: [#3584178](https://www.drupal.org/project/canvas/issues/3584178).
