# Slot restrictions review — lauriii/canvas PR #71

Branch reviewed: `slot-component-restrictions` @ `300c5444` (base `6181b9f1`).
Spec reviewed against: canvas-specs PR #13, branch `slot-component-restrictions`
(`changes/slot-component-restrictions/{proposal,design,tasks}.md` +
`specs/{slot-restrictions,component-system,component-tree}/spec.md`).
Core schema checked against `web/core/assets/schemas/v1/metadata.schema.json` (Drupal 11.3.13).

**Verdict: do not merge yet.** The core mechanism is sound and, where it is wired up, it
works exactly as specified. Two defects are ship-blocking (`openapi.yml` out of sync
causes an HTTP 500 that takes down the whole component library; grandfathering breaks on
reorder), and two more are serious client/server disagreements that leave an author with
an unpublishable page and no way to see why. Details and severity ranking below.

---

## 1. How this was tested

- Checked out the branch in the site-6 ddev clone, rebuilt the production UI
  (`npm run build`, 4.34 MB `ui/dist/assets/index.js`), `drush cr`, logged in as admin.
- Wrote a throwaway fixture module at `web/modules/custom/slotcheck` (outside this repo,
  so the PR diff stays clean) with SDCs exercising every branch of the resolver:

  | Component | Declares |
  | --- | --- |
  | `SC Container` | `strict` (`expected: [slotcheck:sc-card]`, `minItems: 1`, `maxItems: 2`), `tagged` (`expected: [promo]`), `mixed` (`expected: [sdc.slotcheck.sc-card, promo]`), `free` (nothing), `typo` (`expected: [slotcheck:does-not-exist, no-such-tag]`), `onlycard` (`expected: [slotcheck:sc-card]`) |
  | `SC Card` | no tags — matched by direct ID |
  | `SC Promo` | `tags: [promo]` — matched by tag |
  | `SC Plain` | no tags — the component nothing expects |
  | `SC Legacy` | `allowedComponents: [slotcheck:sc-card]` — the pre-#3514072 spelling |

- Browser work through `agent-browser` (2 sessions), dnd-kit drags driven by synthetic
  `PointerEvent`s. Server behaviour probed with `drush scr` scripts calling
  `$entity->get('components')->validate()` and `SlotRestrictions::accepts()` directly,
  so that grandfathering could be tested by mutating a *stored* tree.
- Evidence screenshots are in `review-evidence/`.

**One local patch was needed to test at all** and was reverted afterwards: `openapi.yml`
had to be given a `tags` property (see finding 1). The working tree is clean.

---

## 2. Manual test results

