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

See `AGENTS.md` and the migration plan (canvas.drupal.org issue tracking
this effort) for the full rationale and MR sequencing.

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
| `autosave.cy.js` | Not started | `componentOperations.spec.ts` (partial), `mediaMultiCardinality.spec.ts` (partial) | Missing: deletion surviving reload. Reproduce the setup in-test and reuse existing coverage where possible before adding new helpers. |
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
| `cy.drupalCanvasInstall` / `cy.drupalInstall` | Not started | `tests/src/Playwright/fixtures/test.ts` (`isolatedPerTest`) + `setup.ts` | Playwright installs with the bare `minimal` profile and each migrated test reproduces only the setup it needs. Add helper modules only as a last resort. |
| `cy.drupalLogin` / `cy.drupalLoginAsAdmin` / `cy.drupalLogout` | Migrated | `Drupal.login()` / `Drupal.loginAsAdmin()` / `Drupal.logout()` (`@drupal/playwright`) | Direct equivalents already in use by every spec. |
| `cy.drupalCreateUser` / `cy.drupalCreateRole` | Migrated | `Drupal.createUser()` / `Drupal.createRole()` / `Drupal.addPermissions()` | Used in `tests/src/Playwright/setup.ts`. |
| `cy.drupalInstallModule` | Migrated | `Drupal.installModules()` | Used in `tests/src/Playwright/setup.ts` and via the `modules` fixture option. |
| `cy.clearAutoSave` | Migrated | `canvas_e2e_support` route exists (`/canvas-test/clear-auto-save/{type}/{id}`); no Playwright wrapper yet | Add a wrapper only if a migrated spec needs it and inline usage is no longer readable. |
| `cy.setKeyValue` | Migrated | `canvas_e2e_support` route exists (`/canvas-test/set-key-value/{collection}`); no Playwright wrapper yet | Add a wrapper only if a migrated spec needs it and inline usage is no longer readable. |
| `cy.previewReady` / `cy.getIframeBody` / `cy.waitForElement*InIframe` | Migrated | `CanvasBase.waitForEditorFrame()`, `CanvasUtilities.getActivePreviewFrame()` / `testInPreviewFrame()` | Playwright uses native `FrameLocator` + `expect().toPass()` retries instead of bespoke polling commands. |
| `cy.openLibraryPanel` / `cy.openLayersPanel` | Migrated | `CanvasNavigation.openLibraryPanel()` / `openLayersPanel()` (+ `openCodePanel`, `openPagesPanel`, `openTemplatesPanel`, `openBrandKitPanel`) | Playwright's navigation mixin has strictly more panel-opening helpers. |
| `cy.loadURLandWaitForCanvasLoaded` | Not started | `CanvasNavigation.createCanvas()` / `openCanvas()` | Different model: Cypress navigates by URL; Playwright usually creates content via the UI and reopens that route for persistence checks. |
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
