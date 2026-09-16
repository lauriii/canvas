# Code Component metadata

Code Component and Canvas Headless codebases contain an authored `component.yml` file. Canvas publishes a hand-authored
JSON Schema for the current document envelope at
<https://git.drupalcode.org/project/canvas/-/raw/1.x/component-metadata.schema.json>.

This is a rolling schema, not a versioned compatibility contract. Copies found in release tags are historical source
snapshots; Canvas does not promise that current targets accept metadata described by older copies.

The schema uses JSON Schema draft-07. Prop definitions under `props.properties` are themselves JSON Schema and are checked
directly rather than generated from Drupal config schema. Metadata files do not include `$schema` or a schema version
because the Drupal site targeted by a CLI operation provides the authoritative contract.

## Authored fields

Only `name` and `machineName` are required. Authors may also provide:

- `status`;
- `required`;
- `props`;
- `slots`; and
- `dataDependencies.entityFields`.

Canvas CLI validates the raw YAML before applying defaults. It rejects unknown keys. Authors must not provide:

- `type`, which Canvas derives from the component and project mode;
- `dataDependencies.urls` or `dataDependencies.drupalSettings`, which Canvas derives from source analysis; or
- `x-allowed-entity-type-id` and `x-allowed-bundle`, which Drupal projects from `dataDependencies.entityFields` when it
  constructs the SDC definition.

`canvas pull` omits projected keys, preserves `dataDependencies.entityFields`, and writes the optional authored fields
explicitly.

## Local and target validation

The CLI uses the bundled schema to validate the authored envelope. It also checks each prop definition as JSON Schema and
validates its first example, which Canvas uses as the default, when the prop schema is locally resolvable. Canvas-owned
`$ref` definitions from `schema.json` are bundled with the CLI. References provided by other target extensions may not be
resolvable locally.

The required ESLint configuration checks portable authoring rules that cross metadata fields or extend JSON Schema:
required names must identify defined props, required props must provide a default example, and string examples must not be
empty. Drupal owns Canvas-specific metadata policy, including array cardinality, content entity reference relationships,
and image URL restrictions.

`canvas validate` and `canvas push` submit normalized payloads to the authenticated Drupal site for non-mutating
validation. The target runs the same metadata constraints used for create and update operations, so only its result is
authoritative for that installed Canvas version and site configuration.

If authentication, authorization, connectivity, or the target operation is unavailable, the CLI reports that only local
checks were completed. During push, older sites retain per-component save-time validation, so a partial push remains
possible.

`canvas build` performs only local checks. Component discovery, Workbench, and headless consumers remain offline and do
not become target-aware.
