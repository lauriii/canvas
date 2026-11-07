# Inline text editing in Drupal Canvas

Design and implementation plan for editing plain text and formatted text directly on the
canvas, with the end goal of Gutenberg-style long-form authoring.

Status: proposal. No code changes are part of this document.
Grounded against `1.x` at commit `6181b9f1`.

---

## 0. Summary and recommendation

**Recommendation: edit in place, inside the preview iframe, on the DOM range the server
already marks for each prop. Keep every piece of editing chrome (toolbars, labels,
inserters) in the parent document's existing overlay layer.**

Three facts drive this:

1. Canvas already emits `<!-- canvas-prop-start-{uuid}/{propName} -->` /
   `<!-- canvas-prop-end-... -->` around prop output in preview renders
   (`src/Twig/CanvasWrapperNode.php:64-71`, emitted by the globally registered
   `src/Twig/CanvasPropVisitor.php:104-171`). **Nothing consumes these markers today** —
   `packages/preview-geometry/src/markers.ts:36-52` parses only `component`, `slot` and
   `region`. The hardest part of inline editing (mapping a prop to a DOM range) is
   already built and unused.
2. The preview iframe is same-origin `srcdoc`, and the editor already reaches into
   `iframe.contentDocument` to mutate live component props without a server round trip
   (`ui/src/features/layout/preview/IframeSwapper.tsx:96-118`). No postMessage protocol
   is needed.
3. Only in-iframe editing is actually WYSIWYG. The theme's CSS, fonts, measure and
   reflow live inside the iframe. An overlay editor positioned over the component would
   have to reconstruct all of that in the parent document, and would still get line
   breaking wrong the moment the text wraps.

The one structural obstacle is that **every settled prop edit today replaces the entire
iframe document** (`ui/src/services/preview.ts:195` dispatches `setHtml(html)` from the
`PATCH` response; `Preview.tsx:144` and `IframeSwapper.tsx:135-163` then swap `srcdoc`). A
caret cannot survive that. The plan's central mechanism is therefore a **text-edit
session**: while one is active, the in-iframe DOM is the source of truth, no server HTML
is applied, and the Redux layout model is written exactly once on commit.

Phases:

| Phase | Deliverable | Size |
|---|---|---|
| 0 | Prop boundaries parsed and exposed to the client | S (~1 week) |
| 1 | Inline editing of plain-text props (MVP) | M (~3 weeks) |
| 2 | Inline editing of formatted (rich) text props | L (~5 weeks) |
| 3 | Block-based long-form authoring | XL (~2-3 months, own OpenSpec change) |

---

## 1. What exists today

### 1.1 How a text prop is edited

The component-instance form is a **server-rendered Drupal form**, hydrated into React. It
is not JSON-schema-driven on the render side; JSON Schema is used only for client-side
validation.

- The client sends the whole layout node plus the prepared model as a query string on a
  `PATCH` to `canvas/api/v0/form/component-instance/{entity_type}/{entity_id}`
  (`ui/src/components/ComponentInstanceForm.tsx:405-412`,
  `ui/src/services/componentInstanceForm.ts:29-46`, route at `canvas.routing.yml:392-406`).
- `src/Form/ComponentInstanceForm.php:133-134` converts the client model to server inputs
  (`clientModelToInput()`) and builds a **real Drupal field widget per prop** via
  `src/Plugin/Canvas/ComponentSource/JsonSchemaPropsComponentSourceBase.php:893-947`.
- The response is JSON `{html: "<template data-hyperscriptify>…", css, js, settings,
  transforms}` (`src/Render/MainContent/CanvasTemplateRenderer.php:200-208`), hydrated by
  `hyperscriptify()` with `ui/src/components/form/twig-to-jsx-component-map.js` mapping
  `drupal-canvas-input` → `DrupalInput` (`:42`) and `drupal-canvas-textarea` →
  `DrupalTextArea` (`:52`).

Change propagation per keystroke:

1. `createEnhancedOnChange` (`ui/src/components/form/react-hook-form/utils/index.ts:145-210`)
   extracts the value and pushes it into react-hook-form.
2. The store write is **debounced 400 ms** (`DEBOUNCE_TIMEOUT = 400`,
   `ui/src/components/form/react-hook-form/fields/componentFormData.ts:17`, applied in
   `ui/src/components/form/react-hook-form/hooks/useDebouncedFormState.ts:22-26`).
3. `createComponentFormStateHandler`
   (`ui/src/components/form/react-hook-form/fields/componentFormHandlers.ts:43-122`)
   applies per-widget **transforms** (`ui/src/utils/transforms.ts:459-472`;
   `mainProperty` at `:131-156` is what reduces `{value, format}` to a scalar), fires a
   `ComponentPreviewUpdateEvent` for scalar props (`:66-73`), then calls
   `syncPropSourcesToResolvedValues()` + `patchComponent()` (`:89-97`).

Transforms are declared server-side per widget plugin in
`src/Hook/ReduxIntegratedFieldWidgetsHooks.php:299-335` and attached to the form at
`JsonSchemaPropsComponentSourceBase.php:1004`. Documented in
`docs/redux-integrated-field-widgets.md` §3.4.

### 1.2 Prop sources and the shape of a text prop

`src/PropSource/PropSource.php:33-43` enumerates source types: `static`,
`entity-field`/`dynamic`, `adapter`, `default-relative-url`, `host-entity`,
`host-entity-url`. TypeScript mirror at `ui/src/features/layout/layoutModelSlice.ts:149-178`.

Shape matching maps a JSON Schema prop to a field type + widget
(`src/JsonSchemaInterpreter/JsonSchemaType.php`):

| Prop schema | Field type / prop | Widget | Format |
|---|---|---|---|
| `type: string` | `string␟value` | `string_textfield` | — |
| `type: string, pattern: (.|\r?\n)*` | `string_long␟value` | `string_textarea` | — |
| `type: string, contentMediaType: text/html` (or `x-formatting-context: block`) | `text_long␟processed` | `text_textarea` | `canvas_html_block` (`:304`) |
| `… x-formatting-context: inline` | `text␟processed` | `text_textfield` | `canvas_html_inline` (`:305`) |

`inline` is still gated as `NOT YET SUPPORTED` for entity-field sources
(`JsonSchemaType.php:135`, blocked on CKEditor 5 inline support, drupal.org/i/3467959).
`src/PropShape/PropShape.php:278-299` (`isPlainOrRichProse()`) is the canonical
"is this prose?" test — the natural gate for "is this prop inline-editable?".

Stored shapes. A plain string prop collapses to a bare scalar in `inputs`; a rich text
prop keeps the field-item map:

