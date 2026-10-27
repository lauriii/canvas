# 17. Per-translation component tree forks (asymmetric translations)

Date: 2026-07-18

Issue: <https://www.drupal.org/i/3571130>

## Status

Accepted

Extends [12. Symmetric content translation: store all inputs, validate at write time](0012-symmetric-content-translation-store-all-inputs-validate-at-write-time.md)

Extends [13. Symmetric translation propagation on component instance update](0013-symmetric-translation-propagation-on-component-instance-update.md)

## Context

Canvas component trees are translated symmetrically: the tree structure is synchronized across translations and only
input values are translatable. Whether a tree is symmetric or asymmetric was designed as a per-field, site-wide
`translation_sync` setting, but content teams need it to be a per-entity decision: most pages stay symmetric, and an
author forks one specific translation so that translation owns an independent component tree.

Every translation already stores full component tree rows per langcode, so storage supports divergent trees; what was
missing was a way to represent the intent "this translation owns its tree", to exclude such translations from
symmetric synchronization, validation, and component version propagation, and to reverse the decision.

## Decision

- Fork state for content entities is a translatable, revisionable boolean base field, `canvas_component_tree_fork`
  (default FALSE, no form or view displays), added to every translatable content entity type. One flag covers all
  component tree fields on the entity; the flag on the default translation is ignored. Deriving "trees differ implies
  forked" was rejected: it cannot represent "forked but not yet diverged" (synchronization would instantly re-sync the
  divergence away) and makes accidental divergence indistinguishable from intent. Plain field data gets revisions,
  workspaces, JSON:API, and default_content behavior for free.
- The single predicate is
  `ComponentTreeTranslationFork::isForkedTranslation(ContentEntityInterface $translation)`.
- Forked translations are excluded from symmetric synchronization via snapshot/restore around the decorated core
  `FieldTranslationSynchronizer::synchronizeFields()`: forked translations' raw component tree values are captured
  before core's sync and restored after; when the saved translation is itself forked, every translation's values are
  protected so fork edits never propagate outward. Restoring values core did not touch is a no-op, so core's merge
  branches (pending revisions, default-translation-affected) stay authoritative for non-forked translations.
  Re-implementing core's sync, or altering field definitions per entity, were rejected as fragile.
- Forking flips the flag on the translation's auto-save draft; no tree copy is needed because symmetric mode already
  stores identical tree rows plus the translation's own translated inputs — the current state is the fork seed, and
  translator work is preserved. Unforking, also on the draft, destructively re-syncs from the default translation:
  tree structure and non-translatable inputs come from the default translation, the fork's translatable input values
  are re-applied for component instances that still exist in the default tree, and fork-only component instances are
  discarded. Both operations participate in preview, publish, and discard like any other edit.
- The symmetric translation constraint skips forked translations; their trees remain covered by the per-field
  constraints running on each translation's own field items. Component version updates reconcile a forked
  translation's own tree in place (no redirect to the default translation) and skip forked translations when
  iterating siblings of a non-forked host: forks reconcile when edited or published in their own language.
- Everything ships gated behind the experimental `canvas_dev_translation` module (in addition to content_translation),
  including the base field, the fork/unfork HTTP endpoints (whose routes live in that module's routing file), the
  layout API fork metadata, and the editor UI actions. The gate moves into canvas proper when translation support is
  promoted. Editing forked translations in the editor (the `canvas_langcode` request subscriber) is not gated: without
  fork support no translation is forked, so it only ever resolves the default translation.
- Config-defined component trees (ContentTemplate, PageVariant) fork through their language overrides: an override
  carrying only translatable input values is symmetric (structure falls through to the base config), an override
  carrying a `forked_component_tree` is forked. The fork cannot be an override of `component_tree` itself, because
  overrides are merged onto the base config and so could never delete or reorder its component instances. It cannot be
  a nested array either, because core removes override keys the base config lacks, recursively, whenever the base
  config is saved, which would remove every fork-only component instance. So the base config always carries an empty
  `forked_component_tree` string, and a forked language's override sets it to its complete tree, JSON-encoded. Every
  reader of a translation's tree (rendering with the language's overrides, the translation validator, component version
  updates, and the editor) uses the fork's tree instead of the merged one. Fork state is the presence of that value in
  the staged override when there is one, and the stored override otherwise; no flag is needed, because nothing
  re-syncs override contents. Forking, unforking and editing a forked config translation stage its override, which is
  listed, published and discarded with its base entity's draft.

## Consequences

- Divergent translations become an explicit, reversible, per-translation state instead of a site-wide mode, and
  pre-existing divergent data can be preserved on upgrade by marking those translations forked rather than being
  clobbered by the now-guaranteed symmetric synchronization.
- String-based translation tools cannot operate on forked translations: their premise (a shared tree with per-key
  input overrides) no longer holds. Consumers must detect fork state and degrade gracefully; publishing a string draft
  onto a forked translation must be refused.
- A draft whose own snapshot is forked is listed in the pending changes, labelled with its language, and publishes and
  discards independently of its siblings; it applies only its translatable fields, so it never reverts a shared field
  the default translation published since. Symmetric drafts and pending unforks still publish and discard with the
  whole entity group, because their trees depend on the default translation's draft, until per-language publish
  lands. A pending unfork is listed under the default translation's draft when there is one, and on its own otherwise.
- The Canvas editor is the editing surface for forked trees. Editor requests name the forked translation with an
  explicit `?canvas_langcode=` query parameter, which a request subscriber resolves after routing before re-checking
  route access against the named translation, and route controllers read the swapped entity unchanged; symmetric
  translations and pending unforks stay preview-only. Writes name the translation their data was loaded
  for, and switching the edited translation of an entity reloads the editor, so one translation's data is never
  written to another.
  See `\Drupal\canvas\EventSubscriber\ForkedTranslationEditRequestSubscriber`.
- Unforking is destructive by design (fork-only components are discarded); the UI requires type-to-confirm before
  calling it.
