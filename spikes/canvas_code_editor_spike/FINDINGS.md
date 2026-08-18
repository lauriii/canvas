# SPIKE: can a Canvas page extension host the code editor?

**Verdict: yes for the editing surface, compilation and the save path; no for
the preview without new core API. And a remotely hosted extension cannot host
it at all.**

Narrower than it first read: the *transport* is proven, the *payload* is not.
See "What the spike gets wrong".

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

**Not verified: anything at runtime.** There is no Drupal site, no database and
no browser in this environment. Every HTTP call, the nested-iframe sandbox
behavior and the wasm boot are reasoned from the code and the platform specs,
not observed. The runtime half needs a site-equipped follow-up.

## What is NOT a wall

1. **CodeMirror in an extension.** Ordinary bundling. Nothing Canvas-specific.
2. **Compilation.** `@swc/wasm-web` and `tailwindcss-in-browser` are public npm
   packages, and Canvas compiles **nothing** server-side — the client uploads
   `compiledJs`/`compiledCss`. So the compiler belongs to whoever owns the
   client, and that can be a module.
3. **The save path.** `/canvas/api/v0/config/auto-save/js_component/{id}` and
   `.../asset_library/global` are ordinary same-origin session-authenticated
   routes. `fetch(credentials: 'same-origin')` + `X-CSRF-Token` is enough, the
   same way `canvas_translate` talks to its own routes. Canvas's optimistic
   concurrency (`autoSaves` hashes + `clientInstanceId`, 409 on conflict) is
   already expressed in the wire format, so an extension participates in it for
   free.
4. **Sandbox nesting.** The page-extension iframe carries `allow-scripts
   allow-same-origin allow-downloads`; a nested iframe can never exceed its
   parent's sandbox, and `allow-scripts allow-same-origin` (what core's preview
   uses) is within that. `srcDoc` + `blob:` module URLs work in a same-origin
   sandboxed document.

## The walls

All five are recorded as `WALL(n)` in `extension/host.ts`.

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
`src/Render/ImportMapResponseAttachmentsProcessor.php`). It exists only as a
`<script>` tag in the host HTML. **There is no endpoint that returns it as
data.**

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
- **Both preview sandboxes carry live `@todo`s to drop `allow-same-origin`** —
  `ui/src/features/code-editor/Preview.tsx:371` (tracked as
  <https://www.drupal.org/i/3527515>) and
  `ui/src/components/extensions/ExtensionPage.tsx:94`. Every conclusion in the
  "not a wall" list above depends on `allow-same-origin` surviving: without it
  the preview document gets an opaque origin and `import(blobUrl)` fails,
  because blob URL fetches are same-origin only. The authoring-environment
  endpoint does not fix that case.

## What this means for the proposal

The preview is the whole problem, and it is four walls, not five. Two of them
(2, 3) exist only because the preview runtime and its import map are private to
the Canvas UI build. One core addition — **an endpoint that returns the
code-component authoring environment (import map, preview runtime URL,
`drupalSettings` subset, compiler asset URLs) as JSON** — collapses walls 1
through 4 into one documented API call. Wall 5 needs nothing: the extension
system already enforces permissions on the route.

Wall 0 is not solvable and should be stated as a constraint, not a bug: the
code editor extension is a local extension.
