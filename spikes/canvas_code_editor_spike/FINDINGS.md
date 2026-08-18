# SPIKE: can a Canvas page extension host the code editor?

**Verdict: yes for the editing surface, compilation and the save path; no for
the preview without new core API. And a remotely hosted extension cannot host
it at all.**

**Now verified at runtime on a real Drupal site**, not just reasoned from code.
See "Verified on a live site" below. Two claims in the first version were
falsified by that verification: WALL(5) was never a wall, and CSS compilation
does *not* work with the build recipe this spike started with.

Status: throwaway. Nothing here ships. Delete once the API design is agreed.

## What was built

A real Drupal module (`canvas_code_editor_spike`) declaring a `type: page`
Canvas extension, plus a real extension app in `extension/`:

| File | What it proves |
|---|---|
| `extension/index.ts` | CodeMirror 6 (JS + CSS surfaces) driving a debounced save and a live preview, inside the extension document |
| `extension/api.ts` | Load + auto-save a `js_component` and the global `asset_library` over Canvas's HTTP API with cookies and `X-CSRF-Token` |
| `extension/compile.ts` | SWC (JSX/TS → JS) and Tailwind/Lightning CSS compilation from the extension's **own** bundle |
| `extension/preview.ts` | The component preview as an iframe nested inside the extension iframe |
| `extension/host.ts` | **The result.** Every value the editor needs that the extension cannot get without reaching into Canvas internals |

## How it was verified

```
cd spikes/canvas_code_editor_spike/extension
npm install && npm run type-check && npm run build
```

Both pass. Output:

```
dist/index.global.js                          1.00 MB
dist/lightningcss_node-1.30.1-…​.wasm         13.61 MB
dist/wasm_bg.wasm                            19.00 MB
```

## Verified on a live site

A DDEV site was created (golden clone, Canvas installed, this module enabled) and
the extension was driven in a real browser. Screenshot: `verified.png`.

**The spike works end to end.** In the real page-extension iframe at
`/canvas/app/canvas_code_editor_spike/component/card`:

| Observed | Result |
|---|---|
| Extension discovered and served | `type=page`, url resolved to the module path |
| Extension document boots | status `Editing Card` |
| CodeMirror surfaces | **2** mounted (JS + CSS) |
| SWC compile | works, from the extension's own wasm |
| Tailwind / Lightning CSS compile | works, after the build fix below |
| Preview document assembled | 10,390 chars, contains the import map and a `blob:` module URL |
| Preview renders | the preview root has rendered content |
| Component navigation | 12 components listed; switching updates the parent URL via `canvas:navigate` to `/component/footer` and re-opens the editor |
| Auto-save PATCH | `{data, autoSaves, clientInstanceId}` → 200, new hash returned |
| Conflict detection | same `clientInstanceId` → 200 (hash check skipped by design); different one + stale hash → **409** |
| Sandbox nesting | the extension document can read `window.parent.document` — `allow-same-origin` holds through the nesting, as reasoned |

Also confirmed on the same site, about Canvas rather than the spike:

- **The import map already ships to clients as rendered markup.** `GET /canvas/api/v0/config/js_component/card` returns `js_header` (3,485 chars) containing `<script type="importmap">` with `preact` mapped to a cache-busted module URL. See the WALL(3) correction.
- **The draft response lacks it.** The same component's auto-save GET returns source and compiled fields but **no** `default_markup`, `css`, `js_header` or `js_footer`.
- **WALL(5) confirmed withdrawn, and better than described**: a user with `edit canvas_page` but not `administer code components` gets **403 on `/canvas/app/canvas_code_editor_spike` before the iframe is created**, while core's own `/canvas/code-editor/component/card` returns **200** and refuses client-side. The extension is *better* gated than core's editor.
- **A route outside `/canvas/` escapes the path processor**: `/canvas-code-editor/component/card` → 404, while `/canvas/code-editor/component/card` → 200. That is what makes the proposal's redirect route possible.
- **Publishing a code component alone fails with 424** `GlobalAssetNotPublished`, pointing at `asset_library:global`.
- **Denied links contribute cacheability only**: `card`'s `links` is `[]` because it is in use, so delete access is denied and the link is omitted rather than shown.
- **`globalAssets.jsHeader` and `jsFooter` are empty** on this site; only `css` is populated. So the import map and `globalAssets` are two separate sources, and an implementation must not assume the map arrives via `jsHeader`.

