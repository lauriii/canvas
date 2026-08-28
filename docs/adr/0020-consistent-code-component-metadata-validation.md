# 20. Provide consistent Code Component metadata validation across the CLI and Drupal

Date: 2026-08-23

## Status

Accepted

## Context

Developers author Code Component metadata in `component.yml`. This Canvas exchange format reuses Single-Directory
Component prop and slot vocabulary, but it is neither an SDC definition nor a JSON Schema document. Canvas places
required prop names at the document's top level, derives values during push, and projects the result into an SDC
definition for Drupal validation.

Each entry under `props.properties` is a JSON Schema and carries Canvas presentation metadata. Drupal uses it to
validate example and runtime values. Drupal config schema is not JSON Schema; it defines which JSON Schema vocabulary
Canvas can store. Entity constraints determine which valid JSON Schema shapes Canvas and the target site support.
Translating config schema would require reproducing its dynamic types, inheritance, custom data classes, and Symfony
constraints.

Metadata validation covers the authored envelope, prop schemas and examples, relationships between authored values,
project conventions, and site-dependent rules. The last category depends on installed field types, widgets, the entity
model, and alter hooks. Checks that do not submit HTTP validation requests to the Drupal target cannot provide its
authoritative verdict, but should provide useful feedback without duplicating Drupal's validation model.

## Decision

### Use three validation layers

```text
component.yml
  ├─ JSON Schema → envelope, prop schemas, and default examples
  ├─ ESLint → portable authoring and project rules
  └─ Drupal target → authoritative acceptance
```

The CLI bundles Canvas-owned schemas. The target validates against its installed code and configuration.

### Publish and bundle one authored-metadata schema

Canvas will publish a hand-authored draft-07
[`component-metadata.schema.json`](../../component-metadata.schema.json) in the module root. It will:

- require `name` and `machineName`;
- allow optional `status`, `required`, `props`, `slots`, and `dataDependencies`;
- allow only `dataDependencies.entityFields` as an authored data dependency;
- reject authored `type`, URL dependencies, Drupal settings dependencies, and projected `x-allowed-*` keys; and
- reject unknown keys at fully defined metadata levels.

The schema will not be generated from Drupal config schema. Draft-07 meta-schema validation will check prop-schema
keywords. The authored-envelope schema will add Canvas requirements, including a nonempty `title` and an explicit
`type`. These checks cannot decide whether a site can store a prop shape, map it to an installed field and widget,
resolve an extension-provided `$ref`, or accept site-specific alterations.

Canvas will publish one rolling schema for the current format from the latest development branch. Release-tag copies are
historical snapshots, not supported versions, and Canvas does not promise that a current target accepts metadata
described by an older snapshot. The rolling repository URL will be its `$id` for external tools.

The CLI will statically import and embed that same file. It will not maintain a second authored-envelope schema or fetch
the rolling URL at runtime. Metadata files will not declare `$schema` or a contract version because target sites remain
authoritative and one component may target different sites.

Migrating Canvas-owned TypeScript schemas and validators to draft-07 is part of this change. A private workspace package
will provide a strict Ajv factory with standard formats plus `idn-email`, `idn-hostname`, `iri`, `iri-reference`, and
`duration`. Registering these validators from `ajv-formats-draft2019` does not change the dialect. The UI and CLI will
share the factory, and the CLI build will embed it instead of depending on the private package at runtime.

### Validate portable rules locally

After envelope validation, the CLI will validate every `props.properties` entry as JSON Schema and validate its first
example, when present, against its prop schema.

The CLI will also statically import and embed the module root [`schema.json`](../../schema.json). At runtime it will
register each `$defs` entry at its `json-schema-definitions://canvas.module/<name>` URI, allowing Canvas-owned
references to resolve without network access. Extension-provided `$ref` values may remain unresolved locally and remain
subject to Drupal validation. Separately, the CLI will not reject unknown extension vocabulary; Drupal will validate it
authoritatively. Drupal combines these definitions with the authored `required` names when projecting the complete SDC
`props` schema.

