# Review: Canvas !1523 vs !1484 (issue #3591930, optimistic Brand kit color edits)

Reviewed 2026-08-21 on a ddev clone of the Canvas module, against `upstream/1.x` at `1a369531`.
Both MRs were checked out, built (`npm run build:app` in `ui/`), installed on the live site, and
exercised in a real browser (agent-browser, Chromium) with `window.fetch` wrapped in the page to
delay, fail, or record specific requests. An adversarial sub-agent then attacked the first draft of
this report for bias toward the larger MR and toward its author, who commissioned the review; the
challenges that held are folded in below and the recommendation changed because of them.
Nothing was posted anywhere.

- !1523 `3591930-RTK-optimistic` at `9083e7cc` (bnjmnm): 4 files, 278 changed non-test lines, 125 test lines.
- !1484 `3591930-apply-brand-kit-colour-changes-optimistically` at `c90585c0` (lauriii): 13 files, 558 changed non-test lines (roughly a third doc comments), 629 test lines.
- Latest CI pipeline on both: success. Neither MR's new Playwright tests are executed by the
  pipeline (it runs `canaryMinimal.spec.ts`), so the Playwright results below are local only for both.

## 1. The question: what does !1484 solve that !1523 does not?

The "Upstream" column is the baseline; without it, pre-existing gaps read as defects !1523 created.
Only rows 3 and 4 (and the hidden-form issues in §2.4) are regressions that !1523 itself introduces.

| # | Behaviour | Upstream | !1523 | !1484 | Evidence |
|---|---|---|---|---|---|
| 0 | Edit applied to the row before the response | No: waits on the round trip with a spinner, then closes | Yes, ~103 ms. Form hidden (`opacity: 0`) until the response, then closed (`ColorFormPopover.tsx:377-382`) | Yes, ~136 ms. Form closed at once (`ColorFormPopover.tsx:322-323`) | Swatch logs, PATCH delayed 3 s, both branches |
| 1 | Edit visible while a Brand kit auto-save draft exists | No: form closes on success, row never changes | No: identical to upstream (patches `getBrandKit` and `getBrandKits`, never `getAutoSave`; `invalidatesTags` unchanged, `brandKit.ts:216-219, :277-280, :333-336`). Row still green 20 s after the server held `#ff0000` | **Regression**: row and preview update, the write persists, then about 4 s after success the row flips back to the stale value with no error — it reads as a failed save (§1.1) | Draft created via `PATCH …/auto-save/brand_kit/global`; PATCH delayed 3 s |
| 2 | Page preview reflects the change without an editor reload | No | No: identical to upstream. 50 s after success the preview still reported the old `--brand-red`; the preview re-POST returns identical HTML so `IframeSwapper` never swaps. No preview file touched | Yes: `usePreviewBrandKitColors` injects `<style id="canvas-brand-kit-colors">`; `--brand-red` changed in both iframes at ~150 ms and rolled back on rejection. Untested by any automated test | Preview `getComputedStyle(documentElement).getPropertyValue('--brand-red')` |
| 3 | Rejected delete is reported | Yes: error card in the still-open popover | **Regression**: row vanishes and reappears ~1.7 s later with no message. `onOpenChange(true)` at `DeleteColorPopover.tsx:39` runs on a component that unmounted with the row; the error only reaches `console.error` | Yes: row reappears, section shows "Failed to delete color: …" (`BrandKitColorsSection.tsx:65, :269`) | DELETE forced to 500 after 1.5 s |
| 4 | Stale failure does not revert a newer edit | n/a (edits are serial) | **Regression** in the interleaving: edit 1 (PATCH held 8 s, then 500), edit 2 saved at 7.2 s → row showed edit 2, reverted to the pre-edit value at 8.3 s, stayed ~5.5 s while edit 2's queued request ran, then showed edit 2. The form also re-opened with no error (§2.3) | Yes: same interleaving, row kept edit 2 throughout (`isNewest`, `brandKit.ts:111-120`). But !1484 sends concurrent PATCHes with no ordering guarantee on the server (§2.3) | Swatch log on !1523: `200 → 10 (t=8508) → 200 (t=14002)` |
| 5 | Optimistic create | No | Yes, temporary id swapped on success (`brandKit.ts:17-20`); no API change; rejected add un-hides the form on the entered values with "Failed to create color". Hazard: row interactive under a fake id (§2.4) | Yes, client-minted UUID (`uuidv4()`, `ColorFormPopover.tsx:278`), which needs `Color::createFromClientSide()` to honour `id` and a public `openapi.yml` change (§1.2); rejected add reopens the form on the values with the error | POST forced to 422 after 1.5 s; POST delayed 6–7 s |
| 6 | In-flight write survives a read landing mid-write | No | No | Yes (`reapplyPendingColorWrites`, `brandKit.ts:81-90`), but narrow: RTK's default `invalidationBehavior: 'delayed'` already defers mutation-triggered refetches until no mutation is pending (both MRs), so this covers reads already in flight when the write starts | Code reading; Vitest on !1484 |
| 7 | Automated coverage | — | 1 Playwright test (passes here; never holds a request, so it does not observe the optimistic window). No Vitest, no PHP | 4 Playwright (pass here; two would pass on upstream by inspection, §2.6), 9 Vitest (pass; one leaks state, §2.6), 3 kernel tests (pass). Preview injection: no coverage | §4 |
| 8 | MR description | — | Unfilled template, no testing instructions, AI-disclosure block unedited | Detailed but stale: says creation "still waits" and lists seven Vitest cases; the branch makes creation optimistic (`5c010797`), has nine cases, and does not mention the `IframeSwapper` commits or the draft behaviour in §1.1 | MR API |