**Still not verified:** performance under real editing load, behavior with a remote extension (impossible by construction), and anything about the migration itself.

## The build recipe: Vite, not tsup

The most expensive thing this verification found. The spike began with the
`tsup` + IIFE recipe the `canvas-extension` skill prescribes and which
`canvas_translate` ships. **That cannot build this extension at all**, because
`tailwindcss-in-browser` imports its Lightning CSS wasm with a Vite-style `?url`
suffix:

- `format: 'iife'` → `Error: Dynamic require of "./lightningcss_node-1.30.1-<hash>.wasm?url" is not supported`, thrown at module scope, so nothing boots. The bundle built cleanly and `tsc` passed; only a browser revealed it.
- `format: 'esm'` → the `?url` import survives as a static import of a non-JS asset, and the browser refuses the whole module: *"Failed to fetch dynamically imported module"*.

Fixed by building with **Vite**, which is what Canvas's own `ui/` uses and which
resolves `?url` natively. Two further details, both found only at runtime:

- Vite **lib mode** does not emit the `?url` asset, so the build must copy
  `lightningcss_node-1.30.1.wasm` itself — under **exactly** that versioned name,
  because that is the URL left in the bundle. Canvas's `ui/dist/assets/` contains
  the same filename.
- The package *also* resolves `new URL("lightningcss_node.wasm", import.meta.url)`
  at runtime, so the un-versioned name has to be shipped too. Get either wrong and
  the browser fetches Drupal's 404 HTML and `WebAssembly.instantiate()` fails with
  `expected magic word 00 61 73 6d, found 3c 21 44 4f` — `<!DO`.

**Consequence for the proposal:** an extension that reuses Canvas's CSS compiler
has to reproduce Canvas's Vite asset handling and ship 33 MB of wasm under two
names dictated by a third-party package's internals. That is the strongest
argument for Canvas exposing its compiler asset URLs, so the module ships neither
file.

## Navigation

The screenshot shows the other thing a live site makes obvious: **the extension
page has only a back arrow.** Canvas's code component list lives in the left
panel (`ui/src/components/sidePanel/Code.tsx`) and ADR 0009 excludes extension UI
there, so an extension-hosted editor has to carry its own navigation. The spike
now does — a component picker that lists the site's code components, excludes
`external` ones, and reports each move to Canvas with `canvas:navigate`. This is
Q5 loss 1 in the proposal, seen rather than predicted.

## What is NOT a wall

1. **CodeMirror in an extension.** Ordinary bundling. Nothing Canvas-specific.
2. **Compilation.** `@swc/wasm-web` and `tailwindcss-in-browser` are public npm
   packages, and Canvas compiles **nothing** server-side — the client uploads
   `compiledJs`/`compiledCss`. So the compiler belongs to whoever owns the
   client, and that can be a module.
3. **The save path, for the component itself.**
   `/canvas/api/v0/config/auto-save/js_component/{id}` is an ordinary
   same-origin session-authenticated route. `fetch(credentials: 'same-origin')` +
   `X-CSRF-Token` is enough, the same way `canvas_translate` talks to its own
   routes, and the request shape matches
   `ApiConfigAutoSaveControllers::patch()` exactly. Canvas's optimistic
   concurrency (`autoSaves` hashes + `clientInstanceId`, 409 on conflict) is
   already expressed in the wire format, so an extension participates for free.
   The *global asset library* half of the save path is a different story — see
   WALL(6).
4. **Sandbox nesting.** The page-extension iframe carries `allow-scripts
   allow-same-origin allow-downloads`; a nested iframe can never exceed its
   parent's sandbox, and `allow-scripts allow-same-origin` (what core's preview
   uses) is within that. `srcDoc` + `blob:` module URLs work in a same-origin
   sandboxed document.

## The walls

Walls 1 to 5 are recorded as `WALL(n)` in `extension/host.ts`; wall 6 is recorded in `extension/compile.ts`, because it is a packaging problem rather than a missing host value.

### WALL(0) — a remote extension is impossible, not merely degraded

