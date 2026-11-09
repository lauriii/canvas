# Drupal Canvas CLI

In the rest of this document, `Drupal Canvas` will be written as `Canvas`.

Canvas ships commands for the [`dr` CLI](https://www.drupal.org/node/3584928), which requires Drupal >= 11.4. List them with:

```sh
vendor/bin/dr list canvas
```

Every command:

- takes `--format=table|json`. `table` is the default. `json` prints one object and nothing else, for tooling;
- prints JSON as an envelope with a `report_version` integer, described by a JSON Schema next to this file. Adding a key does not bump `report_version`; removing or renaming one does;
- exits with `0` on success, `1` when it ran and found a problem, and `2` on invalid input. The error names the valid values.

## `canvas:doctor`

Validates all of a site's Canvas data at once, plus a few environment and update-state checks. See the [`Canvas Data Health` doc](data-health.md).

```sh
vendor/bin/dr canvas:doctor --all-checks
```

## `canvas:audit:usage`

The component audit service (`\Drupal\canvas\Audit\ComponentAudit`) finds where a `component` is used. It searches
`component tree`s in content entity revisions, config entities and auto-saves. Searches can be narrowed to specific
`component` versions.

The command exposes this on the command line. It accepts one of:

- a `Component Source Plugin` ID: lists usages of every `component` of that source;
- a `Component` config entity ID: lists usages of every version of that `component`;
- a `Component` config entity ID and version, separated by `@`: lists usages of that version only.

```sh
vendor/bin/dr canvas:audit:usage sdc
vendor/bin/dr canvas:audit:usage sdc.olivero.teaser
vendor/bin/dr canvas:audit:usage sdc.olivero.teaser@a1b2c3d4e5f60718
```

Use `--revisions=default` or `--revisions=latest` to search fewer content entity revisions (default: `all`), and
`--format=json` for machine-readable output. The envelope has the `target` and `revisions` as given, and `usages`. Each
usage has a `revision` ID and `default` and `latest` flags, which are `null` for config and auto-save usages. Schema:
[`audit-usage.schema.json`](audit-usage.schema.json).