```php
// Plain string
'heading' => [
  'sourceType' => 'static:field_item:string',
  'expression' => 'ℹ︎string␟value',
  // value: 'My banner title'
],
// Formatted text
'text' => [
  'sourceType' => 'static:field_item:text_long',
  'expression' => 'ℹ︎text_long␟processed',
  'value' => ['value' => '<p>…</p>', 'format' => 'canvas_html_block'],
  'sourceTypeSettings' => ['instance' => ['allowed_formats' => ['canvas_html_block']]],
],
// resolved: ['text' => '<p>…</p>']   // server-processed via check_markup()
```

(`src/PropSource/StaticPropSource.php:90-107`, `:433-481`; fixtures in
`tests/src/Kernel/Plugin/Canvas/ComponentSource/SingleDirectoryComponentTest.php:3945-3966`
and `:7685-7706`.)

**So: `source.value` is the raw authored `{value, format}`; `resolved` is the
`check_markup()`-processed HTML.** `inputToClientModel()` drops `source[prop].value` when
it equals `resolved` (`JsonSchemaPropsComponentSourceBase.php:616-628`);
`clientModelToInput()` restores it (`:1368-1372`); the client mirror is
`syncPropSourcesToResolvedValues()` (`ui/src/features/layout/layoutModelSlice.ts:225-283`).

### 1.3 Preview rendering, selection, and the overlay

Documented in `docs/react-codebase/page-preview.md`. Structure:

```
Preview.tsx
└─ Viewport.tsx
   ├─ IframeSwapper.tsx  → <iframe data-canvas-iframe="A"|"B">   (srcdoc)
   └─ ViewportOverlay.tsx → RegionOverlay → ComponentOverlay → SlotOverlay
```

- The preview document is a **whole-page HTML string** delivered as JSON and assigned to
  `iframe.srcdoc` (`ui/src/services/preview.ts:63-78` → `previewSlice.ts:44`,
  `Preview.tsx:188`, `Viewport.tsx:108`). Server side:
  `src/Controller/ApiLayoutController.php:562` + `src/Render/MainContent/CanvasPreviewRenderer.php:44`
  (assets are inlined into the HTML, `:74`).
- `IframeSwapper.tsx` keeps **two** iframes and swaps them by opacity once Astro islands
  have hydrated, with a 1 s fallback (`:57-86`, `:136-163`). This exists to kill flicker
  (drupal.org/i/3469677).
- **The overlay deliberately blocks all interaction with the iframe**
  (`page-preview.md:60-63`). Overlays render only in `EditorFrameMode.EDIT`
  (`Viewport.tsx:114`). Pressing `v` toggles `EditorFrameMode.INTERACTIVE`, which hides
  the overlay and makes the iframe focusable (`EditorFrame.tsx:124-131`,
  `IframeSwapper.tsx:184`, enum at `ui/src/features/ui/uiSlice.ts:33-36`).
- Component/slot/region positions come from HTML comment markers parsed by
  `packages/preview-geometry` (`markers.ts:115` `discoverCanvasBoundaries()` via a
  `TreeWalker` over `SHOW_ELEMENT | SHOW_COMMENT`), measured by `measure.ts:96`, and
  re-measured by `observer.ts:17` (ResizeObserver + MutationObserver + scroll/animation/
  font-loading listeners, batched into one rAF). Wired in
  `ui/src/hooks/useComponentHtmlMap.ts:25` → `PreviewGeometryContext.tsx:37`.
  `mapCanvasDocument()` (`ui/src/utils/function-utils.ts:68-81`) additionally stamps
  `element.dataset.canvasUuid` on every element between a component's markers and stores
  the element list in `PreviewDomContext.tsx:43`.
- Overlays are portalled into `#canvasPreviewOverlay` so editor zoom does not scale UI
  chrome (`PreviewOverlay.tsx:14`, `ViewportOverlay.tsx:115`). Zoom is a raw CSS
  `transform: scale()` on the container wrapping the iframe
  (`EditorFrame.tsx:389`), and every overlay rect is multiplied by
  `editorViewPortScale` (`ComponentOverlay.tsx:123-137`).
- Selection lives in `uiSlice`: `selection: {items: string[], consecutive: boolean}`
  (`uiSlice.ts:26`, `:131`), reducers `setSelection` (`:360`) / `clearSelection` (`:357`).
  Clicking a `ComponentOverlay` (`:98`) routes through
  `ui/src/hooks/useComponentSelection.ts`, which dispatches `setSelection` **and**
  navigates to `/component/:componentId` (`:110`, `ui/src/app/AppRoutes.tsx:146`) — the
  URL is what opens the prop form.

**Nothing in the preview is editable today.** Grepping `contentEditable|designMode|
execCommand|Range|getSelection` across `ui/src`, `packages/*/src`, `src`, `js` and
`templates` finds only a hotkey guard in
`ui/src/features/versionComparison/PageVersionComparisonView.tsx:63` and an attribute map
in hyperscriptify.

### 1.4 Client↔server flow, auto-save, undo/redo

**Endpoints** (`canvas.routing.yml:463-568`, `src/Controller/ApiLayoutController.php`):

- `GET  …/layout/{entity_type}/{entity}` → editor state: `layout`, `model`, `autoSaves`,
  `entity_form_fields`, `translations` (`:94-247`).
- `PATCH …/layout/{entity_type}/{entity}` → one component instance changed (`:378-466`).
  Body: `{componentInstanceUuid, componentType: "id@version", model: {source, resolved},
  autoSaves, clientInstanceId}`. It validates auto-save hashes (`:426-430`), writes inputs
  through `clientModelToInput()` (`:749-772`), auto-saves (`:450`), and returns the whole
  recomputed `layout` + `model` + `entity_form_fields` + **a full page HTML string**.
- `POST …/layout/{entity_type}/{entity}` → persist whole layout + fields, return preview
  HTML (`:473-560`).

There is **no partial/per-component render endpoint**. Every response is one whole-page
string (`src/Render/PreviewEnvelope.php:12-19`,
`src/EventSubscriber/PreviewEnvelopeViewSubscriber.php:34-47`).

**Redux.** `ui/src/features/layout/layoutModelSlice.ts:285-541`, state
`{layout: RegionNode[], model: ComponentModels, updatePreview, isInitialized, translations}`
(`:82-94`). `model` is keyed by component instance UUID (`:77-80`). Structural actions
(`deleteNode`, `duplicateNode`, `moveNode`, `insertNodes`, `sortNode`, `shiftNode`,
`:295-502`) each set `updatePreview = true`. **Prop updates have no local reducer** —
they are server-first via the `updateExistingComponentValues` thunk (`:711-804`), and the
server's massaged model overwrites the optimistic one when
`setLayoutModel({layout, model, updatePreview: false})` is dispatched from
`ui/src/services/preview.ts:199`.

