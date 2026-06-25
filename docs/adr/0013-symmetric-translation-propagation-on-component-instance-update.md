# 13. Symmetric translation propagation on component instance update

Date: 2026-06-25

Issue: <https://www.drupal.org/project/canvas/issues/3591596>

## Status

Accepted

## Context

[ADR #6](0006-One-field-row-per-component-instance.md) established that each component instance stores a `component_version` alongside its `inputs`. When a component's implementation changes, Canvas creates a new version of the `Component` config entity and existing component instances must be migrated forward: obsolete inputs removed, newly required inputs seeded with defaults, and the stored `component_version` updated to match. This migration is performed by a component source's *instance updater* (`ComponentInstanceUpdaterInterface`).

[ADR #10](0010-dynamic-config-schema-for-component-tree-translatability.md) established that each component instance's inputs have a per-instance translatability classification: some inputs are translatable, others are not. This classification is determined dynamically from the component source's schema generator.

[ADR #12](0012-symmetric-content-translation-store-all-inputs-validate-at-write-time.md) established that every symmetric content translation stores the full set of inputs for every component instance — both translatable and non-translatable ones — and that non-translatable inputs are synchronized from the default translation at write time.

(Note that "input" is the generic terminology to describe all the key-value pairs that a component instance stores in its `inputs`. Individual `ComponentSource` plugins may call these differently: SDCs and code components call them "props", blocks call them "settings".)

### The gap: version updates were not propagated to translations

Before this ADR, an instance version update was applied only to the default translation's component tree. After such an update, the default translation's instances referenced the new component version with updated inputs, but every non-default translation still referenced the old version and still held inputs shaped for that old version — stale `component_version`, deleted inputs, missing values for new required inputs. This violated the invariant from ADR #12 (all content entities with symmetrically translated component trees store complete, synchronized inputs).

### Symmetric translations make this propagation tractable

The symmetric translation model — same component tree structure across all translations, only inputs may differ per language — means a component instance update is structurally a change to *all* translations at once. `ComponentSource` plugins' instance updaters (`ComponentInstanceUpdaterInterface`) are deterministic: given the same instance and the same source-version transition, they produce the same structural result regardless of which translation's tree it runs on (it removes the same deleted inputs, seeds the same required inputs' default value, prunes the same deleted-slot children, and sets the same `component_version`). It does not touch existing valid inputs, so each translation keeps its own translated values.

Both content entities and their translations are live in memory as `ComponentTreeItemList` field item lists, so the updater can run on them directly. Config entities are represented using plain arrays (but complying with the `type: canvas.component_tree` config schema) and can be transformed 1:1 to `ComponentTreeItemList`s, so the updater can run on them directly too. Config entity translations, however, are stored as sparse `LanguageConfigOverride` records containing only translatable overrides, so the updater cannot run on them directly. Instead the full translated tree is reconstructed (base config + override), the same updater runs on it, and the sparse override is re-derived from the result.

## Decision

### 1. Content entity translations: run the same updater on each translation's tree

After updating the default translation, `ComponentSourceManager::updateComponentInstances()` applies the same instance updaters to each non-default translation's `ComponentTreeItemList`. Each translation's tree is updated in-place. The updater leaves valid existing inputs' values untouched, so each translation keeps its own translated values. Non-translatable values converge to the default translation through the existing write-time synchronizer (`ComponentTreeFieldSymmetricalTranslationSynchronizer`, ADR #12) — not through this propagation step.

The updater seeds only *required* new inputs. Optional new inputs are not injected into any translation (including the default): they are absent from stored inputs until a translator explicitly sets them. This is correct: optional inputs have no stored value until someone chooses one, regardless of whether the tree is a default or non-default translation.

### 2. Config entity translations: rebuild the full tree from base + override, then re-derive the sparse override

Config entity translations are stored as sparse `LanguageConfigOverride` records containing only translatable overrides, so the updater cannot run on them directly. For each non-default translation:

1. The full translated tree is reconstructed by merging the base config with the translation's override, then loaded as a dangling `ComponentTreeItemList` (`ComponentTreeConfigEntityBase::getTranslatedComponentTree()`). Because the entity's stored `component_tree` is still the pre-update tree at this point, the merge yields the pre-update translated tree.
2. The same instance updater runs on that full tree. This is where deleted inputs are removed, over-cardinality arrays are truncated, new required inputs' defaults are seeded, deleted-slot children are pruned, and `component_version` is bumped — identical to the default-translation pass. If the updater reports no change, the override needs no maintenance and is left untouched.
3. If a change was reported, the sparse override is re-derived: for each instance, keep only the inputs that were already in the override, are still translatable in the new version, and still exist post-update. New inputs are never added (their value comes from the default translation — the base config), and `component_version` is not stored in overrides (since that too would be identical, because it's a symmetrical translation). If a component instance's override entry becomes empty it is dropped; if the entire override record becomes empty it is deleted.

Staged config translation mutations are kept in memory on `StagedLanguageConfigOverride` entities — the same auto-save pattern as `StagedConfigUpdate` — so they participate in the review-and-publish workflow. Publishing writes a real `LanguageConfigOverride`, or deletes it if empty.

Core already prunes overrides whose keys vanished from the base config (deleted inputs, shrunk cardinality) via `ConfigFactoryOverrideBase::filterOverride()`, invoked from `LanguageConfigFactoryOverride::onConfigSave()` when the base config is saved. Canvas cannot reuse it: `filterOverride()` is a `protected` method with no public entry point other than that save-time event subscriber, so it is private API and out of reach — and it is coupled to a real `Config::save()`, whereas Canvas mutates staged, in-memory overrides before publish. Canvas therefore re-derives the override itself. This is not merely a reimplementation of `filterOverride()`: the staged override must be self-consistent and valid *before* publish (for preview and validation), and the re-derivation additionally drops keys whose translatability classification changed between versions — which `filterOverride()`, being key-existence-only, cannot detect.

### 3. The config override re-derivation reuses ADR #10's translatability classification

The config override re-derivation (Decision 2, step 3) decides which inputs belong in an override using the same schema-driven translatability classification established in ADR #10 — the `inputs` field property's method for enumerating translatable keys (`ComponentInputs::getTranslatableInputKeys()`), evaluated against the new version. This is what drops a key whose translatability classification changed between versions: it survives in the tree but is no longer enumerated as translatable, so it is excluded from the re-derived override. The content path needs no such classification: non-translatable convergence is handled by the write-time synchronizer (ADR #12), and the updater itself is translatability-agnostic.

### 4. Propagation is in-memory only; the caller persists

The updated translations (content field item lists, config staged overrides) are mutated in memory only. The caller is responsible for persisting them — by creating auto-saves for the content or config entity with a component tree, for both its default translation and every translation it has.

## Consequences

In order of importance, with the following markers:
- positives (`+`) vs negatives (`-`) vs status quo (`≃`)
- impact types: technical (`T`) vs operational (`O`) vs business (`B`)

1. `+TOB` **Translation integrity is maintained through component version transitions.** After an update, every translation — content entity or config entity — carries inputs shaped for the new component version, with the correct version identifier. The ADR #12 invariant for content entity translations is preserved through updates; for config entities the very design of how translations work ensures this.

2. `+T` **No bespoke reconciliation logic.** The component instance's `ComponentSource` plugin's `updater` — already the authority on a single-translation update — is the only mechanism for both content- and config-defined component trees. There is no snapshot capture and no hand-written remove/seed/sync rule set: content runs the updater on each translation's tree, config runs it on a reconstructed full tree. This removes a large class of "the rules disagree with the updater" bugs by construction.

3. `+T` **Single source of truth for "which inputs survive".** Content and config translations both let the updater decide, rather than re-deriving valid inputs from a separate algorithm. Cardinality truncation and deleted-slot child pruning are therefore handled identically everywhere.

4. `+TOB` **Content and config translations share one conceptual model** — "run the same updater on the full tree" — even though config first reconstructs the full tree from base + override and re-derives the sparse override afterward. The divergence is a consequence of sparse storage (config), not a conceptual split.

5. `+T` **No new services.** The propagation responsibility stays in `ComponentSourceManager`.

6. `-T` **Propagation requires all translations to be accessible in memory at update time.** For content entities all translations are loaded; for config entities only sparse override records are loaded and a full tree is rebuilt per translation. This is analogous to what Drupal core's `Drupal\content_translation\FieldTranslationSynchronizer` already incurs for content translations.

7. `≃T` **The updater is applied independently per translation rather than reconciling against the default.** This is correct because the updater is deterministic and idempotent: independent application yields the same structure on every translation, and existing translated values are preserved.