`host.ts::canvasWindow()` returns `null` for a cross-origin host, and then
every other wall fires at once. The auto-save routes are deliberately **not**
`canvas_external_api: true`, so they are cookie-only: no OAuth token reaches
them. **The code editor module must ship a locally served extension.** That is
consistent with ADR 0009 (which allows local `url:` values) but rules out the
remote-hosting benefit that ADR names as a motivation.

### WALL(1) — `drupalSettings.path.baseUrl`

Needed to build any API URL. `canvas_translate` hits this too and hardcodes
`'/'` (`extension/api.ts::basePath()`), which is why page extensions do not
work on subdirectory installs today.

### WALL(2) — `drupalSettings.canvas.canvasModulePath`

The preview loads Canvas's preview runtime from
`{canvasModulePath}/ui/dist/assets/code-editor-preview.js`.

### WALL(3) — the Canvas import map · **the load-bearing one**

`ui/src/features/code-editor/Preview.tsx:92` copies the import map out of its
own document with `document.querySelectorAll('script[type="importmap"]')`. That
map is produced by `src/GlobalImports.php::getImportMap()` and emitted as a
**response attachment** on the Canvas boot route
(`src/Controller/CanvasController.php:308` →
`src/Render/ImportMapResponseAttachmentsProcessor.php`).

**CORRECTION.** This wall was overstated, and the correction changed the
proposal. It is *not* true that no endpoint returns the import map as data:
`ClientSideRepresentation::renderPreviewIfAny()` (`src/ClientSideRepresentation.php:46-74`)
already returns a rendered component preview as JSON, and its `js_header` field
is `renderInIsolation($import_map) . renderJsHeaderAssets($assets)` (`:58,68`) —
so every component preview payload already carries the map, and
`ui/src/components/ComponentPreview.tsx:67-100` already builds an iframe from it.
What is actually true is narrower: no endpoint returns the map **standalone**,
and the one component the editor is editing is the one whose *draft* preview
Canvas does not render — `ApiConfigAutoSaveControllers::get()` skips
`renderPreviewIfAny()`. The fix is therefore to have Canvas serve the draft
preview, not to export the map. See the proposal's R2.

Without it the preview document cannot resolve `preact`, `react/jsx-runtime`,
`@/components/*`, or any site-provided import — i.e. every non-trivial code
component. `ui/lib/code-editor-preview.js:22` is copied verbatim by Vite rather
than bundled, precisely so that its `import { h, render } from 'preact'`
resolves through that map.

The extension **cannot** simply ship its own map: sibling code components
imported via `@/components/*` are compiled against Canvas's shipped Preact, so
a second, divergent map would give two Preact instances.

### WALL(4) — `drupalSettings` for the preview document

`ui/lib/code-editor-preview.js:69-73` hard-fails without a `drupalSettings`
object, because components may read `drupalSettings.canvasData.v0`.

### WALL(6) — the Tailwind class-name index is unpublished, and a naive substitute is destructive

Found by a second review, of the spike's code rather than this document.

Core does not compile the active component's Tailwind class names in isolation.
It merges them into a per-component index stored as a comment in the global asset
library's JS (`upsertClassNameCandidatesInComment`), then compiles the **merged**
candidate set of every code component on the site
(`ui/src/features/code-editor/hooks/useSourceCode.ts:164-183`,
`ui/src/features/code-editor/utils/classNameCandidates.ts`), and PATCHes the
result over `asset_library.global`.

That index function lives inside a `private: true` package with no `exports`, so
an extension cannot reach it. The spike's first version compiled only the active
component's candidates and saved the result — which on a real site would have
silently dropped every other component's utilities from the global stylesheet.

The spike now compiles global CSS **for the preview only** and never writes it
back (`compile.ts::compileGlobalCssForPreview()`, and there is deliberately no
`saveGlobalAssetLibrary()` in `api.ts`). That is honest but incomplete: a real
module cannot ship without writing global CSS, so it cannot ship until the index
function is published. This is the strongest single argument for the proposal's
shared authoring package.

### WALL(5) — WITHDRAWN. Not a wall.