**Auto-save.** `src/AutoSave/AutoSaveManager.php` stores into the `canvas.auto_save`
key-value store keyed `entityType:id[:langcode]` (`:73`, `:370-377`), with
`data_hash`/`original_hash`/`client_id` (`:217-237`). Conflicts are 409s from
`src/Controller/AutoSaveValidateTrait.php:53-82`; `autoSaves` + `clientInstanceId` are
injected into every mutation by `ui/src/services/baseQuery.ts:174-226`. Client cadence:
400 ms form debounce, plus a further 1000 ms (`POLLED_BACKGROUND_TIMEOUT`,
`componentFormHandlers.ts:14`) when a client-side preview update already succeeded;
identical consecutive PATCH bodies are dropped (`preview.ts:242-249`).

**Undo/redo.** `redux-undo` wraps only `layoutModel` and `pageData`
(`ui/src/app/store.ts:109-146`). No `limit`, **no `groupBy`**. The history filter
(`:116-124`) records everything except `setInitialLayoutModel`, `setTranslations` and
`setUpdatePreview` — so `setLayoutModel` from every PATCH response **is** a history entry.
`historyEraser` (`:63-105`) cross-invalidates the other slice's redo stack and force-sets
`updatePreview: true` on past/future entries. The user-facing timeline is
`ui.undoStack`/`ui.redoStack` (`uiSlice.ts:44-56`), driven by `undoRedoActionIdMiddleware`
(`store.ts:180-236`). Forms re-sync from Redux after undo via
`ui/src/components/form/react-hook-form/hooks/useUndoRedoSync.ts:19-60`.

Net effect: typing does not create one history entry per keystroke, but it does create
**one entry per 400 ms typing pause**. That is already poor granularity, and inline
editing must not make it worse.

### 1.5 Rich text today

CKEditor 5 runs **only in the sidebar prop form**, never on the canvas.

- Drupal builds a `text_format` element; Canvas rewrites it in
  `src/Hook/ReduxIntegratedFieldWidgetsHooks.php:349-441` — renaming `data-editor-for` to
  `data-canvas-editor-for` (`:400`, `:407`) so core's vanilla-JS attach never fires, and
  serializing the format select into data attributes (`:415-433`).
- `ui/src/components/form/components/drupal/DrupalTextArea.tsx:50-68` branches on
  `format.editor === 'ckeditor5'` and renders
  `ui/src/components/form/components/drupal/DrupalFormattedTextArea.tsx`, which mounts
  `@ckeditor/ckeditor5-react`'s `<CKEditor>` (`:177`) with `ClassicEditor` destructured
  from the `window.CKEditor5.editorClassic` global (`:172-173`) and plugin classes
  resolved off `window.CKEditor5[build][name]` (`:139-151`). Config processing is copied
  from core's `ckeditor5.js` (`:48-152`). It syncs to Redux through a **hidden
  `<textarea>`** (`:214-220`) and `fieldContext.triggerChange` (`:210`).
- Only two text formats ship, both locked:
  `config/install/filter.format.canvas_html_block.yml:17` allows
  `<strong> <em> <u> <a href> <p> <br> <ul> <ol> <li>`;
  `filter.format.canvas_html_inline.yml:17` allows `<strong> <em> <u> <a href>`.
  **Neither allows headings, images or embeds.** `use` access is granted to everyone and
  every other operation is forbidden (`src/Hook/ShapeMatchingHooks.php:176-244`).
- Sanitization is entirely server-side: `resolved` is the `processed` computed property,
  i.e. core's `TextProcessed` → `check_markup()`. Canvas never calls `check_markup()`
  itself. Field type overrides at
  `src/Plugin/Field/FieldTypeOverride/TextLongItemOverride.php:25-38` mark `processed`
  required with `StringSemantics: markup`.
- Code components render HTML props through
  `packages/drupal-canvas/src/FormattedText.tsx:17` — `dangerouslySetInnerHTML` on an
  already-server-filtered string.
- Dependencies: `@ckeditor/ckeditor5-react` (`ui/package.json:34`) is the **only**
  rich-text package in the repo. No `lexical`, `prose-mirror`, `slate`, `tiptap`, `quill`,
  `tinymce`. CKEditor 5 itself arrives as Drupal asset libraries on `window.CKEditor5`.

### 1.6 Component model, SDC, code components, headless

- The layout is a **flat ordered list of component instances** in the `component_tree`
  field type (`src/Plugin/Field/FieldType/ComponentTreeItem.php:121`, schema at `:223-280`).
  Field props: `uuid`, `component_id`, `component_version`, `parent_uuid`, `slot`,
  `inputs` (a JSON column), `label`. Nesting is `parent_uuid` + `slot`; sibling order is
  the field item delta (`~/Projects/canvas-specs/specs/component-tree/spec.md:11`,
  `docs/data-model.md:200-263`).
- **A slot holds component instances, never raw text.** Text always lives in a prop,
  resolved through a prop source.
- Client-side the flat list becomes `ComponentNode {uuid, type, slots: SlotNode[]}` with
  `SlotNode.id = "${componentUuid}/${slotName}"`
  (`layoutModelSlice.ts:50-63`, `:629`) — the same identity format the
  `canvas-slot-*` markers carry.
- Code components are a separate config entity `js_component`
  (`src/Entity/JavaScriptComponent.php:80-119`) with the same JSON Schema prop
  conventions; they render as `<canvas-island props="…">` Astro islands, so their props
  live inside a JSON attribute rather than in the DOM.
- **Canvas ships exactly one SDC** (`components/image/image.component.yml`), and it is
  `noUi: true` (`:4`). `themes/canvas_stark/` ships none. There is **no shipped
  paragraph / heading / prose component set** anywhere in the module.
- Patterns (`src/Entity/Pattern.php:40`) are copy-by-value prefab subtrees, not live
  transclusion.

### 1.7 Three latent assets worth naming

1. **Prop boundary markers already exist and are unused.**
   `src/Twig/CanvasWrapperNode.php:64-71` emits
   `<!-- canvas-prop-start-{uuid}/{propName} -->` for every Twig `{{ prop }}` print in a
   preview render, whenever the print is *not* a declared slot name. The visitor is
   registered globally (`src/Twig/CanvasTwigExtension.php:54-56`) and the context vars are
   set for SDC, JS and Fallback sources
   (`src/Plugin/Canvas/ComponentSource/SingleDirectoryComponent.php:155-157`,
   `JsComponent.php:534-536`, `Fallback.php:71-72`). No client code and no test reads
   them.