| # | Scenario | Result | Evidence |
| --- | --- | --- | --- |
| 1 | `expected` by **direct SDC plugin ID** — drag `SC Card` into `strict` | **Pass.** Accepted, rendered in the slot. | `strict => sc-card` |
| 2 | Same slot refuses a non-listed component — drag `SC Plain` into `tagged` | **Pass.** Refused; nothing inserted anywhere in the tree (`.sc-plain` count unchanged). | `review-evidence/03-invalid-drag-no-feedback.png` |
| 3 | `expected` by **tag** — drag `SC Promo` (tagged `promo`) into `tagged` | **Pass.** Accepted. | `tagged => sc-promo` |
| 4 | Dotted Canvas ID form (`sdc.slotcheck.sc-card`) resolves | **Pass.** `mixed` advertises "Accepts SC Card and SC Promo". | DOM read of `_emptySlotAccepts_` |
| 5 | Newly tagged component becomes eligible | **Pass.** Adding `tags: [promo]` and rebuilding caches moves the component into the candidate set without a version bump. | §4, hash unchanged |
| 6 | **Fail open** — `typo` slot, no entry resolves | **Pass.** Accepts anything, client and server agree. | `typo => sc-plain`; probe E |
| 7 | **`maxItems`** — 3rd `SC Card` into a full `strict` (2 of 2) | **Pass.** Drop refused, nothing inserted. | probe C |
| 8 | `maxItems` counter in Layers | **Pass.** "Strict — 2 of 2"; unrestricted slots show no counter. | `review-evidence/04-layers-slot-counter.png` |
| 9 | **`minItems`** unmet at publish | **Pass, as designed.** Publish is not blocked (probe Q2 clean). **But** nothing surfaces the shortfall: the Review panel item (spec task 5.1) is not implemented; only the Layers counter hints at it. | probe Q2 |
| 10 | **Grandfathering** — tighten `maxItems` 2 → 1 under stored content | **Pass.** Unchanged tree validates clean; adding a 3rd child is still rejected; deleting children is clean. | probes A, C, M, N |
| 11 | **Grandfathering** — narrow `expected` under stored content | **Pass.** Unchanged tree clean, new invalid child rejected. | probes K, L |
| 12 | **Grandfathering — reorder** two grandfathered children in an over-full slot | **FAIL.** Reordering, which adds and removes nothing, produces a fresh `maxItems` violation and makes the page unpublishable. | probe F, finding 2 |
| 13 | Moving a grandfathered child into a slot that refuses it | **Pass.** Re-evaluated and rejected. | probe G |
| 14 | **Nested slots** — container inside `free`, invalid child in its `strict` | **Pass.** Violation reported against the nested parent. | probe H |
| 15 | Unknown parent / unknown slot name | **Pass.** Only the pre-existing structural violation is reported; no duplicate restriction noise. | probes I, J |
| 16 | **Server rejects at publish → 422** | **Pass.** `422`, message "Component SC Plain is not expected in the Tagged slot of SC Container. Expected: promo." Not a 500. | `review-evidence/06-publish-422.png` |
| 17 | **Config trees** (`Pattern`) enforced | **Pass.** Violation reported on `component_tree.N.slot`. | probe P2 |
| 18 | Config trees **grandfathered** | **FAIL.** A pattern already saved with a violation reports it again on every subsequent validation. | probe P3, finding 5 |
| 19 | Empty-slot advertisement | **Partial.** Text is generated correctly ("Accepts SC Card", "0 of 2, at least 1"), but it is **clipped and invisible** in a default-height empty slot. | `review-evidence/02-empty-slot-hints-clipped.png`, finding 6 |
| 20 | Refused drag feedback | **Partial.** The slot correctly never highlights, but the author is told nothing — no reason, no "what fits" hint. | `review-evidence/03-invalid-drag-no-feedback.png` |
| 21 | **Paste** into a restricted slot | **FAIL.** ⌘V places any component into any slot with no check. | `review-evidence/05-paste-bypasses-restriction.png`, finding 3 |
| 22 | Insert / duplicate / "Move into" gating | **Pass** by code inspection (`disabled` + early return + slot filtering). Not exercised end-to-end: the Radix menus close under synthetic clicks in this harness. | — |
| 23 | Legacy `allowedComponents` | **Ignored, on both sides** — slot behaves as unrestricted. This matches the spec's deliberate decision (tasks 2.3), and *contradicts the review brief*, which expected a working fallback. See finding 10. | probe P1 |
| 24 | Component version hash unaffected by restriction edits | **Pass.** `feebbf4d3d178fcf` before and after `maxItems: 2 → 4`. | §4 |
| 25 | Console cleanliness | **Pass** (once finding 1 is patched): editor loads with zero console errors or warnings. | `review-evidence/08-console-clean.png` |

---

## 3. Code review findings, most severe first

### 1. BLOCKER — `openapi.yml` rejects the new `tags` property, so the component list 500s

`src/Plugin/Canvas/ComponentSource/JsonSchemaPropsComponentSourceBase.php:1211` adds `tags`
to the client-side info, but `openapi.yml:3615` declares `Component` with
`additionalProperties: false` (line 3629) and no `tags` property. The response validator
(`src/EventSubscriber/ApiResponseValidator`, active whenever
`league/openapi-psr7-validator` is installed with assertions on — i.e. every dev site and
every CI job) then fails the response:

