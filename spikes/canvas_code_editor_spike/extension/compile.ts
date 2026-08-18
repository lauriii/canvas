/**
 * @file
 * Client-side compilation, from the extension's own bundle.
 *
 * No wall here either: SWC and the Tailwind compiler are public npm packages
 * (`@swc/wasm-web`, `tailwindcss-in-browser`), so a module can ship its own
 * copies. Canvas does not compile anything server-side — the browser uploads
 * `compiledJs`/`compiledCss` — so the compiler has to live in the client, and
 * the client can be an extension.
 *
 * What is NOT reproduced here (and does not need core API, just porting):
 * - `rewriteAssetImportsForCanvas()` (asset manifest imports)
 * - the Tailwind class-name index stored as a comment in the global asset
 *   library's JS
 * - the separate compile pass for slot example markup
 *
 * @see ui/src/features/code-editor/hooks/useCompileJavaScript.ts
 * @see ui/src/features/code-editor/hooks/useCompileCss.ts
 */

import initSwc, { transformSync } from '@swc/wasm-web';
import {
  compileCss,
  compilePartialCss,
  extractClassNameCandidates,
  transformCss,
} from 'tailwindcss-in-browser';

// Copied from ui/src/features/code-editor/utils/tailwindCss.ts. Utilities that
// must not land inside `@layer utilities`.
const UNLAYERED_DISPLAY_UTILITIES = ['block', 'inline-block', 'inline', 'flex'];

let swcReady = false;

/**
 * Boots SWC from the wasm the extension ships itself.
 *
 * Core points this at `{canvasModulePath}/ui/dist/assets/wasm_bg.wasm`. An
 * extension does not need that: tsup copies the wasm next to the bundle.
 */
export async function initCompiler(): Promise<void> {
  if (swcReady) {
    return;
  }
  // Works because the bundle is ESM (see tsup.config.ts). In an IIFE build
  // `import.meta` is empty and this must fall back to `document.baseURI`.
  await initSwc(new URL('./wasm_bg.wasm', import.meta.url).href);
  swcReady = true;
}

export function compileJs(source: string): { code: string; error?: string } {
  if (!swcReady) {
    return { code: '', error: 'compiler not ready' };
  }
  try {
    const { code } = transformSync(source, {
      jsc: {
        parser: { syntax: 'typescript', tsx: true },
        target: 'es2015',
        transform: {
          react: {
            pragmaFrag: 'Fragment',
            throwIfNamespace: true,
            development: false,
            runtime: 'automatic',
          },
        },
      },
      module: { type: 'es6' },
    });
    return { code };
  } catch (error) {
    return { code: '// @error', error: String(error) };
  }
}

export async function compileComponentCss(
  componentCss: string,
  globalCss: string,
): Promise<string> {
  return transformCss(await compilePartialCss(componentCss, globalCss));
}

/**
 * Compiles global Tailwind CSS **for this preview only. Never save the result.**
 *
 * This is a wall the spike could not get past, and the reason is packaging.
 * Core does not compile the active component's class names in isolation: it
 * merges them into a per-component index stored as a comment in the global
 * asset library's JS (`upsertClassNameCandidatesInComment`), then compiles the
 * *merged* candidate set of every code component on the site
 * (`useSourceCode.ts:164-183`, `utils/classNameCandidates.ts`).
 *
 * That function is not published anywhere an extension can reach — it lives in
 * `ui/src/features/code-editor/utils/classNameCandidates.ts` inside a
 * `private: true` package. Reimplementing the index by hand and saving the
 * result would silently drop every other component's utilities from the site's
 * global stylesheet, so this spike compiles global CSS for the preview and
 * deliberately never PATCHes it.
 *
 * A module can only do this correctly once the index function is published.
 * @see the proposal's R1.
 */
export async function compileGlobalCssForPreview(
  sourceJs: string,
  globalCss: string,
): Promise<string> {
  const candidates = extractClassNameCandidates(sourceJs);
  const css = await compileCss(candidates, globalCss, {
    unlayeredUtilities: UNLAYERED_DISPLAY_UTILITIES,
  });
  return transformCss(css);
}

/**
 * The imports a code component declares, which the PATCH is required to carry.
 *
 * Core does this with `@babel/parser` over the full AST. A regex is enough to
 * prove the extension can compute it; the real module MUST port the AST walker,
 * because this regex is knowingly wrong in three ways the server cannot detect:
 * it misses side-effect imports (`import '@/components/x'`), it does not
 * exclude `@/lib/drupal-utils` the way core's walker does, and it produces no
 * `dataDependencies`. The server only sets keys it receives, so the result is
 * silent data loss rather than a rejected request.
 *
 * @see ui/src/features/code-editor/utils/ast-utils.ts
 * @see src/Entity/JavaScriptComponent.php ::updateFromClientSide()
 */
export function importedJsComponents(source: string): string[] {
  const found = new Set<string>();
  const pattern = /from\s+['"]@\/components\/([a-z0-9_-]+)['"]/g;
  for (const match of source.matchAll(pattern)) {
    found.add(match[1]);
  }
  return [...found];
}