### 1.1 The draft case: a pre-existing one-line hook quirk, made worse by !1484, fixed by neither

`useBrandKitColors.ts:27-33` prefers `autoSaveBrandKit.data.colors` over the canonical colors. But a
Brand kit draft can never carry different colors: the entity exports only `id`, `label`, and `fonts`
(`src/Entity/BrandKit.php:82-86`), `useBrandKitFonts` only ever PATCHes `fonts`, and the auto-save GET
re-normalizes colors from the live `canvas.color.*` entities (`ApiConfigAutoSaveControllers.php:29`,
`BrandKit::normalizeForClientSide()`). The preference is semantically meaningless; it only decides
which of two copies of the same data the UI reads, and which cache entry must be patched and refetched.

Two consequences:

- The minimal correct fix for rows 1 and 6 is one line in `useBrandKitColors` (take colors from the
  canonical entry; keep the draft for fonts). With that, !1523 works with drafts as-is, and !1484's
  `getAutoSave` patching, its `getAutoSave` re-application, and its `BrandKitsAutoSave` invalidations
  on the three color mutations become unnecessary. Neither MR considers this; the first draft of this
  report did not either.
- Without it, !1484 is worse than upstream with a draft present. Its `BrandKitsAutoSave` invalidation
  refetches the draft, and the refetch is served by Drupal's dynamic page cache as a **HIT with the
  pre-edit colors**, because `ApiConfigAutoSaveControllers::get()` keeps only `->values` and drops the
  Color cache tags that `BrandKit::normalizeForClientSide()` adds (`BrandKit.php:158-176`):

  ```
  GET /canvas/api/v0/config/brand_kit/global            X-Drupal-Dynamic-Cache: MISS  Brand Red #0000ff (fresh)
  GET /canvas/api/v0/config/auto-save/brand_kit/global  X-Drupal-Dynamic-Cache: HIT   Brand Red #ff0000 (stale)
  ```

  The row shows the new value, then flips back to the stale one about 4 s after a successful save,
  with no error; the preview (canonical) stays correct. A draft exists after any font edit until it is
  published, so this is a common state. That is the same class of defect this report holds against
  !1523 in §2.3 (a revert of a value the user chose), and it must be a blocker for !1484, not optional.