```
GET /canvas/api/v0/config/component
500 {"message":"Body does not match schema … [Keyword validation failed:
      Data has additional properties (tags) which are not allowed in sdc.slotcheck.sc-promo]"}
```

One tagged component anywhere on the site takes down the entire component library — the
Library panel shows only "Try again" and Canvas is unusable. This reproduced immediately
on a stock dev site (`review-evidence/01-components-api-500.png`).

CI is green today only because nothing enables the PR's own fixture module
(`tests/modules/canvas_test_slot_restrictions`, whose `restricted-child` declares
`tags: [canvas-test-tag]`) in a functional/API test. The moment a functional test does, CI
goes red.

**Fix:** add `tags` to the `Component` schema in `openapi.yml`, and add a functional test
that enables `canvas_test_slot_restrictions` and hits
`/canvas/api/v0/config/component`, so this can never regress silently. The spec's Impact
section and task list should mention `openapi.yml` too — task 6.2 only covers
`JavaScriptComponent`.

### 2. HIGH — reordering inside a grandfathered over-full slot un-grandfathers it

`src/SlotRestrictions.php:98` attributes the `maxItems` violation to whichever children
happen to sit at `position >= maxItems`, and `src/SlotRestrictions.php:346` keys the
violation on that child's UUID:

```php
if (\is_int($max_items) && $position >= $max_items) { … self::key(self::RULE_MAX_ITEMS, $child['uuid'], $group) … }
```

Swap two children inside an over-full slot and the surplus position is occupied by a
different UUID, so the key changes, `array_diff_key()` in
`ComponentTreeStructureConstraintValidator.php:226` no longer suppresses it, and a
pure reorder is reported as a new violation:

```
--- F. Reorder the two stored SC Cards inside `strict`   (maxItems tightened 2 → 1)
    [components.3.slot] The Strict slot of SC Container accepts at most 1 components, but 2 were provided.
```

This directly violates the spec: *"A violation SHALL be considered pre-existing only when
the same component instance occupies the same slot of the same parent under the same
rule"* — a reorder changes none of those. It also breaks the proposal's promise that
tightening a rule "can never make 200 already-published pages unpublishable": an author
who merely drags one existing card above another can no longer publish.

It is also a client/server disagreement: `slot-utils.ts:162-168` deliberately exempts
reorders (`isReorderWithinSlot`) so a full slot can still be reordered, so the client
*permits* exactly the operation the server then rejects.

**Fix:** make the `maxItems` violation slot-scoped rather than child-scoped — key it on
`(rule, parent_uuid, slot)` and report it once against the slot (the message already says
"but @count were provided", which is a slot-level statement, not a per-child one). Keep
`expected` keyed per child.

### 3. HIGH — paste and AI placement are not gated

The tasks file claims "Implemented: every placement path is gated" (§3), but two paths
are not:

- `ui/src/hooks/useCopyPasteComponents.ts:56` `pasteAfterSelectedComponent()` dispatches
  `insertNodes` with no restriction check. Reachable from ⌘V
  (`ui/src/features/editorFrame/EditorFrame.tsx:141`) and from the context menu's Paste.
  Verified live: copy `SC Plain`, select the `SC Promo` inside `tagged`, ⌘V → `SC Plain`
  lands in a slot that only accepts `promo`, with no warning
  (`review-evidence/05-paste-bypasses-restriction.png`). Publishing then fails with 422.
- `ui/src/components/aiExtension/AiWizard.tsx:278` dispatches
  `addNewComponentToLayout` at an AI-chosen `nodePath` with no check (spec task 6.3, not
  started — but the spec's own requirement says the UI SHALL prevent invalid placements on
  "AI-driven placement").

Both produce the worst outcome the two-layer design is meant to avoid: an author reaches a
state the client allowed and publishing refuses. Paste is the urgent one — it is a
one-keystroke path a real author hits by accident.

**Fix:** route paste through `useCanPlaceInSlot` and no-op (or fall back to the nearest
accepting ancestor) when it refuses; gate the AI path or file it explicitly as a known gap
in the PR description.

