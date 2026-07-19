# 20. Multiselect batch operations

Date: 2026-07-19

## Status

Accepted

## Context

The Canvas editor lets users select multiple component instances at once (meta/cmd+click in the preview and the
layers panel). A selection is an ordered list of component UUIDs plus a derived flag stating whether the selected
components are consecutive siblings — same parent region or slot, adjacent positions. The selection enforces
parent-child exclusion: a component and one of its descendants can never be selected together, because an operation
that acted on both would process the descendant twice.

Operations on such a selection — delete, copy, paste, duplicate, save as pattern, move — need three policy decisions:

- What is the unit of work? If each selected component is mutated by its own action, one user gesture produces N
  undo history entries and N preview refreshes, and undo after "delete five" silently restores only one component.
- What order do group results use? Users select components in arbitrary click order, but expect "copy these three"
  to reproduce what they see on the page.
- Which selections are valid inputs for operations that produce a new group in the layout? An arbitrary
  non-contiguous selection has no single well-defined insertion point, and reproducing it elsewhere forces a policy
  on collapsing the gaps between its members.

## Decision

**One gesture, one action.** Every batch operation mutates the layout model in a single dispatched action. The undo
history therefore contains exactly one entry per user gesture regardless of selection size, and undo/redo restores
or removes the whole batch at once. Batch reducers accept arrays and reuse the same per-node mutation logic as their
single-item forms; a single-item operation is simply a batch of one.

**Document order, not click order.** Before an operation runs, the selection is sorted into document order: the
order components appear when the layout tree is read region by region, depth first. Copy places subtrees on the
clipboard in that order, paste and duplicate insert their results in that order, and save as pattern stores the
subtrees in that order. Click order is deliberately discarded — the page as seen is the contract. Lexicographic
comparison of tree paths yields this order; when an ancestor and a component inside a later sibling are both
selected, the ancestor's shorter path sorts first.

**Group-producing operations require consecutive siblings.** Copy, duplicate, and save as pattern are only available
when the selection is consecutive siblings. A consecutive run is a contiguous list of subtrees with one unambiguous
insertion point (directly after the run), so "duplicate" and "paste" have obvious, predictable results. When the
selection is not consecutive, these actions are disabled and the UI explains that they require adjacent items.
Delete is exempt: removing components is well defined for any selection, including one spanning different parents,
because parent-child exclusion guarantees no member is inside another member's subtree.

**Move by drag is also exempt from the consecutive constraint.** Dragging any member of the selection moves the
whole selection to the drop position; dragging a non-member moves only the dragged component. Unlike duplicate or
paste, a drag's insertion point is not inferred from the group — the user chooses it explicitly — so collapsing a
non-consecutive selection into a contiguous run at the drop point is well defined, and parent-child exclusion
guarantees the collapse cannot nest a component into its own subtree. Two drops are rejected as no-ops: a drop
inside a selected component's subtree (the target would vanish with the moved original), and a consecutive group
dropped onto its own position.

**Selection state is the operation input.** Operations read the selection from the editor state, not from the URL.
The URL only ever carries a single component (as a deep link to its props form) and is empty during multi-select, so
keyboard shortcuts and menus keyed to the URL would go dead exactly when a multi-selection exists.

**Selection afterwards.** Delete clears the selection. Duplicate and paste select the newly created components, in
document order, so a follow-up operation (move, delete, duplicate again) applies to the result. Move keeps the moved
components selected, with their adjacency recomputed against the new layout — a scattered selection becomes
consecutive once dropped as a group, which enables the group-producing operations on it.

## Consequences

- Undo behaves predictably: one undo step reverses one user gesture, and the layout model's history length matches
  the number of gestures, not the number of affected components.
- The layout is mutated once per gesture, so the preview pipeline re-renders once per gesture.
- The consecutive-siblings constraint trades capability for predictability: users cannot copy or duplicate a
  scattered selection. Lifting the constraint later (for example, collapsing a non-contiguous selection into a
  contiguous group on paste) is additive and does not conflict with this decision.
- Because a pattern's content is a flat list of component subtrees, a pattern created from a consecutive run of N
  components is representable without any schema change.
- Batch operations compose client-side tree mutations and persist through the same auto-save pipeline as single
  operations; the server needs no batch awareness.
