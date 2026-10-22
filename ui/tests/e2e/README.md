<!-- cspell:ignore autosave unmigrated scrollability PWDEBUG -->

# Cypress → Playwright e2e migration ledger

Canvas is migrating its end-to-end test coverage from Cypress
(`ui/tests/e2e/`) to Playwright (`tests/src/Playwright/`). This file is the
single source of truth for that migration: it tracks, per Cypress spec,
whether it has been migrated, where its coverage now lives, and what (if
anything) is still missing.

**Rule: this file is updated in the same commit as any change to this
directory.** No Cypress spec is deleted without a corresponding row below
explaining where its coverage went. When the last row is `Deleted`, this
entire directory (and this file) is deleted in the same commit.

See `AGENTS.md` for repo-wide testing conventions, and see "Appendix: MR
phase plan" below for the full rationale and MR sequencing this ledger is
tracking progress against.

## Status legend

- **Not started** — spec is unchanged, still runs in CI as Cypress.
- **Partially migrated** — some assertions now also live in a Playwright
  spec; the Cypress spec still runs until the remaining assertions listed
  under "Remaining gap" are closed.
- **Deleted** — spec has been removed. "Playwright spec(s)" names where its
  coverage lives now (or notes that it was redundant / dead code).

## Spec status

| Cypress spec | Status | Playwright spec(s) | Notes / remaining gap |
|---|---|---|---|
| `auto-path-alias.cy.js` | Not started | — | Path alias auto-generation from title, manual-override lock, post-publish lock. No Playwright coverage yet. |
| `autocomplete-props.cy.js` | Not started | `componentOperations.spec.ts` (partial: blur behavior only), `raceConditions.spec.ts` (partial: installs `canvas_test_autocomplete`) | Missing: `ul.ui-autocomplete` suggestion list assertions, config-entity autocomplete rewrite, link-field autocomplete resolving to node alias. |
| `autosave.cy.js` | **Deleted** | `componentOperations.spec.ts` (`Can delete component with delete key`) | Coverage was folded into existing CRUD coverage: delete a component, reopen the same editor route, and assert the deletion persisted in both UI overlay and preview. |
| `canary.cy.js` | **Deleted** | `canaryMinimal.spec.ts` + `tests/src/Playwright/setup.ts` / `fixtures/test.ts` harness | Cypress-harness-only smoke test (`drupalInstall`, `drupalCreateUser`, `drupalInstallModule`). The Playwright fixture harness proves the equivalent install/login/module-install flow on every single test run. |
| `ckeditor5.cy.js` | Not started | `componentOperations.spec.ts` (partial: empty required formatted body) | Missing: text-format switching + "Change text format?" dialog (already `it.skip` in Cypress, see #3522998); Source-editing mode round trip; publish + assert on node edit form; Claro admin-theme dialog styling. |
| `code-component-image.cy.js` | Not started | `optionalImageDefault.spec.ts` (partial: "Code component: Remove, upload, and persist optional image correctly") | Missing: "Vanilla Image" default `placehold.co` src assertion; publish, then assert the image on the **live published page** (not just the editor). |
| `component-transforms-and-evolution.cy.js` | Not started | — | No Playwright spec installs `canvas_test_storable_prop_shape_alter`. Zero coverage of a stored component instance keeping its old widget shape after component metadata evolves. |
| `components-slots.cy.js` | **Deleted** | `componentOperations.spec.ts` ("Can add a component with slots"), `childrenSlotComponent.spec.ts`, `CanvasComponents.moveComponent()` | Spec was already `describe.skip` (dead code, blocked on #3473617). Slot-insertion behavior it would have tested is covered by the specs listed. |
| `contextual-panel.cy.js` | Not started | `componentOperations.spec.ts` (partial), `routing.spec.ts` (partial: direct component-route visits), `uiPermissions.spec.ts` / `patternEditing.spec.ts` (partial: right-click menus) | Missing: Duplicate action; Delete via context menu (preview and layers); browser-back history across component routes; "Handles empty values in required inputs" (full case); "Edit code" → code editor route. |
| `copy-and-paste.cy.js` | Not started | — | No `Meta+C`/`Meta+V`, no localStorage clipboard assertions, no paste-with-children. Needs `CanvasClipboard` mixin (planned MR 5). |
| `dom-to-redux.cy.js` | Not started | — | Store-propagation assertions are planned to move to Vitest in `ui` (not Playwright); the `#states`-adjacent half folds into a Playwright `drupalStates.spec.ts` alongside `states.cy.js`. |
| `drag-and-drop.cy.js` | **Deleted** | `dragAutoScroll.spec.ts`, `folders.spec.ts` (drag reorder / between folders), `componentOperations.spec.ts` (`moveComponent()`) | Spec was already `describe.skip` (dead code, blocked on #3473617). Missing from the listed specs: dragging between slots via the layers menu, and undo/redo after a layers drag — tracked as a gap, not blocking deletion since the spec never ran. |
| `editor-frame.cy.js` | Not started | — | No zoom coverage (`+`/`-`, zoom dropdown, ctrl+wheel). Needs `CanvasFrame` mixin (planned MR 6). |
| `empty-canvas.cy.js` | Not started | Implicitly by nearly all specs (`canvas.createCanvas()` → `addComponent()` on an empty page) | Missing: the `node/2` (article, not `canvas_page`) empty-preview variant; `.canvas--region-empty-placeholder`; SEO/metatag fieldset assertions; `auto-saves/pending` HTTP-200 guard. |
| `entity-form-field-types-test.cy.js` | Not started | — | 23 field-widget modules under `entity-form-fields/` plus a JSON:API working-copy round trip. No Playwright spec exercises page-data-form field widgets beyond Title and URL Alias. Needs `CanvasPageDataForm` mixin (planned MR 7/8). |
| `entity-form-fields/*.js` (22 modules + `index.js` + `default-date.js`) | Not started | — | Helper modules consumed only by `entity-form-field-types-test.cy.js`; migrate together. |
| `error-handling.cy.js` | Not started | — | No `canvas-error-alert` / `canvas-error-reset` coverage; no 418 error-boundary reset; no logged-out 401 handling. |
| `expand-slots.cy.js` | Not started | `componentOperations.spec.ts` (partial: aria snapshot shows collapse controls) | Missing: collapse-all; collapse state persisting across navigation; selecting a component auto-expanding only its ancestor slots. |
| `extension-legacy.cy.js` | Not started | `pageExtension.spec.ts` (covers the **new** extension UI only) | No Playwright spec installs `canvas_test_extension_legacy`. Legacy JS extension API (select/focus/insert/update/delete) unmigrated. |
| `image-code-component.cy.js` | **Deleted** | `optionalImageDefault.spec.ts` → "Code component: Remove, upload, and persist optional image correctly" | Near-identical flow already asserted. The one gap (asserting the "Add media" button is not disabled before opening the media dialog) was added to the Playwright test in the same commit as this deletion. |
| `link-code-component.cy.js` | Not started | — | No assertion that a code-component link prop renders the canonical alias, nor that query/fragment are preserved through alias resolution. |
| `media-library-component-instance.cy.js` | Not started | `optionalImageDefault.spec.ts` (partial: per-prop scoping + persistence), `document.spec.ts` (partial: per-instance media isolation) | Missing: 3× swap-iteration loop; removing a required image restoring the example placeholder; value persisting across Page-data ⇄ Settings tab switches and a Preview/Exit-Preview round trip; the `article` entity-type variant. |
| `media-library-entity-form.cy.js` | Not started | — | No Playwright spec opens a media widget inside `canvas-page-data-form`. Needs `CanvasPageDataForm` mixin (planned MR 7). |
| `media-library.cy.js` | Not started | `optionalImageDefault.spec.ts` (partial: tests 1–4) | Missing: optional-image-without-example SDC flow; removing a required code-component image ("One media item remaining." + no render error); sibling text prop staying intact after image removal. |
| `media-video-prop.cy.js` | Not started | `video.spec.ts` (partial: uses a code component, not the SDC `canvas_test_video_fixture`) | Missing: `Display width` prop driving `video[width]` + measured rendered width; per-instance media isolation across two Video instances; "not immediately having a value" no-render-error case. |
| `multi-select-components.cy.js` | Not started | — | No meta+click multi-select, no "N items selected" panel, no layers↔preview selection sync, no parent/child mutual exclusion. Needs `CanvasSelection` mixin (planned MR 4). |
| `multivalue-date-time-form-design.cy.js` | Not started | `multivaluePropTypes.spec.ts` (partial: "Datetime and Date", "Datetime (Unlimited)" — component-prop context only) | Missing: the page-data entity-form context. 3 of 12 Cypress tests are already `it.skip` (remove-button behaviors). |
| `multivalue-form-design-link.cy.js` | Not started | `multivaluePropTypes.spec.ts` (partial: "Link items" — component-prop context only) | Missing: "Four Multiple Links" SDC field validation; drag reorder of link rows; cardinality hiding "+ Add new"; panel remaining interactive after a server-side 422; `field_cvt_uri_relative`; entity-form context. |
| `multivalue-form-design-list.cy.js` | Not started | `multivaluePropTypes.spec.ts` (partial: "List text", "List integer" — component-prop context only) | Missing: dropdown chevron open/close; disabled-option no-op click; re-enable-on-remove; clear-all re-enable; entity-form context. |
| `multivalue-form-design-number-integer.cy.js` | Not started | `multivaluePropTypes.spec.ts` (partial: "Numbers", "Limited items", "Required items" — component-prop context only) | Missing: drag reorder of number rows; live-typing propagation; `form_build_id` refresh on "+ Add new"; entity-form context. |
| `multivalue-form-design.cy.js` | Not started | `multivaluePropTypes.spec.ts` (partial: "Helper functions", "Edit, remove, add, and re-order" — component-prop context only) | Missing: entity-form context and its AJAX `form_build_id` assertions. |
| `multivalue-media-form-design.cy.js` | Not started | `mediaMultiCardinality.spec.ts` (partial: component-prop context only) | Missing: inline delete button; drag-handle reordering; cardinality message on a limited media field; entity-form context. |
| `navigation.cy.js` | Not started | `CanvasNavigation.createCanvas()` (partial), `infiniteScroll.spec.ts` (partial), `routing.spec.ts` (partial: SPA page-to-page nav), `uiPermissions.spec.ts` (partial: page options menu items) | Missing: search filtering + "No pages found"; title edits propagating to nav list; Duplicate page; Delete page (incl. deleting current page); Set as homepage + staged-publish. |
| `overlay-ui.cy.js` | Not started | `componentOperations.spec.ts` (partial: hover outlines, not name tags) | Missing: single-`canvas-name-tag`-at-a-time rule; parent/child/hover label precedence. |
| `page-data-form.cy.js` | Not started | `routing.spec.ts` (partial: Undo enabled/disabled, stack cleared on navigation) | Missing: undo/redo interplay between page-data form and component-instance form; each form invalidating the other's redo stack. Needs `CanvasHistory` mixin (planned MR 3). |
| `pattern.cy.js` | Not started | `patternEditing.spec.ts` (partial: create/rename/edit/publish) | Missing: inserting a saved pattern on a different page, resulting component count, pattern root becoming the selected layer. |
| `primary-panel.cy.js` | Not started | `folders.spec.ts` (partial), `componentOperations.spec.ts` (partial: library list) | Missing: library-panel scrollability with 50 mocked components; per-component hover previews with SDC CSS actually applied. |
| `prop-types.cy.js` | Not started | — | No Playwright spec installs `sdc_test_all_props`. Largest single gap: 19 single-value prop-type cases unmigrated (booleans, string formats, validation messages, enums, dates, integer bounds, CKEditor toolbar config, JSON-string textareas). Planned MRs 11–13. |
| `publish-review.cy.js` | Not started | `reviewChanges.spec.ts` (partial), `uiPermissions.spec.ts` (fully covers the no-publish-permission case), `conflictResolution.spec.ts` (partial) | Missing: topbar status transitions (`Published`/`Changed`/`No changes`); "Review N change(s)" counter; verifying change on the live node before/after publish; discard-without-full-reload. |
| `publish-validation.cy.js` | Not started | `conflictResolution.spec.ts` (partial: legacy publish conflict errors) | Missing: non-validation/exception publish errors; per-entity error links that SPA-navigate to the erroring component; validation-constraint errors across two entities; page-data form validation errors blocking publish. |
| `realtime-preview-code-component.cy.js` | Not started | `brandKitColors.spec.ts` (partial: asserts real-time preview updates for a color prop) | Missing the stricter guarantee this spec is for: preview must update *before* the layout PATCH fires, for scalar code-component props. |
| `scope-css.cy.js` | Not started | — | Not a true e2e test — stubs CSS and calls `Drupal.AjaxCommands.prototype.scope_css()` directly. Planned to split: CSS-variable/selector-rewrite logic → Vitest in `ui` (requires extracting `scopeCss` out of `js/ajax.command.customizations.js` into an importable module first); `@scope` at-rule parsing assertions → Cypress component test (jsdom cannot parse `@scope`), unless proven otherwise once the extraction is done. |
| `states.cy.js` | Not started | — | No Playwright spec installs `canvas_test_state_api`. Zero coverage of Drupal's `#states` API (checkbox/select/radio/text-input driving visibility/disabled/checked). Planned to fold into a new `drupalStates.spec.ts` (MR 2), alongside the `#states`-adjacent half of `dom-to-redux.cy.js`. |
| `topbar-layout-preview-mode.cy.js` | Not started | `pagePreview.spec.ts` (partial: enter/exit preview, change width), `languageSelect.spec.ts` (partial: topbar language trigger) | Missing: eye-icon mutual exclusivity; topbar sections not overlapping at multiple viewport sizes; 118px title truncation; language selector 2-letter code format. |
| `undo-redo.cy.js` | Not started | `routing.spec.ts` (partial: Undo button enabled/disabled, stack cleared on navigation) | Missing: actually performing undo/redo (`Meta+Z`/`Meta+Shift+Z`) for component insertion and component-instance form values. Needs `CanvasHistory` mixin (planned MR 3). |
| `vh-units.cy.js` | Not started | — | No Playwright spec installs `canvas_test_vh_preview`. No `data-canvas-preview-max-height` capping assertions, no unbounded-iframe-growth guard. Needs `CanvasFrame` mixin (planned MR 6). |

## Helpers (Cypress → Playwright mapping)

Tracks custom Cypress commands (`ui/tests/support/commands.js` unless noted)
and where their Playwright equivalent lives, so no helper behavior is lost
silently.

| Cypress helper | Status | Playwright equivalent | Notes |
|---|---|---|---|
| `cy.drupalCanvasInstall` / `cy.drupalInstall` | Migrated | `tests/src/Playwright/fixtures/test.ts` (`isolatedPerTest`) + `tests/src/Playwright/setup.ts` | Playwright installs the bare `minimal` profile and each migrated spec reproduces only the setup it needs. Prefer in-test setup first; add helper modules only as a last resort. |
| `cy.drupalLogin` / `cy.drupalLoginAsAdmin` / `cy.drupalLogout` | Migrated | `Drupal.login()` / `Drupal.loginAsAdmin()` / `Drupal.logout()` (`@drupal/playwright`) | Direct equivalents already in use by every spec. |
| `cy.drupalCreateUser` / `cy.drupalCreateRole` | Migrated | `Drupal.createUser()` / `Drupal.createRole()` / `Drupal.addPermissions()` | Used in `tests/src/Playwright/setup.ts`. |
| `cy.drupalInstallModule` | Migrated | `Drupal.installModules()` | Used in `tests/src/Playwright/setup.ts` and via the `modules` fixture option. |
| `cy.clearAutoSave` | Migrated | `canvas_e2e_support` route exists (`/canvas-test/clear-auto-save/{type}/{id}`); no Playwright wrapper yet | Add a wrapper only if a migrated spec needs it and inline usage is no longer readable. |
| `cy.setKeyValue` | Migrated | `canvas_e2e_support` route exists (`/canvas-test/set-key-value/{collection}`); no Playwright wrapper yet | Add a wrapper only if a migrated spec needs it and inline usage is no longer readable. |
| `cy.previewReady` / `cy.getIframeBody` / `cy.waitForElement*InIframe` | Migrated | `CanvasBase.waitForEditorFrame()`, `CanvasUtilities.getActivePreviewFrame()` / `testInPreviewFrame()` | Playwright uses native `FrameLocator` + `expect().toPass()` retries instead of bespoke polling commands. |
| `cy.openLibraryPanel` / `cy.openLayersPanel` | Migrated | `CanvasNavigation.openLibraryPanel()` / `openLayersPanel()` (+ `openCodePanel`, `openPagesPanel`, `openTemplatesPanel`, `openBrandKitPanel`) | Playwright's navigation mixin has strictly more panel-opening helpers. |
| `cy.loadURLandWaitForCanvasLoaded` | Migrated | `CanvasNavigation.openCanvas()` / `createCanvas()` | Different model: Cypress navigates by URL; Playwright usually creates content via UI and returns `{ entity_type, entity_id }`, then reopens that route when persistence checks are needed. |
| `cy.insertComponent` | Migrated | `CanvasComponents.addComponent()` | Equivalent: opens the Library panel context menu and asserts the instance count. |
| `cy.editHeroComponent` | Migrated | `CanvasComponents.editComponentProp()` | Playwright's version is generic over prop name/type rather than hard-coded to the Hero component. |
| `cy.clickComponentInPreview` / `cy.getComponentInPreview` | Migrated | `CanvasComponents.clickPreviewComponent()` / `hoverPreviewComponent()` | |
| `cy.clickComponentInLayersView` / `cy.expandComponentLayer` / `cy.expandSlotLayer` | Not started | — | No direct Playwright equivalent yet; specs that need layers-tree expansion assertions (`expand-slots.cy.js`) are not yet migrated. |
| `cy.clickOptionInContextMenuInLayers` | Not started | — | Planned: `CanvasContextMenu` mixin (MR 4). |
| `cy.sendComponentToRegion` / `cy.focusRegion` / `cy.returnToContentRegion` | Not started | — | No Playwright spec exercises global regions via the layers tree yet; `CanvasGlobalRegions.enableGlobalRegions()` exists but only for the theme-settings toggle. |
| `cy.publishAllPendingChanges` | Migrated | `CanvasNavigation.publishAllChanges()` | Playwright's version retries with configurable intervals; used across most specs. |
| `cy.hidePanels` / `cy.showPanels` / `cy.disableEditorPanning` / `cy.reEnableCanvasPanning` | Not started | — | No Playwright equivalent; not yet needed since no migrated spec drags across large distances requiring a static frame. Would land with `CanvasFrame` (MR 6) if needed. |
| `cy.getElementScaledDimensions` | Not started | — | Planned: `CanvasFrame.getScaledDimensions()` (MR 6), for `editor-frame.cy.js`. |
| `cy.setRangeValue` / `cy.triggerMouseWheelWithCtrl` | Not started | — | Planned: `CanvasFrame.ctrlWheel()` (MR 6), for `editor-frame.cy.js`. |
| `cy.assertToggleState` / `cy.toggleToggle` | Migrated (ad hoc) | Specs assert Radix toggle state inline via `data-state`/`aria-checked` locators | No shared helper needed so far; revisit if duplication appears during migration. |
| `cy.checkSiblings` | Not started | — | Only used by the already-skipped `drag-and-drop.cy.js`. |
| `cy.waitForWindowProcess` / `cy.waitForElementsToStabilize` | Migrated (ad hoc) | `expect(locator).toPass()` / Playwright's built-in auto-waiting | Playwright's retrying assertions cover most of what these polled for; no direct 1:1 port planned. |
| `cy.recordFormBuildId` / `cy.selectorShouldHaveUpdatedFormBuildId` | Not started | — | Only called by `multivalue-form-design*.cy.js` (MR 9–10). Likely an arbitrary-wait pattern left over from an earlier refactor, not a behavior worth porting as-is; try Playwright's built-in auto-waiting (or `page.waitForLoadState('networkidle')` if truly needed) first when MR 9–10 is scoped. |
| `cy.assertMultivalueReorder` | Not started | — | Needed once multivalue drag-reorder specs migrate (MRs 9–10). |
| `cy.openMultivaluePopover` / `cy.closeMultivaluePopover` | Migrated | Inline locators in `multivaluePropTypes.spec.ts` (no shared helper yet) | Consider extracting to `CanvasComponents` if duplication grows during MRs 9–10. |
| `cy.realDnd` / `tests/support/realDnd.js` | Migrated (different approach) | `CanvasBase.drag()` | Cypress uses a CDP-driven synthetic drag; Playwright uses `hover` + `mouse.down/up` with forced reflows. Both work around native HTML5 DnD limitations. |
| `cy.waitForAjax` | Not applicable | — | Dead code in the current Cypress suite — defined in `commands.js`, called by zero specs. Not being ported. |
| `cy.drupalEnableThemeForCanvas` | Migrated | `CanvasGlobalRegions.enableGlobalRegions()` | |
| `cy.debugPause` | Not applicable | Playwright's `--debug` / `PWDEBUG=1` / `page.pause()` | Native tooling replaces this Cypress-specific shim. |
| `spec-tags.js` / `parallel.js` / `flaky-specs.js` (CI sharding) | Not applicable | Playwright's built-in `--shard` + `fullyParallel` | Playwright config already shards in CI (`.gitlab-ci.yml` `playwright` job); no bespoke weight/flaky-tag system is needed. |

## Deletion criteria

A Cypress spec (or this whole directory) may be deleted once:

1. Every row above it depends on is `Deleted`, or every remaining case in
   its "Remaining gap" column has a tracked, closed MR reference, and
2. The corresponding Playwright spec(s) have been run and pass.

When in doubt, add the missing assertions to the existing Playwright spec
first (per `AGENTS.md`: prefer extending an existing test over creating a
parallel one), then delete the Cypress spec in the same commit that closes
the gap.

## Appendix: MR phase plan

This is the full sequencing plan this migration is following. It is kept
here (rather than only in an issue) so any contributor picking up the next
MR has the full rationale without needing prior conversation context.
Update this appendix if the plan changes; keep the "Spec status" table
above as the moment-to-moment source of truth for what's actually done.

### Guiding rules

1. **Close gaps in existing Playwright specs**, don't create parallel
   files (per `AGENTS.md`).
2. **This README is the ledger.** Every MR updates it: each Cypress spec
   gets a row — `Migrated to <playwright spec>` / `Partially migrated
   (remaining: …)` / `Not started` / `Deleted: covered by …`. The
   directory and this README are deleted together in the final MR. No
   ambiguity about what's left at any point.
3. **Upstream candidates are quarantined** in
   `tests/src/Playwright/upstream/`, Canvas-free, so they can be lifted
   into `@drupal/playwright` as a patch.
4. Each MR is independently reviewable; no MR both adds infra *and*
   migrates more than ~3 specs.

### Setup strategy for migration

Migration does not use a seeded-site fixture. Each migrated Playwright test
reproduces only the setup it needs, directly in the test flow.

If coverage cannot stay readable or reliable with explicit setup in the
spec, add a helper module as a last resort.

Consolidate migrated coverage wherever practical. Every isolated test run
installs and tears down Drupal, so reducing duplicate test setup paths keeps
the suite cheaper to run and easier to maintain.

**Also confirmed:** 26 of 45 Cypress specs are already partially covered
by an existing Playwright spec — close those gaps in place (don't fork a
parallel file), then delete the Cypress spec. A few specs
(`scope-css.cy.js`, parts of `dom-to-redux.cy.js`, `states.cy.js`) are not
true e2e tests; move those to Vitest in `ui` where possible, Cypress
component tests where jsdom can't do the job (e.g. `@scope` at-rule
parsing).

### Phase 0 — Ledger + free deletions

**MR 0 (done):** Establish this migration ledger and remove redundant
Cypress specs: `canary.cy.js`, `components-slots.cy.js`,
`drag-and-drop.cy.js` (deleted), `image-code-component.cy.js` (gap closed
in `optionalImageDefault.spec.ts`, then deleted).

### Phase 1 — Foundation

**MR 1 (done):** Keep the default Playwright install path (`minimal` +
`setupSite()`), and migrate coverage by reproducing setup inside tests.
Do not add a seeded fixture path.

Originally proved with **`autosave.spec.ts`** (migrated from
`autosave.cy.js` — delete a hero, reload without clearing auto-save, assert
still gone). That coverage now lives in
`componentOperations.spec.ts` (`Can delete component with delete key`) so
the standalone autosave spec was removed as redundant.

Also in this MR: ensure migration docs prefer explicit per-test setup and
state that helper modules are a last resort.

**MR 2:** Migrate **`states.cy.js`** (327 lines, `canvas_test_state_api`)
into a new `drupalStates.spec.ts`. Pure `#states` API assertion
(visibility/disabled/checked/required reacting to checkbox, select, radio,
and text-input changes) — no new helpers needed, only the already-established
`page.getByLabel()` + `expect(...).toBeVisible/toBeEnabled/toBeChecked/
toHaveAttribute()` patterns used throughout the existing suite.

No `drupalAjax.ts` in this MR. `cy.waitForAjax` is dead code in the
current Cypress suite (defined in `commands.js`, called by zero specs).
`cy.recordFormBuildId` / `cy.selectorShouldHaveUpdatedFormBuildId` are only
called by the `multivalue-form-design*.cy.js` specs (MR 9–10) — both are
arbitrary-wait patterns from an earlier refactor that most likely
shouldn't be ported at all; Playwright's built-in auto-waiting (or, in the
rare case an explicit wait is truly needed, `page.waitForLoadState
('networkidle')`) should be tried first when MR 9–10 is scoped.

### Phase 2 — Reusable Canvas page-object mixins

Each MR adds one mixin to `objects/canvas/` and migrates the specs that
need it.

| MR | New mixin | Migrates |
|---|---|---|
| **3** | `CanvasHistory` — `undo()`/`redo()` (`Meta+Z`), `expectUndoEnabled()`, plus `openPageDataForm()`/`openComponentForm()` on `CanvasNavigation` | `undo-redo`, `page-data-form` |
| **4** | `CanvasContextMenu` — `openContextMenu(target, {in: 'preview'\|'layers'})`, `chooseMenuItem()`; `CanvasSelection` — `selectMany()`, `expectSelected()`, `expectNameTag()` | `contextual-panel`, `multi-select-components`, `overlay-ui`, `expand-slots` |
| **5** | `CanvasClipboard` — `copySelection()`, `paste()`, `readClipboardStore()` + `context.grantPermissions` | `copy-and-paste` |
| **6** | `CanvasFrame` — `zoomIn/Out()`, `setZoom()`, `expectZoom()`, `ctrlWheel()`, `getScaledDimensions()` | `editor-frame`, `vh-units` |

MR 4 is the highest-leverage: right-click context menus and selection
assertions recur across ~10 remaining specs.

### Phase 3 — The page-data-form blind spot

Playwright currently never exercises a field widget inside
`canvas-page-data-form`. This is the single largest structural gap and
needs its own mixin before any of the four specs below can move.

**MR 7:** `CanvasPageDataForm` mixin — open the tab, scope locators to the
form, drive the media-library widget inside it, assert AJAX rebuilds.
Migrate `media-library-entity-form.cy.js`.

**MR 8:** Entity-form field widgets — port the 23
`entity-form-fields/*.js` modules to a TS table-driven
`entityFormFieldTypes.spec.ts`, plus a JSON:API read helper
(`?resourceVersion=rel:working-copy`). Migrates
`entity-form-field-types-test.cy.js` (195 lines + 23 modules).

**MR 9–10:** Multivalue widgets in the entity-form context — the widget
behaviors are already covered by `multivaluePropTypes.spec.ts` for
component props; only the entity-form integration path is missing. Add a
second `describe` there rather than new files. Also folds in the
genuinely-missing widget cases: link drag-reorder, server-side 422 leaving
the panel interactive, list dropdown chevron open/close and
re-enable-on-remove, number drag-reorder. Migrates `multivalue-form-design`,
`-link`, `-list`, `-number-integer`, `-date-time`,
`multivalue-media-form-design`.

### Phase 4 — Prop types (largest single gap)

`prop-types.cy.js` is 970 lines / 19 cases against `sdc_test_all_props`,
with no Playwright counterpart. Split by prop family into three MRs so
each diff is reviewable:

- **MR 11:** scalars — booleans with defaults, the 7 string formats, integer min/max/clamping, space-only and emptied-optional values, JSON-string textareas. Introduces `expectPropValidationMessage()` on `CanvasComponents` (`data-invalid-prop-value` / `data-prop-message`).
- **MR 12:** enums and dates — string/integer `_none` handling, date+time, date range (including PATCH payload shape), date-only.
- **MR 13:** CKEditor — block-format toolbar, `drupalSettings.editor.formats` config, typing a paragraph + list. Also absorbs the migratable parts of `ckeditor5.cy.js` (source mode, publish round-trip, Claro dialog styling); its already-skipped `can change text formats` test is dropped with a note in this ledger pointing at [#3522998].

### Phase 5 — Publish, review, routing

**MR 14:** `auto-path-alias.cy.js` — alias auto-generation, manual-override
lock, post-publish lock. Reproduce setup directly in the spec.

**MR 15:** publish error paths — extend `reviewChanges.spec.ts` /
`conflictResolution.spec.ts` with topbar status transitions
(`Published`/`Changed`/`No changes`), the review counter,
discard-without-reload, exception errors, and per-entity error links that
SPA-navigate to the erroring component. Adds `publishExpectingErrors()` to
`CanvasNavigation`. Migrates `publish-review`, `publish-validation`.

**MR 16:** `navigation.cy.js` — fold search, duplicate, delete,
set-homepage and staged-publish into `infiniteScroll.spec.ts`/a new
`contentNavigation.spec.ts`; extends `CanvasNavigation`.

### Phase 6 — Remaining long tail

Grouped by shared setup, roughly 4 MRs:

- **MR 17 — media:** remaining `media-library`, `media-library-component-instance`, `media-video-prop` gaps folded into `optionalImageDefault` / `mediaMultiCardinality` / `video`.
- **MR 18 — code components:** `link-code-component`, `code-component-image`, `realtime-preview-code-component` (preview must update *before* the PATCH), `component-transforms-and-evolution`.
- **MR 19 — panels and errors:** `primary-panel` (route-mock 50 components, hover previews), `error-handling` (418 boundary, 401 logged-out), `empty-canvas` (SEO/metatag fieldset, empty-region placeholder), `topbar-layout-preview-mode` gaps into `pagePreview.spec.ts`.
- **MR 20 — extensions and patterns:** `extension-legacy`, `pattern` insert-on-another-page gap into `patternEditing.spec.ts`, `autocomplete-props` gaps into `componentOperations.spec.ts`.

### Phase 7 — Non-e2e extraction

**MR 21:** `dom-to-redux.cy.js` — the store-propagation assertions move to
Vitest in `ui`; the Drupal `#states` interaction half stays e2e and folds
into the MR 2 `drupalStates.spec.ts`.

**MR 22:** `scope-css.cy.js` — **caveat:** `scopeCss` is a non-exported
closure in `js/ajax.command.customizations.js`, and the test asserts on
parsed `@scope` at-rules via `CSSStyleSheet`. jsdom supports neither. This
MR first extracts `scopeCss` into an importable ES module, then:
- CSS-variable resolution and selector-rewriting logic → Vitest (string-in/string-out, no CSSOM).
- `@scope` at-rule parsing assertions → Cypress component test (real browser), since Vitest cannot parse them. Confirm this split once the extraction is done rather than committing to it upfront.

### Phase 8 — Teardown

**MR 23:** Remove the Cypress e2e suite.

- Delete `ui/tests/e2e/` (including this README), `ui/tests/support/realDnd.js`, `spec-tags.js`, `parallel.js`, `flaky-specs.js`, and the e2e-only half of `commands.js` (keep `cy.mount` and anything the surviving component/unit tests use).
- Strip the `e2e` block and `setupNodeEvents` tasks from `ui/cypress.config.js`; keep the `component` block.
- Remove the `cypress E2E` GitLab job and its `retry.exit_codes: [112]` flaky handling; keep `cypress unit`.
- Drop now-unused devDeps (`cypress-real-events`, `cypress-axe`, `cypress-terminal-report`, `@cypress/skip-test`, `@cypress/webpack-preprocessor`, `fast-glob`, `minimist`) if nothing else uses them.
- Prune the e2e `cy:*` scripts from `ui/package.json`.
- Update `AGENTS.md`, `CONTRIBUTING.md` and `docs/testing/` to drop e2e-Cypress references.

### Upstream proposal

Any upstream proposals from this migration should remain Canvas-free and be
backed by usage across multiple specs before proposing patches.

### Sequencing summary

| Phase | MRs | Specs cleared |
|---|---|---|
| 0 Ledger + free deletions | 1 | 4 |
| 1 Foundation | 2 | 2 |
| 2 Mixins | 4 | 8 |
| 3 Page-data form | 4 | 8 |
| 4 Prop types | 3 | 2 |
| 5 Publish/routing | 3 | 4 |
| 6 Long tail | 4 | 12 |
| 7 Non-e2e | 2 | 2 |
| 8 Teardown | 1 | — |
| **Total** | **24** | **45** |

### Known risks

- **Per-test setup runtime.** `isolatedPerTest` installs per *test*. Consolidate overlapping coverage where possible so migration does not duplicate expensive setup/teardown paths.