### 4. HIGH — client and server resolve `expected` against different component sets

`ui/src/features/layout/slot-utils.ts:105` resolves a component reference against the
component list the client holds — which contains only **enabled**, non-broken components:

```ts
if (components[reference]) { allowed.add(reference); } else { unresolved.push(entry); }
```

`src/SlotRestrictions.php:245` resolves against **all** `Component` config entities,
enabled or not:

```php
: $component_storage->load($reference) !== NULL;
```

Disable a component named in a slot's `expected` and the two halves invert:

- client: nothing resolves → `allowed: null` → the slot is treated as **unrestricted**;
- server: the entry still resolves → the slot **refuses everything else**.

Verified live. With `SC Card` disabled, the `onlycard` slot dropped its "Accepts …" line
and accepted `SC Plain` by drag (`review-evidence/07-disabled-component-divergence.png`),
while the server refuses the identical tree:

```
--- Q1. SC Plain in `onlycard` (only expected entry is the DISABLED SC Card)
    [components.1.slot] Component SC Plain is not expected in the Only card slot of SC Container. Expected: SC Card.
```

The same asymmetry exists on the tag side (`slot-utils.ts:112` scans the client list;
`SlotRestrictions.php:264` scans `loadMultiple()`), and applies equally to components
hidden from the library for any other reason.

**Fix:** make the server's resolution agree with what the client is served — treat a
disabled/unavailable component as unresolvable in `hasResolvableEntry()` and
`componentIdsWithTag()`. Whichever way it is decided, the two resolvers must use the same
notion of "exists", and the shared PHP/TS fixture that tasks 3.2 flags as "the single most
valuable addition" would have caught this.

### 5. MEDIUM — config entities get no grandfathering

`ComponentTreeStructureConstraintValidator::getPreviouslyStoredTree()` returns `[]` unless
the tree arrived as a `ComponentTreeItemList` (`…ConstraintValidator.php:254-255`), which
is never the case for `ContentTemplate`, `PageRegion` or `Pattern` — those validate as
typed config. The `@todo` at line 252 acknowledges it.

