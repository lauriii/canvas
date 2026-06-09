# 12. Symmetric content translation: store all inputs on every translation, sync non-translatable keys at write time

Date: 2026-06-09

Issue: <https://www.drupal.org/project/canvas/issues/3583684>

## Status

Accepted

## Context

[ADR #6](0006-One-field-row-per-component-instance.md) established the field data model: one `ComponentTreeItem` field item per component instance, each carrying a `uuid`, `component_id`, `component_version`, and an `inputs` field property (stored as a JSON blob). A key requirement from that data model is that some inputs of a component instance in a symmetric translation must be untranslatable — for example, an image field where one component instance must show the same CEO photo across all translations, while another shows a locally named product image.

[ADR #10](0010-dynamic-config-schema-for-component-tree-translatability.md), section 3, scoped what `content_translation` minimally needs to know in order to support symmetric translations:

> 1. validate that translations only override translatable inputs
> 2. synchronize non-translatable inputs from the default translation

Drupal's `content_translation.synchronizer` already runs on every entity presave and handles synchronizing non-translatable field *column groups* (the `tree` column group for `uuid`, `parent_uuid`, `slot`, `component_id`, `component_version`). However, non-translatable input *keys within the `inputs` field property (stored as a JSON blob)* are not a column group and therefore fall outside that mechanism. They need a separate synchronization step that composes with the existing pipeline rather than duplicating it.

## Decision

1. **Every translation stores ALL inputs** — both translatable and non-translatable key-value pairs — in its `inputs` field property (stored as a JSON blob).

2. **Non-translatable input keys are synchronized by decorating `content_translation.synchronizer`.** After core's standard column-group synchronization, additionally propagate non-translatable input keys' values from the default translation to all other translations.

   It activates only when the field is in symmetric mode (`tree` synced, `inputs` translatable). Tree mutations (deletions, reorderings) are handled by the existing `tree` column-group mechanism; the decorator handles only the per-key input propagation within the `inputs` field property.

   **Core bug workaround.** `FieldTranslationSynchronizer::createMergedItem()` merges field items by delta position, not by UUID. When the default translation prepends a new instance (shifting existing instances to higher deltas), the decorated synchronizer receives non-default translation items already carrying the new UUID and `component_id` — but with `inputs` from the instance that previously occupied that delta. This requires a work-around.

3. **All callers read `inputs` directly.** Rendering (`toRenderable()`), client-side representation (`getClientSideRepresentation()`), the computed `inputs_resolved` field property, and JSON:API all read the stored `inputs` identically for default and non-default translations. Hence there is zero cacheability or merging complexity: that is all handled at the time of writing/saving, not at the time of reading/rendering.

4. **The invariant is enforced by a validation constraint.** A constraint similar to `ContentTranslationSynchronizedFieldsConstraint` explicitly verifies that non-translatable inputs match the default translation across all translations.

## Consequences

In order of importance, with the following markers:
- positives (`+`) vs negatives (`-`) vs status quo (`≃`)
- impact types: technical (`T`) vs operational (`O`) vs business (`B`)

1. `+T` **Single source of truth per translation.** Each translation's `inputs` field property is self-contained and correct on its own after the synchronizer runs. Any code that reads `inputs` — rendering, JSON:API, the computed `inputs_resolved` field property — gets the full set of inputs without needing to know about other translations.

2. `+T` **the computed `inputs_resolved` field property works for all translations.** JSON:API callers get resolved inputs for any translation.

3. `+T` **Adds to `FieldTranslationSynchronizer` by decorating it.** The decorator runs inside Drupal's existing presave pipeline. Tree mutations (deletions, reorderings) are handled by the `tree` column-group mechanism; the decorator adds only per-key propagation inside `inputs`. No duplication of core synchronization logic.

4. `+TOB` **Aligns with Drupal's existing translation storage convention, reducing outlier complexity.** In Drupal core and contrib, every translation row stores the full field data for that translation — non-translatable fields are present on every translation row with identical values, kept in sync by `FieldTranslationSynchronizer`. Canvas `ComponentTreeItem` now follows the same convention: `inputs` is present and complete on every translation row. This means any Drupal subsystem that loads a translation object and reads its fields directly — without any Canvas-specific awareness — works correctly out of the box. Search API (already supported by Canvas) is an immediate beneficiary: it indexes each translation independently by loading the translation object and iterating its fields; if non-translatable input keys were absent from non-default translation rows, Search API would index incomplete component tree data for those translations without any error or warning.

5. `+T` **Every past revision is self-contained.** Because the synchronizer fires on presave — the same moment a new revision row is written — every (revision × translation) pair is committed to the database already synchronized. Loading any past revision requires no cross-revision or cross-translation lookup at read time.

6. `+TOB` **Compatible with Workspaces without special casing.** Workspace module creates pending revisions inside a workspace and deploys them by promoting those revisions to live. Because all inputs are synchronized on presave of the pending revision, deploying a workspace is a pure revision promotion. No workspace-aware merging of inputs is needed, and the question of whether to read non-translatable inputs from the pending-revision default translation or the live default translation never arises.

7. `-T` **Storage is slightly larger.** Non-translatable inputs are duplicated across all translations. For typical component instances (a handful of boolean or integer block settings) the overhead is negligible, but it is not zero.

8. `+TO` **Write-time validation via constraint.** The decorator synchronizes inputs at save time; the constraint validates the result. Together they prevent invalid state from being persisted.

9. `-TOB` **Newly added component instances leave translatable inputs empty on non-default translations.** When a component instance is added to the default translation, the `tree` column-group sync creates corresponding rows in all non-default translations and the decorator propagates the non-translatable inputs — but translatable inputs (e.g. a block `label`) are empty, because copying them would store the default-language text under a different-language translation. Translators must supply those inputs manually.