2. **An interactive iframe mode already exists** (`EditorFrameMode.INTERACTIVE`, hotkey
   `v`), so the "let events through to the iframe" plumbing is precedent, not invention.
3. **Direct in-iframe DOM patching already exists** for code components
   (`IframeSwapper.tsx:96-118`), including the "we handled it, skip the round trip" flag
   (`componentFormHandlers.ts:66-80`). Inline editing generalizes this pattern rather than
   introducing a parallel one.

### 1.8 In-flight OpenSpec changes this depends on or overlaps

All under `~/Projects/canvas-specs/changes/`. None of these are present in this checkout
of `1.x` — spec status is ahead of code.

| Change | Relevance |
|---|---|
| `native-prop-forms` | Moves standard widgets client-side behind a widget registry, with a server-form escape hatch. Inline editing needs a client-owned value/codec write path, not a Form API round trip. |
| `native-rich-text-widget` | Extracts the CKEditor 5 host out of `DrupalFormattedTextArea` into a shared `{value, onChange, editorSettings, disabled}` component (design D3), adds `GET /canvas/api/v0/text-editor-settings` for per-format editor settings + asset URLs (D1/D2), and defines the `{resolved: raw markup, source: {value, format}}` codec with server echo authority (D4). **Its `design.md:21` explicitly lists on-canvas inline rich text as a Non-Goal** — this plan is that follow-up. Phase 2 should consume its editor host and settings endpoint, not fork them. |
| `canvas-preview-performance` | Stateless partial-render endpoint, client subtree patching, optimistic structural ops, auto-save decoupled from preview render. **Phase 3 hard-depends on this.** |
| `instant-prop-forms` | Prepare-then-reveal subtree swaps (hydrate islands and decode images before revealing). Directly reduces the "commit blows away the canvas" jolt. |
| `component-prop-adapters` | Surfaces adapter-backed props. A constraint: an inline-edited prop may be entity-field- or adapter-backed and therefore not directly editable. |
| `canvas-realtime-collaboration` | Op-based writes, per-component soft locks, operation-inverse undo. Inline editing is the highest-frequency op producer. |
| `exposed-slots` | Per-entity editable slots backed by their own `component_tree` field. This is where a long-form article body would live. |

---

## 2. UX options, compared

### Option A — contenteditable inside the preview iframe (recommended)

The prop's existing DOM range becomes editable in place. Chrome (formatting toolbar,
"editing Heading" label, block inserter) renders in the parent document's overlay layer,
positioned with the geometry machinery that already positions component outlines.

*Plain text*: `contenteditable="plaintext-only"` on the range. The browser handles caret,
selection, IME, native undo, and strips formatting on paste. Zero custom key handling.

*Formatted text*: CKEditor 5 attached to the same in-iframe element, with its own toolbar
suppressed and a Canvas-owned floating toolbar in the overlay driving `editor.execute()`.

Feel: you click into a heading and the heading itself gets a caret, in the theme's real
font, at the real size, wrapping at the real measure. Typing reflows the surrounding
layout live because it *is* the layout. The right sidebar stays open and stays in sync.

+ Only option that is genuinely WYSIWYG.
+ Prop ranges come free from markers that already exist.
+ Keystrokes fire inside the iframe, so they never reach the parent's destructive hotkeys
  (`Backspace`/`Delete` = delete component, `mod+v` = paste component,
  `EditorFrame.tsx:124-131`).
+ Reuses the direct-DOM-access pattern already proven at `IframeSwapper.tsx:96-118`.
− Requires suppressing the full-document re-render for the duration of an edit.
− The iframe sits inside a CSS `transform: scale()` container (`EditorFrame.tsx:389`);
  caret and selection under a scaled ancestor need verification per browser.
− Any editing style injected into the iframe risks bleeding into the themed document
  (mitigated by keeping chrome in the parent and injecting only a focus ring + caret
  color).

### Option B — overlay editor positioned over the component

An `<input>`/`<textarea>`/CKEditor in the parent document, absolutely positioned over the
measured component rect, styled by copying computed styles out of the iframe.

+ No iframe mutation, no re-render race.
+ Geometry already available from `PreviewGeometryContext`.
− Not WYSIWYG in practice. Matching `font-family`, `font-size`, `line-height`,
  `letter-spacing`, `text-transform`, `color` and `text-align` is achievable; matching
  **line breaking and reflow of surrounding content** is not. The illusion collapses the
  moment text wraps or the block grows.
− Parent hotkeys must be guarded per-key.
− Two rendering paths for the same text.

Verdict: **keep as the fallback**, not the primary. It is the right answer for props that
have no usable DOM range (props printed into attributes, e.g. `alt` or `href`) and
possibly for zoomed/touch contexts.

### Option C — a separate block-based authoring surface

A Gutenberg-style document editor replacing the preview.

− Forks the rendering model: two different ways to see a page, one of which is not the
  real render. This contradicts the premise the whole preview architecture is built on
  (`docs/react-codebase/page-preview.md`).
− Every theme would need a second, editor-only representation.

Verdict: **rejected as an architecture**, adopted as an *interaction layer* in Phase 3.
The block behaviors (Enter creates a sibling, Backspace merges, `/` opens an inserter,
per-block toolbar) run against the component tree while the surface stays the real
preview. That is Gutenberg's feel without Gutenberg's parallel canvas.

### What long-form authoring should feel like

Click into the article body. A caret appears in the real rendered paragraph. Type; the
page reflows. Select a phrase; a small toolbar floats above it with exactly the buttons
the text format's CKEditor configuration declares. Press Enter at the end of the
paragraph: a new empty paragraph appears immediately (optimistically, no spinner) and the
caret is in it. Type `/` and an inserter opens at the caret listing the same components
the left library offers. Press Backspace in an empty paragraph and it is removed, caret
landing at the end of the previous one. Paste three paragraphs and an image from a Google
Doc and you get four component instances, each independently selectable, draggable and
configurable in the sidebar. Nothing you can do inline is unavailable in the sidebar, and
vice versa — inline editing is a second way into the same model, never a second model.

---

## 3. Architecture

### 3.1 Prop → DOM range

Extend `packages/preview-geometry` to parse the prop markers that already ship:

- `packages/preview-geometry/src/types.ts:5` — `CanvasBoundaryType` gains `'prop'`.
- `packages/preview-geometry/src/markers.ts:36-52` — add
  `prop: {start: 'canvas-prop-start-', end: 'canvas-prop-end-'}`.
- `markers.ts:263` — generalize `parseCanvasSlotIdentity()` (it already splits
  `"{uuid}/{name}"`, the exact format prop markers use) into a shared
  `parseQualifiedIdentity()` used for both `slot` and `prop`.