The stale-cache behaviour of the auto-save route is a separate, pre-existing server bug (the response's
only cacheability is the auto-save entry and the Brand kit config, which `Color::postSave()` never
invalidates). It stops mattering to the colors UI once the hook reads canonical colors; it still
deserves its own issue.

### 1.2 Why !1484 needs `src/Entity/Color.php` and `openapi.yml`

Only for optimistic creation under the final identifier: the client mints the UUID, the Color
schema has `additionalProperties: false`, so `id` must be allowed in `openapi.yml:4784-4790`, and
`Color::createFromClientSide()` previously discarded `id` (`Color.php:131-137`). A duplicate UUID already
surfaces as a 409 via `ApiConfigControllers::post()`; three new kernel tests cover honoured, omitted, and
duplicate ids; phpcs is clean. It is required *for the approach chosen*, not for optimistic creation as
such: !1523 shows optimistic creation without touching the public v0 contract. Adding an optional `id`
to a public POST is a permanent API surface added for a UI nicety, it has no HTTP-level test
(`CanvasConfigEntityHttpApiTest.php` is untouched; no test sends a malformed or duplicate id over
HTTP), and it deserves its own explicit decision rather than riding along. So: !1523 is not incomplete
for lacking the backend change; the two MRs made different trade-offs (fake id with hazards vs a
contract change).

## 2. Review of !1523 on its own merits

### 2.1 What it does well

- The optimistic path is the documented RTK Query pattern: `onQueryStarted` → `updateQueryData`
  patch → `await queryFulfilled` → `patchResult.undo()` on rejection, for create, update, and
  delete. Readable, no new abstractions beyond two helpers, no backend or API change.