The first version of this document claimed the extension could not know whether
the user may edit code components, because it is only exposed as
`drupalSettings.canvas.permissions.codeComponents`. That is wrong, and the spike
had already solved it without noticing: a page extension declares
`permissions:` in its `*.canvas_extension.yml`, and
`src/Access/ExtensionPageAccessCheck.php:33-48` requires every one of them on
`/canvas/app/{extension_id}` before the iframe is created.
`canvas_code_editor_spike.canvas_extension.yml` declares `administer code
components`, so an unauthorized user gets a 403 on the route and the editor is
never drawn. `src/Controller/CanvasController.php:158-166` additionally filters
`pageExtensions` by the same permissions.

Kept in place, not renumbered, so the numbering in `host.ts` stays stable.

## Two costs the spike quantified

- **33 MB of wasm, duplicated.** The extension must ship its own SWC (19 MB)
  and Lightning CSS (13.6 MB) wasm. Canvas depends on the same two packages
  (`ui/package.json:55,91`) and already serves the SWC one from a known path
  (`useCompileJavaScript.ts:72` loads `ui/dist/assets/wasm_bg.wasm`), but that
  path is a build detail, not an API. A core API that names the compiler asset
  URLs would remove the duplication.
- **`import.meta` is empty in an IIFE bundle** (`compile.ts:48`), so the wasm
  URL must be resolved off `document.baseURI`. Core hits the same thing and
  passes an explicit URL. Minor, but it bites every extension that ships wasm.

## What the spike gets wrong

Found by an adversarial review of this document against the spike's own code.
None overturns the verdict, but the verdict is now narrower than it was: the
*transport* is proven, the *payload* is not.

- **WALL(5) was never a wall.** See above. The spike declared the permission in
  its own YAML and then wrote code to work around not having it.
- **`importedJsComponents` derivation is knowingly wrong** (`compile.ts:106`).
  The regex requires `from`, so side-effect imports are missed; it does not
  exclude `@/lib/drupal-utils` the way core's AST walker does; and it computes no
  `dataDependencies`. The server only writes keys it receives, so this is silent
  data loss, not a rejected request. The real module must port the AST walker.
- **`crypto.randomUUID()` was called at module scope** (`api.ts`), which is
  undefined outside a secure context and would have taken the whole IIFE bundle
  down on a plain-HTTP dev site. Now lazy, with a fallback.
- **External code components were not handled.** `type: 'external'` components
  carry no source and the server rejects any code field on them
  (`src/Entity/JavaScriptComponent.php:409-437`), so the spike would have sent a
  PATCH that 422s. Now refused up front, as core's `CodeEditorContainer` does.
- **Global CSS was compiled from one component and saved.** See WALL(6). This was
  the worst defect in the spike: on a real site it would have destroyed other
  components' utility CSS. Now preview-only, with no write path at all.
- **Compilation raced the save.** Each keystroke started an un-awaited
  `recompileAndPreview()` that mutated shared state across two awaits, while an
  independent debounce persisted whatever was there — so a slow older compile
  could publish stale artifacts over a newer source. Now guarded by a revision
  counter, and the save is skipped until the newest source has compiled. CSS
  compiler rejections were also unhandled; they are now reported.
- **The boot status overwrote real errors.** A "preview blocked" or "compile
  error" message was immediately replaced by the neutral editing status. The
  neutral status is now set first.
- **Both preview sandboxes carry live `@todo`s to drop `allow-same-origin`** —
  `ui/src/features/code-editor/Preview.tsx:371` (tracked as
  <https://www.drupal.org/i/3527515>) and
  `ui/src/components/extensions/ExtensionPage.tsx:94`. Every conclusion in the
  "not a wall" list above depends on `allow-same-origin` surviving: without it
  the preview document gets an opaque origin and `import(blobUrl)` fails,
  because blob URL fetches are same-origin only. The authoring-environment
  endpoint does not fix that case.

## What this means for the proposal

The preview is most of the problem, and it is five walls: four host values plus the unpublished class-name index. Two of them
(2, 3) exist only because the preview runtime and its import map are private to
the Canvas UI build. One core addition — **an endpoint that returns the
code-component authoring environment (import map, preview runtime URL,
`drupalSettings` subset, compiler asset URLs) as JSON** — collapses walls 1
through 4 into one documented API call. Wall 5 needs nothing: the extension
system already enforces permissions on the route. Wall 6 is not an endpoint at
all — it is a packaging problem, and the shared authoring package fixes it.

Wall 0 is not solvable and should be stated as a constraint, not a bug: the
code editor extension is a local extension.