- `ui/src/features/layout/preview/PreviewGeometryContext.tsx:37` — index prop boundaries
  alongside component/slot/region.

That is roughly a 30-line change and it unlocks everything downstream.

**Coverage is partial, and this must be stated plainly.** `CanvasPropVisitor::enterNode()`
only wraps a `PrintNode` whose expression is a bare `NameExpression`
(`src/Twig/CanvasPropVisitor.php:115-117`), outside `{% trans %}` (`:94-99`), in a
position where the accumulated buffer parses as valid HTML5 (`:155-157`). So in the
`banner` fixture, `{{ heading }}` gets markers but `{{ text|default('') }}` does **not** —
it is a `FilterExpression`. Neither do props printed into attributes, props renamed
through `{% include %}`, or props of code components (they live in a JSON attribute on
`<canvas-island>`).

Mitigations, in order of laziness:

1. Extend the visitor to also wrap a `FilterExpression` chain whose innermost node is a
   `NameExpression` (covers `|default`, `|raw`, `|t`, `|nl2br`). Small, well-scoped,
   testable. **Phase 0.**
2. Props with no range simply are not inline-editable; the sidebar form remains, which is
   today's behavior, so there is no regression. Surface this honestly in the UI (the
   overlay just does not offer an inline affordance).
3. Code components: defer. A later phase can have
   `packages/drupal-canvas/src/FormattedText.tsx` stamp
   `data-canvas-prop="{uuid}/{propName}"` when rendering in preview mode, which the same
   discovery code can read (`markers.ts:206-247` already supports an attribute-based
   marker format for headless frontends).

### 3.2 The text-edit session

New, non-undoable state — a session is transient UI, not model history:

```ts
// ui/src/features/ui/uiSlice.ts
textEditSession: { componentUuid: string; propName: string } | null
```

While a session is active:

