/**
 * @file
 * The component preview, rendered in an iframe nested inside the extension
 * iframe.
 *
 * This is the riskiest part of the whole idea, and the spike's main result.
 *
 * Sandbox nesting is fine: the extension page iframe carries
 * `sandbox="allow-scripts allow-same-origin allow-downloads"`, and a nested
 * iframe can never hold more privilege than its parent, so `allow-scripts
 * allow-same-origin` on the preview is exactly what core already uses. `srcDoc`
 * plus `blob:` module URLs work in a same-origin sandboxed document.
 *
 * What is NOT fine is the *content* of that document. It needs four values that
 * only exist inside the Canvas host window — see host.ts WALL(2..4).
 *
 * @see ui/src/features/code-editor/Preview.tsx
 * @see ui/src/components/extensions/ExtensionPage.tsx (sandbox attributes)
 */

import {
  importMapTags,
  previewDrupalSettings,
  previewRuntimeUrl,
} from './host.ts';

export interface PreviewInput {
  compiledJs: string;
  compiledCss: string;
  compiledGlobalCss: string;
  propValues: Record<string, unknown>;
  slotNames: string[];
}

export type PreviewResult =
  | { ok: true; srcDoc: string; revoke: () => void }
  | { ok: false; missing: string[] };

/**
 * Builds the preview document, or reports what it could not get.
 */
export function buildPreview(input: PreviewInput): PreviewResult {
  const runtime = previewRuntimeUrl();
  const settings = previewDrupalSettings();
  const importMap = importMapTags();

  const missing: string[] = [];
  if (!runtime) missing.push('preview runtime URL (canvasModulePath)');
  if (!settings) missing.push('drupalSettings');
  if (!importMap) missing.push('import map');
  if (missing.length > 0) {
    return { ok: false, missing };
  }

  // Compiled modules are handed over as blob: URLs, exactly as core does, so
  // no server round trip is needed to preview unsaved code.
  const compiledJsUrl = URL.createObjectURL(
    new Blob([input.compiledJs], { type: 'text/javascript' }),
  );
  // Core compiles slot examples separately; the spike has no slot editor, so
  // this is an empty module that still satisfies the runtime's required key.
  const compiledJsForSlotsUrl = URL.createObjectURL(
    new Blob(['export {};'], { type: 'text/javascript' }),
  );

  const data = JSON.stringify({
    compiledJsUrl,
    compiledJsForSlotsUrl,
    propValues: input.propValues,
    slotNames: input.slotNames,
    drupalSettings: settings,
  });

  const srcDoc = `<html>
  <head>
    ${importMap}
    <style>${input.compiledGlobalCss}</style>
    <style>${input.compiledCss}</style>
    <script id="canvas-code-editor-preview-data" type="application/json">${data}</script>
    <script type="module" src="${runtime}"></script>
  </head>
  <body><div id="canvas-code-editor-preview-root"></div></body>
</html>`;

  return {
    ok: true,
    srcDoc,
    revoke: () => {
      URL.revokeObjectURL(compiledJsUrl);
      URL.revokeObjectURL(compiledJsForSlotsUrl);
    },
  };
}