- Update patches in place (`Object.assign(color, changes)`), so immer's inverse patch is narrow and a
  failed edit of color A does not clobber an in-flight edit of color B. (Delete is a whole-array
  `filter`, whose undo restores every later color; the same is true of !1484's `splice`, see §2.6.)
- Rejected edit and rejected add both re-show the form on the entered values with the existing
  in-context error card, so the user can correct and retry. !1484 gives this up for edits (§2.6). The
  `hasInitializedForOpenRef` guard (`ColorFormPopover.tsx:224-235`) stops cache churn from
  re-initialising the open form; a sound fix for a real problem in this design.
- The global write lock (§2.3) has one real benefit the first draft of this report omitted: it
  guarantees the server applies color writes in the order the user made them. !1484 sends concurrent
  PATCHes to the same color; if the server finishes them out of order, storage ends with the older
  intent and nothing on the client notices. Rare, but !1523 cannot lose the last intent that way.
- Its Playwright test covers edit-fail, edit-success, add-fail, add-success, and re-opens both forms to
  assert persisted values. It passes here (6.3 min, §4).

### 2.2 Correctness issues

1. **Draft present: no change, before or after success.** Pre-existing (identical to upstream), but
   the MR's whole purpose is "the color shows up immediately", and it does not in that state. The
   second patch target, `getBrandKits` (`brandKit.ts:160, :193, :254, :311`), has no subscriber in
   `ui/src`, so it is a no-op; the entry the UI prefers (`getAutoSave`) is never patched or refetched.
   One line in `useBrandKitColors` (§1.1) would make this MR correct here.
2. **Silent delete failure** (row 3). A regression: the optimistic removal unmounts `ColorRow` and the
   popover inside it; the catch has nothing to reopen and the hook state is gone. A failure is
   indistinguishable from a flicker.
3. **Preview never updates** (row 2). Pre-existing; not addressed.
4. **Stale failure reverts a newer edit for as long as the queued write takes** (row 4, §2.3). A
   regression, and the lock makes the window longer, not shorter.

### 2.3 The global write lock

`runColorWriteLocked` (`brandKit.ts:36-51`) chains every color write on one module-level promise and
wraps each `queryFn` in it.

- It serialises the network calls, which is its benefit (§2.1): the server sees writes in order.
- It does not protect the optimistic state. Patches are applied at dispatch, before the lock, so in
  the browser: edit 1 (held 8 s, then 500), edit 2 saved at 7.2 s → row shows edit 2 → edit 1 fails at
  8.3 s → `undo()` restores edit 1's inverse (the pre-edit value), clobbering edit 2 on screen → edit
  2's PATCH, held back by the lock, is only now sent and completes at 13.8 s → row shows edit 2 again.
  Five seconds showing a value the user had moved away from. The error from edit 1 was not shown
  either, because re-opening the form had called `resetUpdate()` (`ColorFormPopover.tsx:217`).
- Head-of-line blocking: one slow or hung write delays every later color write for every color.
- It is not an RTK Query idiom; it is an ad hoc mutex around `baseQuery`. The `queryFn` casts
  (`result as QueryReturnValue<…>`) exist only to make the wrapper type-check. A per-color queue would
  keep the ordering benefit without blocking unrelated colors.

### 2.4 The hidden form and the temporary id

- While a save is in flight the form is `opacity: 0; pointer-events: none` (`ColorFormPopover.tsx:377-380`),
  but the Radix popper wrapper around it is not, so an invisible box (230×538 px in the run measured)
  keeps intercepting pointer events over the list. Observed: with Brand Blue's edit in flight, the
  Brand Green row's menu could not be clicked ("covered by … inside `#radix-:r2q:`"); during an
  in-flight add the new row's menu could not be clicked. A few hundred milliseconds on a fast server;
  a dead zone on a slow one.
- Clicking the same row's menu while its save is in flight closes the popover (interact-outside), which
  resets `isSubmittingHidden`, calls `resetUpdate()`, and re-opens it on the optimistic value. When the
  original save then succeeds, `onOpenChange(false)` closes the re-opened form under the user; when it
  fails, the error is not shown because the hook was reset (observed in §2.3).
- Optimistic create renders the row under `temp-<uuid>` until the POST resolves. Rename, edit, and
  delete on that row send the fake id; behind the lock they go out after the POST and 404. The delete's
  undo then restores the pre-delete array (temp row present, real row absent) until the rejection's
  refetch repairs it. Transient and self-healing, and I could not drive it through the UI because the
  invisible form blocked the click (previous bullet); it is inferred from the code. !1484's
  counterpart — deleting a row whose POST is still in flight sends the real UUID, gets a 404, restores
  the row, and shows "Failed to delete color" — is arguably the more visibly confusing outcome.

### 2.5 Smaller points

- `createTempColorId` reimplements a UUID fallback; `uuid` is already a dependency of `ui/`.
- `withGlobalBrandKit` defensively looks up a cache entry that is never populated.
- `data-submission-state` and the `POP_SEL` change in the spec exist only to let the test tell a
  hidden form from a visible one.
- No unit tests.
- The description is the unfilled template (a real gap for a reviewer; says nothing about code quality).

### 2.6 Review notes on !1484 (held to the same standard)

- **Draft regression** (§1.1): blocker. The right fix is to stop preferring `draft.colors` in
  `useBrandKitColors` and then delete the `getAutoSave` patch, the `getAutoSave` re-application, and
  the three `BrandKitsAutoSave` invalidations, which then have no purpose.
- **No ordering guarantee for concurrent writes to the same color** (§2.1): `pendingColorWrites`
  orders client-side rollbacks only. Either serialise per color id or state last-write-wins explicitly
  and accept that a slow older PATCH can overwrite a newer one server-side.
- **Rejected edit discards the entered values.** The form closes at `ColorFormPopover.tsx:322`, the
  row reverts, and the error is rendered at the top of the section (`BrandKitColorsSection.tsx:258-281`)
  with no dismiss, persisting until the next update or delete replaces it. Upstream and !1523 keep the
  form open with the values for retry. A deliberate trade-off (commit `e3bf9774`), but a loss that
  should be stated, not implied.
- **Rejected create can clobber a second add.** Starting another add calls `resetCreateColor()`
  (`BrandKitColorsSection.tsx:229`); when the first POST then rejects, `onCreateRejected` re-initialises
  the open form on the old values (`ColorFormPopover.tsx:230-238` depends on `rejectedColor`) and,
  because the reset removed the mutation substate, no error is shown. Edge case; inferred from code.
- **Redundant failure-path invalidation.** `applyOptimisticColorWrite` dispatches `invalidateTags(…)`
  in its catch (`brandKit.ts:131-134`) under the comment "`invalidatesTags` only fires on success".
  Wrong for RTK Query 2.x: `invalidationByTags.ts:36-37` fires on `isFulfilled` *and*
  `isRejectedWithValue`, and HTTP errors are rejected-with-value. Observed: two rounds of refetches
  after every rejection (with delayed invalidation the explicit dispatch queues a second round).
  Remove it.
- **Dead code in `ColorFormPopover.handleSave`** (`:273-330`): the outer `try/catch` can catch nothing
  (every await inside has its own handler; `updateColor` is not awaited); the trailing
  `onOpenChange(false)` at `:327` is reachable only for `edit` without a `color`; the comment at
  `:257-258` cites `isUpdating`, which no longer exists in the file.
- **Narrow-undo claim overstated for delete.** The comment at `brandKit.ts:26-29` says a mutator that
  changes the smallest part produces a narrow undo; `splice` makes immer emit a replace patch for every
  shifted index, so undoing a rejected delete restores every later color to its pre-delete snapshot
  until refetch plus re-application repairs it. Self-healing, but the comment overclaims.
- **`IframeSwapper` commits are out of scope.** `9d65e677` and `c90585c0` fix a pre-existing
  double-`srcdoc`-load race on exiting preview mode; the second corrects a guard the first inverted;
  `9d65e677` also bundles a Playwright fix into the same commit. Nothing in the brand kit change
  depends on them, and this review did not exercise preview mode. Split them out.
- **Tests are more than !1523's, but weaker than the count suggests.** "restores a color when
  deleting it fails" only asserts the row is visible afterwards (`brandKitColors.spec.ts:760-788`),
  never that it disappeared, so it passes on upstream by inspection; "reopens the add form…" asserts
  a hidden row, form values, and the error text, all of which upstream's stays-open-on-422 form also
  satisfies. The preview injection, the largest user-visible addition, has no automated coverage.
  In Vitest, "survives an auto-save response that lands mid-write" never settles its write, leaving an
  entry in the module-level `pendingColorWrites` that `getBrandKit.onQueryStarted` re-applies in every
  later test's `setup()`; they pass only because they assert ids, not hex. A latent isolation bug.
- **Description is stale** (§1 row 8); it must be rewritten before merge.
- Over-engineering check: the per-id token is justified by the stale-failure interleaving (observed);
  the fixed cache keys are the minimum way to report an error after the reporting component unmounted;
  `PatchDispatch` is an awkward local type but not dead. The `getAutoSave` machinery is the part that
  should go (§1.1).
- Scope: delete error reporting is a direct consequence of making delete optimistic, and the preview
  propagation is what the user actually looks at after changing a color; both are defensible here.
  Client-minted UUIDs (§1.2) and the `IframeSwapper` fix are expansions.

## 3. Recommendation

**Neither MR is mergeable as-is. Fix the hook first; then use !1484 as the base, with a real round of
rework, and close !1523 with credit.** The margin is narrower than the first draft of this report
claimed, and the bias check changed two things: the draft-case charge against !1523 is a pre-existing
quirk that also breaks !1484 (worse), and !1523's lock has a benefit !1484 lacks.

Why still !1484 as the base:

- !1523 introduces two regressions that matter (silent delete failure; revert window plus invisible
  dead zone while a save is in flight), and fixing them properly means lifting error reporting out of
  the row and removing the global lock, at which point most of !1484's structure is being rebuilt.
- !1484 already has the pieces that remain necessary once the draft machinery is dropped (preview
  injection, section-level error reporting, the stale-failure token, optimistic create), plus a test
  harness that holds requests open, and its CI is green.
- Neither pattern is "more RTK" than the other. Both use `onQueryStarted` + `updateQueryData` +
  `undo()`; !1523's distinguishing addition is the promise lock, !1484's is the token and pending map.

Required on !1484 before merge (in order of importance):

1. Change `useBrandKitColors` to read canonical colors (one line), then delete the `getAutoSave`
   patch target, the `getAutoSave` re-application, and the three `BrandKitsAutoSave` invalidations;
   add a test that edits with a draft present and asserts the row *stays* on the new value after
   success. This removes the phantom revert (§1.1) and about a fifth of the service change.
2. Decide ordering: serialise writes per color id (the benefit of !1523's lock without its costs), or
   document last-write-wins and why it is acceptable.
3. Remove the failure-path `invalidateTags` and its comment; remove the dead `try/catch`, the dead
   `onOpenChange(false)`, and the stale `isUpdating` comment in `ColorFormPopover`; fix the
   narrow-undo comment for delete.
4. Move the two `IframeSwapper` commits to their own issue.
5. Strengthen the two Playwright tests so they fail on upstream (assert the row disappeared before it
   reappears; hold the POST open in the add test), add an assertion on the preview's custom property,
   and settle the write in the mid-write Vitest case (or clear `pendingColorWrites` between tests).
6. Either keep the form open on a rejected edit with the entered values (what upstream and !1523 do),
   or state the trade-off in the description. The lost-values behaviour is the one thing !1523 does
   better for the user.
7. Make the `id`-on-POST API addition an explicit decision (it is a permanent public surface) and
   give it an HTTP-level test, or adopt a temporary-id create without the API change and guard the
   row's actions until the POST resolves.
8. Rewrite the description: creation is optimistic with client-minted ids; nine Vitest cases; the
   draft behaviour; what was split out.
9. Open a separate issue for the auto-save route's missing Color cache tags
   (`ApiConfigAutoSaveControllers::get()`); it is two or three lines and unrelated to the UI once (1)
   lands.

If the maintainer prefers the smaller patch as the base: !1523 plus the hook change (1), error
reporting lifted out of the row for delete, the lock replaced by per-color serialisation, and the
preview injection from !1484 would be an equally valid outcome and roughly the same amount of work;
what it would lack is the test harness that holds requests open.

## 4. What was verified, how, and what was not

Environment: macOS farm box, load average 4–6, ddev site `site-2`, Chromium via agent-browser with a
named session. Seven fixture colors imported from
`tests/modules/canvas_test_code_components_color` (enforced module dependencies cleared so PATCH
validates). Editor page `/canvas/editor/canvas_page/1`, Brand kit panel open.

Browser runs that count (tab visible; see caveat below):

| Scenario | !1523 | !1484 |
|---|---|---|
| Edit, PATCH delayed 3 s, no draft | Row at 103 ms; form hidden until 3.8 s, then closed; preview unchanged 50 s later | Row at 136 ms; form closed at once; preview updated at 151 ms in both iframes; canonical and auto-save refetched on success |
| Edit, PATCH forced 500 | Row rolled back; form re-shown with values and "Failed to update color" (observed in a throttled tab; outcome unambiguous) | Row rolled back at 1.8 s; preview rolled back; "Failed to update color: Forced failure" in the section; two refetch rounds |
| Edit with draft present | Row never changes (20 s); server updated | Row updates, then flips back to the stale draft value at ~4 s (dynamic page cache HIT on the auto-save route); preview correct; server updated |
| Delete, DELETE forced 500 | Row gone at 0.2 s, back at 1.9 s, no message | Row gone at 0.1 s, back at 2.0 s, "Failed to delete color: …" in the section |
| Create, POST forced 422 | Temp row at 0.1 s, removed at 2.3 s, form re-shown with values and error | Row appeared right after Add, removed on rejection, form reopened with values and error |
| Create, POST delayed 6–7 s | Temp row at once; its menu not clickable (invisible form over it); row kept after success | Row at once with final UUID (`id` in POST body); delete during flight → 404 → row restored with "Failed to delete color"; row kept after success |
| Two edits, first fails late | Revert flash 200 → pre-edit → 200 for ~5.5 s; form re-opened without error | No flash; edit 2 kept; stale failure not reported |
| Re-open Edit mid-flight | Closes and re-opens the hidden form on the optimistic value; rows under the invisible form not clickable | Form already closed; second edit proceeds |

Automated:

- `brandKitColors.spec.ts:675` on !1523: passed, 6.3 min, headless in the container with
  `--timeout 600000`. At the default 120 s it fails in `beforeEach` (site install) on this box;
  environmental.
- `brandKitColors.spec.ts` `-g "optimistic color edits|rolls back …|restores a color …|reopens the add form"`
  on !1484: 4 passed, 8.5 min, 4 workers (CI uses 1), `--timeout 600000`.
- `ui/src/services/brandKitColors.test.ts` on !1484: 9 passed, 0.7 s (host Vitest).
- `tests/src/Kernel/Config/ColorTest.php --filter testCreateFromClientSide` on !1484: 3 tests, 9
  assertions, OK (pre-existing deprecations only).
- `composer run phpcs` on `src/Entity/Color.php` and `tests/src/Kernel/Config/ColorTest.php`: clean.
- Latest CI pipeline for each MR: success (928613 for !1523, 931040 for !1484).

Not verified, or verified with caveats:

- The one-line hook fix (§1.1) was reasoned from the entity's `config_export`, the fonts PATCH body,
  and the auto-save controller; it was not implemented or tested on either branch.
- Out-of-order server application of concurrent PATCHes (§2.1) was not reproduced; it is a possibility
  from the lack of any ordering in !1484, not an observed failure.
- The temp-id hazard in !1523 (§2.4) and the rejected-create clobber in !1484 (§2.6) are inferred from
  code, not driven through the UI.
- "Two Playwright tests would pass on upstream" is by inspection of their assertions, not by running
  them on upstream.
- Row 6 (mid-write read) was not reproduced in the browser; it rests on !1484's Vitest case and reading.
- Rename-plus-edit concurrency on one color, undo/redo interplay, folder assignment after create, the
  `IframeSwapper` change (preview mode was not exercised), multiple editor tabs: not tested.
- The preview hook was checked on one custom property in both iframes and after rollback, not after a
  real `srcdoc` swap.
- Early browser runs were in a tab whose `visibilityState` was `hidden`, where Chromium throttles
  `requestAnimationFrame`: the preview never initialised, Radix menu exit animations never ended, and
  clicks landed on stale layers. Those runs were discarded; every result above comes from a visible
  tab. Playwright runs were unaffected.
- Not run: the full Vitest suite, the full Playwright suite, phpstan, ESLint/Prettier (CI is green on
  both).
- The issue's own text on drupal.org could not be fetched (client challenge); scope is inferred from
  the MR titles and descriptions.

Checkout left at `c90585c0` (!1484, detached) with its built `ui/dist` and the DB updated for it, so
the live site shows !1484. The fixture colors, one extra color "Fresh Mint", and the edited values
remain on the site. Scratch scripts are under the session scratchpad, not the repo.