ESLint will validate portable relationships and conventions that JSON Schema cannot express cleanly: required names
identify defined props, required props have a default example, string examples are nonempty, `machineName` matches the
component directory or metadata filename, prop names match their titles, directories have the expected hierarchy,
imports use supported paths, and component source files provide a default export. ESLint will not determine target
acceptance or duplicate schema and target failures as blocking diagnostics.

Local success means metadata is structurally usable and its resolvable schemas and defaults are consistent. It does not
guarantee Drupal can store the shape or map it to an installed field and widget. Drupal also owns policies such as array
cardinality and content entity reference relationships. A relative image URL, for example, can satisfy `uri-reference`
while Drupal requires a fully qualified URL.

### Define authored metadata and normalization

The CLI will validate raw YAML before adding defaults. Only `name` and `machineName` are required. It defaults `status`
to `true`, `required` to an empty array, `props` to an object with empty `properties`, and `slots` and
`dataDependencies` to empty objects.

Push will derive `type`, URL dependencies, and Drupal settings dependencies. Projected `x-allowed-entity-type-id` and
`x-allowed-bundle` values are not authored metadata.
[ADR 11](0011-content-entity-reference-props-in-code-components.md) keeps `dataDependencies.entityFields` as their
source of truth.

`pull` will continue to emit optional fields explicitly, omit projected `x-allowed-*` values, and preserve
`dataDependencies.entityFields`.

### Make target validation authoritative

An authenticated Canvas site will provide a non-mutating validation operation. It will accept one normalized Code
Component API payload, construct an in-memory create or update candidate, and run the same entity and metadata
constraint chain used by create and update. It will return `204` when valid or the existing structured
constraint-violation response when invalid, without saving configuration.

`canvas validate` will validate raw YAML against the envelope schema, validate prop schemas and locally resolvable
examples, aggregate ESLint diagnostics, normalize valid metadata into a complete payload, and submit it to the target
when authenticated access is available. If the target is unavailable or lacks the operation, the CLI will report that
only local checks completed. Local checks may succeed without target validation.

Before its first create, update, or delete, `push` will validate every complete component payload against the target. If
an older site lacks the operation, the CLI will warn and retain per-component save-time Drupal validation, so a partial
push remains possible.

`build` will remain offline. Discovery, Workbench, and headless consumers will retain defensive parsing rather than
enforce target acceptance because they do not know the target site's contract.

## Alternatives considered

- **Generate JSON Schema from Drupal config schema.** Rejected because the formats define different contracts. A
  translator would duplicate Drupal config schema incompletely.
- **Use only a bundled metadata schema.** Rejected because it cannot account for target versions, extensions, field
  types, widgets, or alter hooks.
- **Use only remote Drupal validation.** Rejected because offline tools can report YAML, envelope, and resolvable
  prop-schema errors earlier and more precisely.
- **Download a target-specific schema.** Rejected because JSON Schema cannot express every target constraint. The
  non-mutating operation provides the verdict without translation or caching.
- **Declare a contract version in each `component.yml`.** Rejected because sites validate their installed contract, not
  every historical contract, and one component may target sites running different Canvas versions.
- **Make ESLint the complete metadata contract.** Rejected because ESLint can disagree with the target, while envelope
  and prop-schema validation must remain available to standard JSON Schema tools.

## Consequences

- The authored envelope and prop schemas have explicit validation paths.
- Offline tools provide structural feedback but cannot guarantee target acceptance.
- A reachable target provides the authoritative verdict without mutating configuration.
- The hand-authored schema must change with the authored format.
- Bundled Canvas-owned `$ref` definitions may differ from the target's installed version; extension references remain
  target-only.
- Older sites retain save-time validation but cannot provide an all-component preflight.
- This change establishes one TypeScript JSON Schema dialect and one tested Ajv configuration.