Consequence: a pre-existing violation in a config tree is reported on **every** save.
Verified with a `Pattern` saved with an invalid placement and then re-validated
unchanged (probe P3). A site builder who tightens a restriction can no longer save an
already-violating content template at all — which is the exact failure mode
`proposal.md` promises cannot happen, and which the spec forbids ("A violation that
already exists in the stored tree SHALL NOT block a subsequent write").

**Fix:** load the saved config object for config entities, as tasks 2.4 originally
described. If that is deferred, the spec delta must say so — right now the spec claims a
guarantee the code does not provide.

### 6. MEDIUM — the empty-slot advertisement is rendered but invisible

`EmptySlotDropZone.tsx:96-107` renders the "Accepts …" and "0 of N" lines inside a flex
container that is `overflow: hidden` at the slot's natural height. Measured on a default
empty slot:

```
zone   height 30px, clientHeight 28, scrollHeight 59, overflow hidden
accepts line  top 207.6  bottom 224.1   (zone bottom is 214.6)
```

The text exists in the DOM and is correct, but the author never sees it — so the one
authoring-UX affordance this PR ships (task 4.1) does not actually reach the user.
`review-evidence/02-empty-slot-hints-clipped.png`.

**Fix:** let the zone grow (`min-height` + `overflow: visible`, or move the lines into the
slot label) — and check it at the small slot heights that are the common case, not just in
a tall test slot.

### 7. LOW — violation message has no plural handling

`src/SlotRestrictions.php:100`: `'The %slot slot of %parent accepts at most @max components, but @count were provided.'`
produces "accepts at most 1 components, but 2 were provided". Use
`TranslatableMarkup`'s plural formula, or reword to avoid the count-adjacent noun.

### 8. LOW — `useSlotCandidates` is dead code

`ui/src/hooks/useSlotRestrictions.ts:72-85` has no callers outside its own module. It
exists for the per-slot add menu (task 4.2), which is not implemented. Delete it until
4.2 lands; an exported hook with no consumer is untested surface area.

### 9. LOW — per-drop-zone layout scans

`useSlotRule` / `useSlotTitle` (`useSlotRestrictions.ts:52`, `:141`) each run
`findComponentByUuid(layout, …)` over the whole layout, memoised on `[slot, layout,
components]`. Every `ComponentDropZone`, `SlotDropZone`, `EmptySlotDropZone` and
`LayersDropZone` calls them, so the work is O(zones x tree) and `layout` changes on every
edit — a full re-scan per zone per keystroke. Fine for the trees tested here; worth a
memoised uuid→node index before large pages meet it.

### 10. LOW / product — `allowedComponents` is not implemented, by design

The review brief asks to confirm the legacy fallback "still works"; the spec (tasks 2.3,
design revision 3) says it is **deliberately not shipped** pending product question 2, on
the sound argument that it cannot be half-shipped. Verified: a slot declaring
`allowedComponents` is treated as unrestricted on both sides (probe P1) — which is at
least *consistent*, and therefore safe. Flagging only because brief and spec disagree:
somebody needs to close product question 2 before this lands, since the community
implementation (#3563163, ITCare) uses that spelling.

### 11. NIT — the `fallback` docblock describes a path that is not taken

`src/SlotRestrictions.php:158-161` says a fallback-sourced component "has no live metadata
to read, and falls back to the restrictions recorded for its last active version", implying
the `catch (PluginException)` branch at line 176. In fact `Fallback` implements
`ComponentSourceWithSlotsInterface` (`Fallback.php:30`), so the *first* branch is taken and
`Fallback::getSlotDefinitions()` (`Fallback.php:155`) returns the recorded
`fallback_metadata.slot_definitions` by way of `Component::sourcePluginCollection()`
(`Component.php:228`). The outcome is right; the explanation points at the wrong line, and
the `catch` block is only reached when the source plugin ID itself is unknown.

Related and worth a sentence in `design.md`: because restrictions are excluded from the
version hash, `fallback_metadata.slot_definitions` is only refreshed when *something else*
changes the hash. Verified: after editing `maxItems: 2 → 4`, the live source reported `4`
while `Component::getSlotDefinitions()` still reported `2`. So a degraded component
enforces whatever restrictions were current at the last hash-changing save, which can be
arbitrarily stale. That is an acceptable trade, but it is not what "the restrictions that
were in effect for its last active version" (`Component.php:722-725`) suggests.

### 12. NIT — two sources of truth inside one validator

`ComponentTreeStructureConstraintValidator.php:391` reads slot definitions from the
**config entity** (`$parent_config_entity->getSlotDefinitions()`) to check the slot name,
while the new code reads them from the **live source**
(`SlotRestrictions::slotDefinitions()`). Harmless today, because slot names *do* change the
version hash and so keep the config entity current. Worth a comment so the next person
does not "fix" one of them.

---

## 4. Spec conformance

| Spec requirement (`specs/slot-restrictions/spec.md`) | Status |
| --- | --- |
| Restrictions read from core metadata without extension | **Met.** Verified `expected`/`minItems`/`maxItems` survive into `fallback_metadata.slot_definitions` and reach the client verbatim. |
| ‥ tags read from the plugin definition, not `ComponentMetadata` | **Met.** `JsonSchemaPropsComponentSourceBase::getTags()` reads `getPluginDefinition()['tags']`; `getTags()` on `sdc.slotcheck.sc-promo` returned `["promo"]`. Core's `ComponentMetadata` indeed exposes no `tags`. |
| ‥ restrictions do not change the version hash | **Met.** Hash unchanged across a `maxItems` edit. |
| ‥ core schema tolerates the new keys today | **Met, and the design's claim verified.** `metadata.schema.json` sets `additionalProperties: false` only on the object holding slot *names*; the per-slot object and the document root do not, so `expected`/`minItems`/`maxItems`/`tags` validate against released core. |
| `expected` resolves to a candidate set, failing open | **Met** for the happy path; **broken across the client/server boundary for disabled components** (finding 4). |
| Invalid placements prevented on every path | **Partly met.** Drag, insert, duplicate, "Move into" gated; **paste and AI are not** (finding 3). |
| The UI states what each slot accepts | **Partly met.** Strings are correct and generated from metadata; the empty-slot lines are clipped (finding 6) and the refused-drag reason is not implemented (design 3.6 / task 4.4, acknowledged). |
| Server rejects newly-introduced violations, 422 | **Met**, content and config trees both. |
| Pre-existing placements grandfathered | **Partly met.** Correct for add/delete/narrow on content entities; **broken for reorder** (finding 2) and **absent for config entities** (finding 5). "Grandfathered violations SHALL be visible to the author and offer remediation" is not implemented (task 5.1-5.3, acknowledged as not started). |
| `minItems` a publish-time obligation | **Half met.** Correctly not enforced at write time; the Review-panel surfacing that makes it an *obligation* rather than a no-op is not implemented. As shipped, `minItems` does nothing except tint a Layers counter. |

The `component-system` delta's scenario "Restrictions survive source degradation" is met
(finding 11 notes the mechanism, and the staleness caveat).

---

## 5. Automated checks

| Check | Result |
| --- | --- |
| `ddev phpunit tests/src/Kernel/Plugin/Validation/ComponentTreeStructureConstraintValidatorTest.php` | **34 tests, 36 assertions, pass** (9 pre-existing deprecations). |
| `npx vitest run src/features/layout/slot-utils.test.ts` (host) | **15 tests, pass.** |
| `ddev phpcs` on the 6 changed PHP files | **No violations.** |
| `npx tsc --noEmit` (ui) | **Clean.** |
| `npx eslint` on the 12 changed UI files | **Clean.** |
| `npx prettier --check` on the changed UI files | **Clean.** |
| `ddev phpstan` | **Could not run** — pre-existing env breakage, unrelated to this PR: `modules/canvas_headless/…/JsComponentCanvasRenderConverter.php` needs `Drupal\custom_elements`, which is not installed. CI on PR #71 is the source of truth here. |

**Test coverage gap worth fixing with finding 2:**
`testPreExistingViolationsAreGrandfathered()` calls `SlotRestrictions::violations()` twice
and re-implements the suppression with its own `array_diff_key()`. It therefore never
exercises `getPreviouslyStoredTree()`, never touches a config entity, and — because it
hand-picks its two trees — cannot catch the reorder case. A test that mutates a *saved*
entity and validates it would have caught findings 2 and 5. Likewise, tasks 3.2 already
names the shared PHP/TS resolver fixture as the highest-value missing test; finding 4 is
precisely the class of bug it exists to catch.

---

## 6. Verdict

The architecture is right and the parts that are wired up behave exactly as the spec
describes — resolution by ID and by tag, fail-open, nesting, cardinality, 422 at publish,
config trees, no version churn. The tasks file is unusually honest about what is not done,
which made this easy to review.

**Blocking merge:**

1. Finding 1 — `openapi.yml` (500 on the component list; trivial fix, but it makes the
   feature undemoable and will redden CI as soon as a functional test enables the fixture).
2. Finding 2 — reorder breaks grandfathering (contradicts the spec, and the client permits
   the operation the server rejects).

**Should be fixed before merge, or explicitly accepted in the PR description as known gaps
with follow-up issues:**

3. Finding 3 — paste is ungated; a one-keystroke path to an unpublishable page.
4. Finding 4 — disabled components invert the rule between client and server.
5. Finding 5 — config trees are enforced but not grandfathered, contradicting the spec.
6. Finding 6 — the shipped empty-slot hint is invisible.

**Also needed before this is a coherent product increment:** close product question 2
(`allowedComponents`), and either implement the `minItems` Review-panel item or drop
`minItems` from the shipped scope — right now it is read, stored, counted, and then
ignored.
