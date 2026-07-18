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
  `ComponentTreeFieldSymmetricalTranslationSynchronizer::isForkedTranslation(ContentEntityInterface $translation)`.
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
  promoted.
- Config-defined component trees (PageRegion, ContentTemplate) fork via the shape of their language overrides: an
  override carrying only translatable input values is symmetric (structure falls through to the base config), an
  override carrying the full component tree is forked. No flag is stored, because nothing re-syncs override contents,
  so the derived-state objection that applies to content entities does not apply here.

## Consequences

- Divergent translations become an explicit, reversible, per-translation state instead of a site-wide mode, and
  pre-existing divergent data can be preserved on upgrade by marking those translations forked rather than being
  clobbered by the now-guaranteed symmetric synchronization.
- String-based translation tools cannot operate on forked translations: their premise (a shared tree with per-key
  input overrides) no longer holds. Consumers must detect fork state and degrade gracefully; publishing a string draft
  onto a forked translation must be refused.
- Fork and unfork drafts publish and discard with the whole entity group, since the publish pipeline hides
  non-default-translation auto-saves; a fork-only change requires a default-translation draft to publish alongside
  until per-language publish lands.
- The Canvas editor is the editing surface for forked trees; where its translation surface is preview-only, diverging
  a forked tree happens through the HTTP and entity APIs until translation editing lands in the editor.
- Unforking is destructive by design (fork-only components are discarded); the UI requires type-to-confirm before
  calling it.