| Concern | Behavior |
|---|---|
| Source of truth | The in-iframe DOM. Not Redux, not the server. |
| `Preview.tsx:144` POST effect | Skipped. |
| `preview.ts:195` `setHtml(html)` | Skipped (the response's `layout`/`model` may still be applied for auto-save-hash purposes, but **not** the HTML). |
| `layoutModel` writes | None. The in-progress value never enters the undoable slice. |
| Undo history | No entries. Exactly one entry is created on commit. |
| Auto-save | A heartbeat `PATCH` every ~5 s of continuous editing, sent directly through the RTK mutation with HTML application suppressed, so a long session is never at risk of data loss. |
| Overlay | The active component's outline switches to an "editing" treatment; its drag handle, name tag and drop zones are suppressed. Other components stay selectable (clicking one commits and re-targets). |

On commit (blur / `Escape` / click elsewhere / selecting another component):

1. Read the value — `element.textContent` for plain props, `editor.getData()` for rich
   props. **Never `innerHTML` of the live DOM**: it would capture theme-injected markup
   and hydration artifacts.
2. Dispatch `updateExistingComponentValues`
   (`ui/src/features/layout/layoutModelSlice.ts:711-804`) once. This is the single undo
   entry and the single `PATCH`.
3. Clear the session, which re-enables `setHtml` and lets the normal full re-render
   settle. The rendered text may shift slightly if the server massages or filters the
   value — the same echo behavior the sidebar form already has.

Keeping the in-progress value out of Redux is deliberate. It mirrors exactly how the form
works today (react-hook-form holds transient state; Redux only ever receives settled
values, `useDebouncedFormState.ts:22-26`), so this is the existing pattern applied to a
new input surface, not parallel infrastructure. It also avoids a `redux-undo` trap: if
intermediate `setLayoutModel` actions were merely filtered out of history
(`ui/src/app/store.ts:116-124`), the final commit would push a *mid-session* `present`
into `past`, and undo would land in the middle of the user's typing.

### 3.3 Writing to the model, auto-save and the server

No new endpoint is required for Phases 0-2. Commit reuses the existing per-component
`PATCH …/layout/{entity_type}/{entity}` (`ApiLayoutController.php:378-466`) with the
existing payload:

```jsonc
{
  "componentInstanceUuid": "…",
  "componentType": "sdc.canvas_test_sdc.banner@0e79e884426a53ae",
  "model": {
    "source":   { "text": { "sourceType": "static:field_item:text_long",
                            "expression": "ℹ︎text_long␟processed",
                            "value": { "value": "<p>…</p>", "format": "canvas_html_block" } } },
    "resolved": { "text": "<p>…</p>" }
  },
  "autoSaves": { … }, "clientInstanceId": "…"
}
```

For a plain string prop, `resolved[prop]` is the string and
`syncPropSourcesToResolvedValues()` (`layoutModelSlice.ts:225-283`) copies it into
`source[prop].value` — identical to what the sidebar form produces. For a formatted prop,
the client writes the raw markup as the optimistic `resolved` and `{value, format}` as the
`source`; the server's `processed` output on the echo is authoritative. This is precisely
the codec contract `native-rich-text-widget` design D4 already specifies, so inline
editing does not introduce a second write path.

Auto-save, conflict detection (409 via `AutoSaveValidateTrait.php:53-82`) and the
`autoSaves`/`clientInstanceId` injection (`ui/src/services/baseQuery.ts:174-226`) are
unchanged.

### 3.4 Rich text formatting → Drupal text formats

Unchanged data model, unchanged security model:

- The prop's permitted formats come from `sourceTypeSettings.instance.allowed_formats`,
  pinned by shape matching to `canvas_html_block` / `canvas_html_inline`
  (`JsonSchemaType.php:304-305`).
- The inline toolbar's button set is derived from that format's CKEditor 5 configuration
  (`editor.editor.canvas_html_block.yml` toolbar `bold, italic, underline, link, |,
  bulletedList, numberedList`), delivered via the `text-editor-settings` endpoint from
  `native-rich-text-widget`. **No client-side hardcoding of editor capabilities.**
- CKEditor's schema, built from that configuration, is what constrains typing and paste.
  Anything the format disallows is dropped in the editor's model before it ever reaches
  the DOM.
- The server remains the sanitization authority: `resolved` is always
  `check_markup()`-processed output on evaluation. The optimistic raw markup exists only
  in the author's own preview, which is exactly the status quo for the sidebar path.
- Format switching stays in the sidebar. Formats are single-valued in practice today, and
  a format select floating over the canvas is UI noise for a control that is almost never
  actionable.

### 3.5 Selection, caret and undo

- **Component selection and text selection are different states.** Entering a text
  session does not clear the component selection — the sidebar form stays open on the
  same component, so the two surfaces are visibly two views of one prop.
- Within a session, `Ctrl/Cmd+Z` belongs to the text editor: the browser's native
  contenteditable undo for plain props, CKEditor's undo stack for rich props. This is the
  Google-Docs/Gutenberg expectation and it falls out for free, because keystrokes inside
  the iframe never reach the parent's `useHotkeys` bindings.
- Outside a session, `Ctrl/Cmd+Z` is Canvas's global undo, unchanged
  (`ui/src/components/UndoRedo.tsx:11-64`, which already accepts an undo request
  posted from the iframe).
- One session produces exactly one Canvas history entry. This is strictly better than
  today's one-entry-per-400 ms-pause behavior for typing.
- After a Canvas undo lands on a component with an open session, the session is cancelled
  (the DOM it was anchored to no longer exists) — same shape as the existing
  `useUndoRedoSync.ts:19-60` form re-sync.

### 3.6 How a long-form block maps to the component model

**A block is a component instance.** This needs no new concept: the tree is already a
flat ordered list where sibling order is the field item delta, and nesting is
`parent_uuid` + `slot` (`specs/component-tree/spec.md:11`). Gutenberg's block list *is*
Canvas's list of components in a slot or region.

The corollary, which is the important design decision: **structure lives in the tree,
inline formatting lives in the prop.** A heading is a `Heading` component with a
`text` prop, not an `<h2>` inside one giant rich text blob. Bold and links are inline
formatting inside a paragraph's prop. This is the same split Gutenberg makes, and it has
three consequences worth stating:

- The shipped text formats do not need to change. `canvas_html_block`'s restricted tag
  set (no headings, no images) stops being a limitation once structure lives in
  components.
- Every block can be selected, dragged, duplicated, styled and translated on its own,
  because it is an ordinary component instance.
- A 60-paragraph article is 60 field items. `docs/adr/0006-One-field-row-per-component-instance.md`
  is the relevant prior decision; the tree size implications need measuring before
  Phase 3 commits (see open question Q6).

The alternative — one component instance with one large rich text prop and a permissive
format — is much cheaper but gives up per-block design control, drag-and-drop, and
per-block props, and requires loosening the deliberately locked shipped formats. It is
the right answer only if the product goal is "a body field that looks right", not
"Gutenberg-style authoring".

**Canvas ships no prose components today.** For Phase 3 to mean anything, a prose set
(Paragraph, Heading, List, Quote, Image, Separator) has to come from somewhere — a
shipped module, a recipe, or the theme. That is a product decision (Q4), not a technical
one.

---

## 4. The hard problems

### 4.1 The iframe boundary and contenteditable

The iframe is same-origin `srcdoc`, so it is not a security boundary and direct DOM
access works. The real problems are lifecycle, not access:

- **The document is replaced wholesale on every settled edit.** Solved by the text-edit
  session (§3.2). This is the single most important mechanism in the plan.
- **The `IframeSwapper` double-buffers**: on a re-render the *inactive* iframe receives
  the new `srcdoc` and is swapped in (`IframeSwapper.tsx:135-163`). Any session-held DOM
  reference must be invalidated on swap, not merely on re-render.
- **The iframe lives inside `transform: scale()`** (`EditorFrame.tsx:389`). Caret
  rendering and `Range.getBoundingClientRect()` under a scaled ancestor need per-browser
  verification, and the floating toolbar's position must be mapped through
  `editorViewPortScale` the way `ComponentOverlay.tsx:123-137` already does. Fallback if
  it proves flaky: refuse to start a session at scale ≠ 1, or reset zoom on entry (Q9).
- **The overlay blocks pointer events by design** (`page-preview.md:60-63`). A session
  must let events through to exactly one element. Precedent exists in
  `EditorFrameMode.INTERACTIVE` (`Viewport.tsx:114`), but that hides the whole overlay;
  a session needs the overlay's chrome to stay visible while its hit-testing surface
  becomes transparent over the active editable.
- **Astro island hydration** may re-render a code component's DOM underneath a live
  editable. Code components are excluded from inline editing until their props have DOM
  ranges (§3.1).

### 4.2 Sanitization and XSS

The threat model does not change much, because the preview iframe already shares the
editor's origin and already renders raw optimistic values
(`IframeSwapper.tsx:110-116` writes an unfiltered prop value into a live island).

What inline editing adds is a paste surface. Rules:

- **Plain props**: read and write `textContent` only. Never `innerHTML`.
  `contenteditable="plaintext-only"` makes the browser enforce this on paste.
- **Rich props**: CKEditor's model is the filter. Its schema is built from the text
  format's own configuration, so pasted `<script>`, `<iframe>` or `onerror` attributes are
  dropped before they reach the DOM, and the persisted value can only contain what the
  format's CKEditor plugins can represent.
- **Server stays authoritative.** `resolved` is always `check_markup()` output on
  evaluation (`TextProcessed` via `TextLongItemOverride.php:25-38`). The raw optimistic
  value is visible only to its own author, only until the echo returns.
- Format use permission is enforced server-side on write, unchanged.

The genuinely new risk is narrow: a value that is raw in the author's own DOM during a
session could, if a session-scoped PATCH response were rendered into *another* editor's
preview before filtering, become stored XSS. It cannot, because every preview renders
`processed`. This should nonetheless get an explicit test.

### 4.3 Paste

- Plain props: handled by `plaintext-only`. Newlines collapse to spaces for single-line
  props (`string`), are preserved for `string_long`.
- Rich props: CKEditor's clipboard pipeline, constrained by the format schema. Free.
- Long-form (Phase 3): pasting multi-block HTML should produce **multiple component
  instances**, not one blob. This needs a "raw handler" mapping `h1`-`h6` → Heading,
  `p` → Paragraph, `ul`/`ol` → List, `img` → Image, unknown → Paragraph with inline
  formatting stripped to the format's allowed tags, then a single `insertNodes` dispatch.
  This is a substantial piece of work and is the main reason Phase 3 is XL.

### 4.4 The formatting toolbar

Constraint: Drupal core registers `ClassicEditor` (`window.CKEditor5.editorClassic`,
`DrupalFormattedTextArea.tsx:172-173`), which renders its own sticky toolbar attached to
the editable. Injecting that chrome into the themed iframe document would cause layout
shift and two-way style bleed.

**Recommendation: floating toolbar, rendered by Canvas in the parent overlay, driving
CKEditor commands.**

- Mount `ClassicEditor` on the in-iframe element; suppress its toolbar
  (`.ck-editor__top { display: none }` in the small stylesheet injected into the iframe).
- Render a Radix toolbar in the parent, portalled into `#canvasPreviewOverlay` alongside
  the other overlays, positioned from the selection rect mapped through
  `editorViewPortScale`.
- Buttons are generated from `editorSettings.toolbar.items` (the format's own
  configuration) and dispatch `editor.execute('bold')` etc., reading state from
  `editor.commands.get('bold').value`.

This uses the public, stable CKEditor command API rather than relocating CKEditor's own
toolbar DOM across documents (which would be the alternative, and is fragile). It also
means the toolbar is a Canvas design-system component, consistent with the rest of the
editor, and it inherits the existing zoom-independent portal behavior for free. The cost
is a per-button mapping table for the toolbar items Canvas's shipped formats use, which
is a small, bounded list.

Alternative considered and rejected: a fixed toolbar in the topbar. It loses the
connection to the selection and is worse for long documents.

### 4.5 Collaborative and concurrent edits

Today: last-write-wins per prop with a hash-based 409 at the auto-save boundary
(`AutoSaveValidateTrait.php:53-82`). Inline editing raises write frequency but does not
change the shape.

Under `canvas-realtime-collaboration`:

- A text session should acquire that change's **per-component soft lock** for its
  duration, and release on commit.
- Character-level operational transform / conflict-free merge of prop text is **out of scope**.
  The op granularity is a whole prop value; two people in the same paragraph get
  last-write-wins with presence indication, not merged text. Say so explicitly rather
  than implying Google-Docs semantics.
- Collaborative undo is operation-inverse in that change; one session = one operation
  keeps that clean.

### 4.6 IME and mobile

- **Never read the DOM or dispatch during composition.** Track `compositionstart` /
  `compositionend` on the editable and gate the heartbeat and commit on it. Reading
  `textContent` mid-composition captures provisional characters and can cancel the IME.
- CKEditor 5 handles IME internally for rich props; the session wrapper must still not
  commit mid-composition.
- **Mobile/touch is out of scope for v1.** The editor frame is a zoomed, scaled iframe
  inside a desktop-oriented layout; virtual keyboards, selection handles and the
  `visualViewport` interaction with a scaled ancestor are their own project. Q8.

### 4.7 Accessibility

Non-negotiable, not a later phase:

- Entering a session must move **real DOM focus** into the in-iframe editable, and
  announce it via an `aria-live` region in the parent ("Editing Heading. Press Escape to
  finish.").
- The editable gets `role="textbox"`, `aria-label` from the prop's `title`, and
  `aria-multiline` where applicable. CKEditor supplies these for rich props.
- Keyboard-only entry: a focused `ComponentOverlay` must offer `Enter` to start editing
  its (single) editable prop, and a discoverable path when it has several.
- `Escape` always exits to the component-selected state without losing the value. It must
  not also clear the component selection in the same keypress.
- The floating toolbar must be a proper toolbar widget (roving tabindex) and reachable
  from the editable by keyboard; `Alt+F10` is the CKEditor convention and should be
  honoured.
- Text must not become illegible at low zoom; a session should either force scale 1 or
  refuse to start below a threshold.
- Extend `tests/src/Playwright/tests/a11y.spec.ts` rather than adding a new spec.

---

## 5. Phased implementation plan

### Phase 0 — Prop boundaries (S, ~1 week)

Make the markers Canvas already emits usable.

**Changes**

- `packages/preview-geometry/src/types.ts:5` — add `'prop'` to `CanvasBoundaryType`.
- `packages/preview-geometry/src/markers.ts:36-52` — add the `prop` prefix pair;
  `:263` — generalize `parseCanvasSlotIdentity()` to `parseQualifiedIdentity()` and use it
  for both `slot` and `prop`.
- `ui/src/features/layout/preview/PreviewGeometryContext.tsx:37` — index prop boundaries.
- `src/Twig/CanvasPropVisitor.php:104-171` — also wrap a `FilterExpression` chain rooted
  at a `NameExpression`, so `{{ text|default('') }}` is marked.

**New**

- Unit tests in `packages/preview-geometry` for prop marker discovery.
- A kernel test asserting the preview markup for `canvas_test_sdc:banner` contains
  `canvas-prop-start-{uuid}/heading` **and** `.../text`.

**Risks** — the visitor's HTML5-validity buffer check (`:155-157`) may reject positions
that are in fact safe; the filter-chain extension could wrap something that is not a prop
(e.g. `{{ attributes|without('class') }}`). Both are covered by asserting on real
fixtures. Low blast radius: markers are HTML comments and are preview-only.

### Phase 1 — MVP: inline editing of plain-text props (M, ~3 weeks)

**Scope**: props whose shape is `string` or `string_long`, whose prop source is `static`,
that resolve to exactly one prop boundary in the active iframe.

**Changes**

- `ui/src/features/ui/uiSlice.ts` — add `textEditSession` state, actions and selectors
  (alongside `selection` at `:26`/`:131`, not replacing it).
- `ui/src/features/layout/preview/Preview.tsx:144` — skip the POST effect while a session
  is active.
- `ui/src/services/preview.ts:195` (and `:79` for the POST path) — skip `setHtml()` while a session is active.
- `ui/src/features/layout/previewOverlay/ComponentOverlay.tsx:98` — double-click
  hit-tests the pointer against prop boundaries and starts a session; suppress drag and
  the name tag for the active component.
- `ui/src/features/layout/preview/IframeSwapper.tsx:57-86` — cancel any active session on
  swap.
- `ui/src/features/layout/layoutModelSlice.ts:711-804` — commit through the existing
  `updateExistingComponentValues` thunk (no new write path).

**New**

- `ui/src/features/layout/previewOverlay/inlineText/useTextEditSession.ts` — attach
  `contenteditable="plaintext-only"`, manage focus, composition guarding, heartbeat
  auto-save, commit and cancel.
- `ui/src/features/layout/previewOverlay/inlineText/InlineTextAffordance.tsx` — the
  "editing <Prop title>" label and focus ring in the overlay.
- A small stylesheet injected into the iframe document for the focus ring and caret only.
- `ui/tests/e2e/inline-text-editing.cy.js` — enter, type, blur, assert one auto-save and
  one undo entry; assert Escape reverts nothing and commits; assert Backspace does not
  delete the component.
- New cases in `tests/src/Playwright/tests/a11y.spec.ts`.

**Guards** — no session when the prop source is not `static`
(`layoutModelSlice.ts:149-178`), for `Fallback`/block components, for code components,
or when the prop has zero or multiple boundaries.

**Risks** — caret under CSS scale (spike first); overlay hit-testing regressions on
double-click; the `IframeSwapper` swap invalidating DOM references mid-session.

### Phase 2 — Formatted text inline (L, ~5 weeks)

Depends on `native-rich-text-widget` landing (its extracted editor host and
`text-editor-settings` endpoint). If it has not landed, Phase 2 must extract the host
itself, which is the same work done in the wrong place.

**Changes**

- `ui/src/components/form/components/drupal/DrupalFormattedTextArea.tsx:139-222` — consume
  the shared headless CKEditor host rather than owning the bootstrap (this is
  `native-rich-text-widget` design D3; do not fork it).
- The session hook gains a rich-text mode: mount the editor on the in-iframe element,
  read via `editor.getData()`, write `{value, format}` to `source` and raw markup to
  `resolved`.

**New**

- Iframe asset injection for CKEditor's `.ck-content` stylesheet, plus
  `.ck-editor__top { display: none }` to suppress CKEditor's own toolbar.
- `ui/src/features/layout/previewOverlay/inlineText/FloatingToolbar.tsx` — Radix toolbar
  portalled into `#canvasPreviewOverlay`, items generated from
  `editorSettings.toolbar.items`, commands dispatched via `editor.execute()`, state read
  from `editor.commands`, position from the selection rect × `editorViewPortScale`.
- E2E: bold/italic/link round-trip; paste of disallowed markup is stripped; the persisted
  `source` is `{value, format}` and the echo replaces `resolved` with processed output.
- A security test asserting a pasted `<script>` never reaches the persisted value.

**Spike required before committing the phase**: CKEditor 5 `ClassicEditor` mounted on an
element in a same-origin child document, inside a `transform: scale()` ancestor —
selection rects, balloon-free operation, and asset loading. Time-box it. If it fails,
the fallback is Option B (overlay editor) for rich props only, accepting the WYSIWYG
compromise for formatted text while plain text stays inline.

### Phase 3 — Block-based long-form authoring (XL, ~2-3 months)

**Hard dependency on `canvas-preview-performance`.** Without partial render and
optimistic structural operations, every `Enter` is a full-document server round trip and
the interaction is not viable. Do not start Phase 3 before that lands.

Should be its own OpenSpec change; this plan scopes it, it does not specify it.

**New capabilities**

- A prose component set (Paragraph, Heading, List, Quote, Image, Separator). Shipped
  where Q4 decides.
- Block key handling in the session: `Enter` at end → `insertNodes` a sibling paragraph +
  move the caret; `Backspace` at offset 0 → merge into the previous sibling (concatenate
  values, `deleteNode`); `ArrowUp`/`ArrowDown` at boundaries → move the caret across
  blocks; `Enter` in an empty block → outdent/exit.
- A `/` inserter popover at the caret, backed by the existing component library.
- A paste raw-handler splitting multi-block HTML into component instances (§4.3).
- An appender affordance at the end of an editable region.
- Where the blocks live: the page content region, or an `exposed-slots`-backed
  `component_tree` field on a node.

**Changes** — `layoutModelSlice.ts:295-502` structural actions become the block
primitives; `ComponentDropZone`/`SlotOverlay` gain a text-flow-aware presentation.

**Risks** — tree size (60 field items per article, ADR 0006); every structural op is
currently a full-document render; paste fidelity is a long tail; undo semantics across a
merge-two-blocks operation.

---

## 6. Risks and sizing summary

| Risk | Phase | Severity | Mitigation |
|---|---|---|---|
| Caret/selection broken under `transform: scale()` | 1 | High | Spike first; fall back to forcing scale 1 during a session |
| Full-document swap destroys the editing surface | 1 | High | Text-edit session suppresses `setHtml` (§3.2) |
| Prop marker coverage is partial | 0-1 | Medium | Extend the visitor for filter chains; degrade to the sidebar form, which is today's behavior |
| CKEditor 5 in a child document | 2 | High | Time-boxed spike; fall back to overlay editing for rich props only |
| Toolbar item → command mapping drifts from format config | 2 | Low | Generate from `editorSettings.toolbar.items`, never hardcode |
| Structural ops round-trip the whole document | 3 | Blocking | Hard-depend on `canvas-preview-performance` |
| Tree size for long articles | 3 | Medium | Measure before committing; ADR 0006 is the prior decision |
| Undo granularity regression | 1 | Medium | One session = one history entry; assert in E2E |
| Collaborative text conflicts | 2-3 | Medium | Per-component soft lock; explicitly not character-level merge |

Sizing assumes one engineer with Canvas context; Phase 2's spike can run in parallel with
Phase 1.

---

## 7. Open questions

These need a product or design decision before building. They are deliberately not
resolved here.

**Q1 — Entry gesture.** Single-click (Gutenberg-like, but ambiguous with component
selection, which single-click already owns), double-click, or an explicit mode? Does
entering a text session change what the right sidebar shows?

**Q2 — Relationship to the sidebar form.** Does the sidebar prop control stay live and
mirror the inline value keystroke-by-keystroke, go read-only, or disappear for the prop
being edited inline?

**Q3 — Non-static prop sources.** A prop bound to an entity field or an adapter is not a
literal in the layout. Is it silently not inline-editable, visibly locked with an
explanation, or does inline editing write through to the underlying entity field (which
changes data outside the layout and outside Canvas's auto-save)?

**Q4 — Does Canvas ship prose components?** Gutenberg-style authoring is meaningless
without Paragraph/Heading/List/Image blocks, and Canvas currently ships one hidden SDC.
Module, recipe, theme responsibility, or explicitly out of scope for core Canvas?

**Q5 — Formatting vocabulary for long-form.** Keep the deliberately locked
`canvas_html_block` tag set and put all structure in components (recommended in §3.6), or
expand the format to allow headings/images inside a single prop? These lead to very
different products.

**Q6 — Is a block always a component instance?** One field item per block means a 60-
paragraph article is 60 rows (ADR 0006). Is that acceptable, or is a lighter-weight prose
node needed? This should be answered with a measurement, not an opinion.

**Q7 — Collaboration semantics.** Per-component soft lock during a text session (simple,
visible, occasionally annoying) or concurrent editing with last-write-wins (no blocking,
occasional silent loss)? Character-level merge is out of scope either way.

**Q8 — Mobile and touch.** In scope at all? If yes, it likely forces Option B (overlay
editing) as a second path, which doubles the surface area.

**Q9 — Zoom.** Refuse to start a session at scale ≠ 1, auto-reset zoom on entry, or
support editing at any scale (contingent on the Phase 1 spike)?

**Q10 — Scope of surfaces.** Does inline editing apply to global regions and content
templates, or only to content? Regions are frozen snapshots under
`canvas-preview-performance`, which interacts badly with in-place editing.

**Q11 — Translation.** In a translation context, which value does an inline edit write?
ADRs 0012/0013 define symmetric translation and propagation on component-instance update;
inline editing must not become a way to bypass them.
