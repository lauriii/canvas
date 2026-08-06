# Drupal Canvas Data Health

In the rest of this document, `Drupal Canvas` will be written as `Canvas`. This builds on top of the [`Canvas Data Model` doc](data-model.md) and the [`Canvas Config Management` doc](config-management.md).

## Why this exists

Canvas guarantees that data passing validation renders and is editable — but only for data actually validated. Sites with custom update paths or other forms of direct database manipulation may over time deviate further from the upstream data model evolution. Canvas is making its validation more capable over time, which may then trigger validation errors for incorrectly manipulated Canvas-owned data.

The consequences vary by case, but could result in: some component instances failing to render or becoming uneditable, WSODs, or even failing Canvas update paths.

`canvas:doctor` runs the *same* validation that guards editing, across all of a site's Canvas data at once, plus a few environment/update-state checks — answering, on demand: **"is all of this site's Canvas data still valid?"** Run it in monitoring, CI, or by hand when a bug smells like stale data.

**Data item** = one unit the doctor validates: a config entity, a content entity (including all of its translations and revisions), a previous or pending content revision, or an auto-save snapshot. Design rationale: [ADR 17](adr/0017-data-health-validation-check-based-coverage-with-environment-fingerprinted-incremental-results.md). Issue queue: [Validation](https://www.drupal.org/project/issues/canvas?component=Validation).

## `canvas:doctor`

Requires Drupal >= 11.4, which ships the [`dr` CLI](https://www.drupal.org/node/3584928).

```sh
vendor/bin/dr canvas:doctor --all-checks
```

### Example run

```
  🩺  Canvas Doctor  ·  a check-up for your Canvas data
  __/\__/\____/\______/\__/\___♥___

 -------------------------------- ----------------- ------- --------- -------- ----------- ---------- ---------------------------------------------------------------------------------------------------------------------------------------
  Check                            Status            Total   Healthy   Cached   % Healthy   Duration   Prescription
 -------------------------------- ----------------- ------- --------- -------- ----------- ---------- ---------------------------------------------------------------------------------------------------------------------------------------
  System
  code_tagged_releases             ✅ healthy        63      63        0        100.0%      0.006s
  update_path_executed             ✅ healthy        33      33        0        100.0%      0.299s
  update_path_escaped_config       ❌ 1 problem(s)   116     115       0        99.1%       0.184s     Manually re-save these config entities.
  component_source_..._evolution   ⚠︎  1 risk(s)      3       3         0        100.0%      0.000s
 -------------------------------- ----------------- ------- --------- -------- ----------- ---------- ---------------------------------------------------------------------------------------------------------------------------------------
  Data
  config                           ✅ healthy        116     116       112      100.0%      0.112s
  content                          ❌ 2 problem(s)   9       7         0        77.8%       0.774s     Fix the value at the reported property path (run with --details) via the editor UI, config import, or a targeted update, then re-run.
 -------------------------------- ----------------- ------- --------- -------- ----------- ---------- ---------------------------------------------------------------------------------------------------------------------------------------

 💊 Prescription:
     → Manually re-save these config entities.
     → Fix the value at the reported property path (run with --details) via the editor UI, config import, or a targeted update, then re-run.
     → Inspect each problem: vendor/bin/dr canvas:doctor --checks=update_path_escaped_config,content --details

 [ERROR] Diagnosis: 2 check(s) need attention.
```

Each unhealthy check maps to the remedy that fixes it. With `--details`, each unhealthy check expands to an enumerated list instead: the data-item identifier is colored (white-on-red for a problem, yellow for a risk), property paths colored distinctly:

```
update_path_escaped_config
--------------------------
 1. canvas.page.home
    pending: needsComponentTreeMigration

content
-------
 1. canvas_page 8 (default revision, nl)
    [components.0.inputs.href] This value should not be blank.
```

Checks — a **system** check answers one environment/state question; a **data** check validates many data items into one summary row:

| Check | Group | What it reports | When it fails |
|-------|-------|-----------------|---------------|
| `code_tagged_releases` | system | Every installed extension is at a tagged release; dev checkouts are an advisory **⚠️ risk** (disables result reuse, see below). | Never a failure by itself. |
| `update_path_executed` | system | Installed schema version; applied vs. pending post-updates. | Pending post-update → results untrustworthy until `drush updatedb`. |
| `update_path_escaped_config` | system | Config entities with a *detectable* pending `CanvasConfigUpdater::needs*()` migration — typically config that escaped the update path, e.g. imported from an old export after the post-updates already ran. Partial signal: update paths implemented without a `needs*()` detector are invisible here; `update_path_executed` is authoritative for those. | Entity still needs migration → re-save it (`preSave()` applies the pending migration). |
| `component_source_supports_schema_evolution` | system | Component sources lacking a `ComponentInstanceUpdaterInterface` (auto-migrates existing instances to a new component version). A **risk (⚠️), not a failure**. | Never fails. Flags a schema change as breaking for that source. `block` is expected; `fallback` is excluded. See [change record](https://www.drupal.org/node/3521221). |
| `config` | data | Every Canvas config entity; translations validated transitively (`CanvasConfigEntityTranslationsAreValidConstraint` merges each override onto the base config). | Entity or translation violates a constraint. |
| `content` | data | The default revision of every content entity holding a `component_tree` field, and each translation. | A live entity violates a constraint. |
| `content_past_revisions` | data | Superseded (non-default, non-latest) revisions. **Write-path-only constraints** (e.g. `EntityChanged`, `EntityUntranslatableFields`, `ContentTranslationSynchronizedFields`) are filtered out: they always fire on non-default revisions and mean nothing in a read-only audit. | Non-write-path-only violation. Reverting would produce an invalid live entity; findings age out as old revisions are pruned. |
| `content_forward_revisions` | data | Forward (pending/draft) revisions — latest but not yet default. | An unpublished draft violates a constraint; it will fail when published. |
| `auto_save` | data | Every unpublished auto-save snapshot, validated as the entity it would become when published. | Never fails the run: an invalid snapshot is an advisory **⚠️ risk** — drafts are allowed to be incomplete, and publishing is the gate that keeps an invalid one from going live. |

- `--checks=<a,b,…>` — run only the named, comma-separated checks. **Recommended for automation**: the check set may change across releases with no deprecation period, so pin what you want.
- `--all-checks` — run every check; implies no interaction, safe in cron/CI.
- `--cache`/`--no-cache` — caching is on by default (`--cache`): stored results are reused while still fresh. Pass `--no-cache` to ignore them and revalidate everything requested, guaranteeing an up-to-right-now answer; the fresh outcomes are still recorded for later reuse. Nothing is ever deleted.
- `--details` — expand each unhealthy check into an enumerated list.
- `--format=table|json` — `table` (default) is human-readable; `json` (see below) is for tooling. A progress bar shows only when `--format` is left unspecified on an interactive terminal.

One of `--checks` or `--all-checks` is required. Exit codes, for gating a monitoring/CI check:

| Code | Meaning |
|------|---------|
| `0` | Healthy. Advisory **risks do not change this** — a run with only risks still exits `0`. |
| `1` | At least one check found a **problem**. |
| `2` | Invalid usage (unknown check, `--checks` omitted non-interactively). |

JSON output: `--format=json` prints a single object, no progress bar or banner: a stable, versioned envelope. Every requested check — system and data alike — is a uniform entry under `HealthCheck`, keyed by check name. Shape:

```jsonc
{
  "report_version": 1,
  "environment_fingerprint": "36:33:0:9f…",
  "overall": { "healthy": false, "problems": 1, "risks": 1 },
  "checks": {
    "update_path_executed": {
      "type": "system", "status": "healthy",
      "total": 33, "healthy": 33, "cached": 0, "duration_s": 0.528,
      "details": {
        "schema_version": 11200,
        "applied_post_updates": ["canvas_post_update_0001_…"],
        "pending_post_updates": []
      }
    },
    "component_source_supports_schema_evolution": {
      "type": "system", "status": "risk",
      "total": 3, "healthy": 3, "cached": 0, "duration_s": 0,
      "details": {
        "failing": ["block"],
        "sources": { "block": { "label": "Blocks", "has_updater": false } }
      }
    },
    "content": {
      "type": "data", "status": "problem",
      "total": 9, "healthy": 7, "cached": 0, "duration_s": 0.093,
      "details": {
        "failures": [
          { "label": "canvas_page 8 (default revision, nl)",
            "violations": [
              { "message": "This value should not be blank.",
                "property_path": "components.0.inputs.href", "code": null }
            ] }
        ]
      }
    }
  }
}
```

Full envelope: [`data-health.schema.json`](data-health.schema.json) (JSON Schema, draft 2020-12). **`report_version`** covers the envelope itself — `report_version`, `environment_fingerprint`, `overall`, and each `HealthCheck` entry's `type`/`status`/`total`/`healthy`/`cached`/`duration_s`; additive changes (a new key, a new check) do not bump it, a breaking change does, so pin your tooling to a `report_version` you have tested against. The `HealthCheck` map is open — a check added by a later release still validates against the generic entry. The exit code is authoritative; prefer gating on it over parsing `details`.

Whether a check's `details` shape is dependable is expressed by the schema itself: a check with its own precise entry there freezes its `details` across releases, so you can validate against it; a check that only matches the generic entry (currently only `component_source_supports_schema_evolution`) makes no such promise, and its `details` may change without a `report_version` bump — consume it defensively.

CI recipe, pinning the checks depended on and gating on the exit code:

```yaml
# .gitlab-ci.yml
canvas-doctor:
  script:
    - vendor/bin/dr canvas:doctor --checks=config,content,update_path_executed,update_path_escaped_config --format=json
  # exits 0 when healthy, 1 on problems, 2 on misuse — the job fails on non-zero.
```
