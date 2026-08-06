# 17. Data health validation: check-based coverage with environment-fingerprinted incremental results

Date: 2026-07-17

Issue: <https://git.drupalcode.org/project/canvas/-/work_items/3591667>

## Status

Accepted

## Context

Canvas guarantees that data passing validation renders and is editable — but only for data actually validated. Data can drift from the current data model without any site misstep: a site may adopt Canvas before an update path ships for a data-model change, leaving data from older rules untouched. Sites with custom update paths or other forms of direct database manipulation may over time deviate further from the upstream data model evolution. Canvas is making its validation more capable over time, which may then trigger validation errors for data that predates those rules or was incorrectly manipulated.

The consequences vary by case, but could result in: some component instances failing to render or becoming uneditable, WSODs, or even failing Canvas update paths. Worse, these consequences may not be noticed until a site is deployed, or even later: when the invalid component tree happens to be rendered or edited.

The validation ensuring writes are valid (in the codebase at the time of the write) should also run as a standalone audit answering "is all of this site's Canvas data still valid?" — for monitoring, auditing, site-health.

The audit must span several kinds of data, using the already existing validation constraints:
- Canvas config entities — both those that hold a component tree and those that don't. Translations live in language config overrides, which core never validates against config schema; [ADR #10](0010-dynamic-config-schema-for-component-tree-translatability.md) established validating them by merging each override onto the base config, run whenever the entity is validated.
- Content entities holding a component tree, plus every revision and every translation. [ADR #4](0004-page-entity-type.md) established the revisionable, translatable content entity; [ADR #12](0012-symmetric-content-translation-store-all-inputs-validate-at-write-time.md) established that each revision × translation pair is validated at write time.
- Unpublished auto-save snapshots. Not live data, but they become live entities verbatim when published, so an invalid snapshot is a deferred invalid entity.

Several forces shape the design:
1. A full audit cannot be assumed to fit a single PHP execution — it may take seconds (hundreds of entities) to hours (millions of entities).
2. It must be interruptible and resumable, whether stopped cleanly or mid-way.
3. It must be parallelizable across processes.
4. It must detect, granularly, which data needs revalidating: changing one entity must not force rescanning everything.
5. A validation result depends on more than the data validated: also on Canvas' validation *logic*, which can legitimately tighten across releases and turn previously valid data invalid. This differs from component *implementation* changes (an SDC's schema, a code component, a block plugin), which must never invalidate existing instances. Note that such changes *won't* invalidate existing instances since Component config entities are versioned: each instance pins, and is validated against, the version it was created against ([ADR #6](0006-One-field-row-per-component-instance.md)). ⇒ Changes in the environment (modules installed, module versions, etc.) may cause the validation logic to change, and hence make prior validation results obsolete.

## Decision

1. **One reusable validation engine, multiple front ends.** Implemented once as a service (`Doctor`) streaming results one data item at a time (a generator), never materializing the whole data set. The CLI (`canvas:doctor`, on Drupal ≥ 11.4's `dr` CLI), a Drupal status report entry and a future web UI are thin callers, reusing existing validation constraints unchanged — no parallel logic.

2. **Coverage is organized into checks, grouped into data checks and system checks.** Each **data check** validates many data items against the corresponding entity validation constraints; each **system check** answers one environment/state question. The report groups the two so they never blur.

   Data checks:
   - **`config`** — every Canvas config entity. Validating it transitively validates all language overrides (per [ADR #10](0010-dynamic-config-schema-for-component-tree-translatability.md)), so translations need no separate pass; transient, internal staging config entity types are excluded.
   - **`content`** — default revisions of content entities holding a component tree, and each translation — the most actionable check for day-to-day monitoring, since it covers live data.
   - **`content_past_revisions`** — superseded revisions of those entities. A failing one matters: reverting to it would produce an invalid live entity. Kept separate so it never obscures the default-revision report, but covered like any data check (cron, status report). Write-path-only constraints (e.g. `EntityChanged`, `EntityUntranslatableFields`, `ContentTranslationSynchronizedFields`) are filtered out — they compare against the current revision and always fire on non-default ones — and remaining findings age out as retention prunes old revisions.
   - **`content_forward_revisions`** — pending drafts: revisions whose latest revision is not yet the default (unpublished). Like auto-save snapshots, an invalid forward revision is a deferred invalid entity: it will fail when published.
   - **`auto_save`** — every unpublished auto-save snapshot, validated as the entity it would become when published.

   System checks:
   - **`code_tagged_releases`** — every installed extension is at a tagged release. A development checkout is an **advisory risk, not a failure** (decision 7): it disables incremental reuse but doesn't itself indicate broken data.
   - **`update_path_executed`** — the installed schema version and which post-update functions have and have not been applied. Pending post-updates make downstream results untrustworthy.
   - **`update_path_escaped_config`** — config entities with a *detectable* pending data-model migration. (They are detectable whenever config entities get an update path that follows [the proposed best practice in Drupal core](https://www.drupal.org/node/3521618).) A partial signal: update paths implemented without such a `needs*()` detector are invisible here, so `update_path_executed` is the authority for those.
   - **`component_source_supports_schema_evolution`** — component sources lacking a `ComponentInstanceUpdaterInterface`. This is reported as a **risk, not a failure** (see decision 7).

3. **Results are persisted per data item for incremental reuse.** A stored result is reused only while **both** are unchanged:
   - the data item's **freshness signature**, and
   - an **environment fingerprint** (see 4).

   The freshness signature is Drupal's own change signal, not a re-hash of the data:
   - For a **saved content or config entity**: its cache tag checksum. Drupal invalidates the tag on every save, and language overrides share the base config's tag, catching any relevant change. The tag derives from identity alone, so an unchanged entity is skipped **without being loaded** — the dominant cost on large sites — let alone re-serialized and hashed.
   - For an **auto-save snapshot**, which has no cache tag, the snapshot's own data hash (already computed when written).

   (This means that for content entities, a single change makes all persisted results non-reusable: those for the default revision, past revisions and forward revisions. This is considered an acceptable cost, because it eliminates the need for complex additional tracking.)

   This single mechanism satisfies granular revalidation (only changed items redone), interruptibility (each item committed immediately, nothing to roll back), resumption (a rerun skips everything still fresh), and cross-process parallelism (processes coordinate solely through stored results).

   On that last point: the design guarantees parallel-safety but does not itself coordinate the parallel processes. There is **no per-item lock and no work-claiming**. Each result is an idempotent write keyed on the data item and check, and a finding is deterministic given the item's freshness signature and the environment fingerprint — so two processes validating the same item compute the same findings and the last write wins: redundant work, never a corrupt result. Dividing the work rather than duplicating it is a caller's concern, not the engine's: a caller hands each process a disjoint slice of the stream (by check, or by a range within it). No lock is required, because a collision is merely wasted, not wrong.

4. **The environment fingerprint tracks changes to validation logic**: the module schema version, applied update functions, and every installed extension's version (modules and themes). A new release signals its validation logic may have tightened, so any version change invalidates all stored results. It deliberately excludes component versions: implementation changes are handled by Component config entity versioning (each instance validated against the version it was created against — [ADR #6](0006-One-field-row-per-component-instance.md)), needing no fingerprint change.

5. **Persisted results are ignored when any development-checkout extension is present.** Such an extension has no release version, contributing a fixed placeholder to the fingerprint; editing its code would not change it, so persisted results would silently go stale. Rather than warn and leave the burden on the operator, the audit disables reuse entirely whenever any extension lacks a release version — every run is a full revalidation. The safe default: it costs the reuse speedup but eliminates the risk of silently-stale results.

6. **The audit reports conformance to the current rules — not proof of correctness.** The bound is stated to users, not implied away:
   - constraint coverage is incomplete and still evolving, so data can be broken in ways no constraint yet detects;
   - validation may legitimately become stricter across releases, so a previously clean report can later surface failures — a correct signal, not a regression;
   - over a long run the data set is not frozen, so a clean result is eventually-consistent, not a point-in-time guarantee.

7. **Risks are distinguished from failures.** A check may surface a condition worth flagging that is not itself a data-integrity failure. Two checks do this: `component_source_supports_schema_evolution` — a source without an updater cannot auto-migrate existing instances, making a schema change breaking for that source; and `code_tagged_releases` — a development-checkout extension disables incremental reuse. Both are a risk to weigh, not broken data: reported as risks (`⚠️`) with a pointer to guidance, and excluded from the health verdict and exit code. Only failures (`❌`) make a run unhealthy.

## Consequences

In order of importance, with the following markers:
- positives (`+`) vs negatives (`-`) vs status quo (`≃`)
- impact types: technical (`T`) vs operational (`O`) vs business (`B`)

1. `+OB` **Pre-update-path data becomes discoverable before it fails.** The same validation that guards editing now runs site-wide on demand, turning hard-to-trace deploy-time failures into an explicit, up-front report.

2. `+T` **One source of truth for validation.** Reusing existing constraints and sharing one engine/service between CLI and UI means the report is consistent regardless of how it is consumed.

3. `+TO` **Bounded, resumable work.** Streaming per data item plus persisted results makes an arbitrarily long audit safe to interrupt, cheap to resume, and parallelizable, without a separate job/checkpoint subsystem.

4. `+T` **Correct incremental reuse across constraint changes.** Keying reuse on both the freshness signature and the environment fingerprint (which includes the versions of all installed Drupal extensions) prevents the most dangerous failure mode — reporting stale "valid" results after an entity changed or the validation rules changed.

5. `+TO` **Unchanged entities are skipped without loading them.** The freshness signature for saved entities is a cache-tag checksum derived from identity: a rerun confirms an entity unchanged with a lookup, avoiding the load and re-serialization that dominate cost on large sites. Reusing Drupal's native invalidation signal also keeps the audit correct as entities are saved, with no additional change tracking.

6. `-TO` **Development checkouts disable incremental reuse entirely.** On sites where any extension runs from version control, every run is a full revalidation — the speedup is lost. The correct trade-off: trusting stale results in a dev environment would hide real problems and undermine the audit's purpose. The operator is told which extensions caused this, to install a tagged release or accept the slower run.

7. `≃TOB` **Confidence remains bounded by constraint coverage.** The audit raises confidence but does not prove correctness: as coverage grows, so does the audit's value; conversely, gaps in coverage remain gaps here too.
