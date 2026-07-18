/**
 * Applies server-rendered component subtrees to the live preview iframe in
 * place, without the srcdoc reload and double-buffered swap.
 */
import {
  createExecutableFragment,
  extractMarkerRangeHtml,
  replaceMarkerRange,
} from '@/utils/markerRange';
import { previewPerfMark } from '@/utils/previewPerf';

export interface RenderAssets {
  css: string[];
  js: string[];
  importMap: {
    imports?: Record<string, string>;
    scopes?: Record<string, Record<string, string>>;
  };
  libraries: string[];
}

/**
 * The currently visible preview iframe's document, if any.
 */
export function getActivePreviewDocument(): Document | null {
  const iframe = document.querySelector<HTMLIFrameElement>(
    'iframe[data-canvas-preview][data-canvas-swap-active="true"]',
  );
  const doc = iframe?.contentDocument ?? null;
  return doc?.body ? doc : null;
}

/**
 * The (compressed) ajaxPageState libraries value of the preview document,
 * used as the initial "already loaded" set for asset deltas.
 */
export function getPreviewAjaxPageStateLibraries(doc: Document): string | null {
  const win = doc.defaultView as any;
  return win?.drupalSettings?.ajaxPageState?.libraries ?? null;
}

/**
 * Whether the preview document's import map already covers the given map.
 *
 * New entries cannot reliably be added to an already-processed import map, so
 * a missing entry means the render cannot be applied in place (the caller
 * falls back to one full reload; subsequent edits resume partial rendering).
 */
export function importMapIsSatisfied(
  doc: Document,
  importMap: RenderAssets['importMap'],
): boolean {
  const wanted = {
    imports: importMap?.imports ?? {},
    scopes: importMap?.scopes ?? {},
  };
  if (
    Object.keys(wanted.imports).length === 0 &&
    Object.keys(wanted.scopes).length === 0
  ) {
    return true;
  }
  const mapScript = doc.querySelector('script[type="importmap"]');
  if (!mapScript?.textContent) {
    return false;
  }
  let current: RenderAssets['importMap'];
  try {
    current = JSON.parse(mapScript.textContent);
  } catch {
    return false;
  }
  for (const key of Object.keys(wanted.imports)) {
    if (!(key in (current.imports ?? {}))) {
      return false;
    }
  }
  for (const [scope, entries] of Object.entries(wanted.scopes)) {
    const currentScope = current.scopes?.[scope] ?? {};
    for (const key of Object.keys(entries)) {
      if (!(key in currentScope)) {
        return false;
      }
    }
  }
  return true;
}

/**
 * Injects new CSS/JS assets into the preview document head.
 */
export function injectAssets(doc: Document, assets: RenderAssets): void {
  for (const markup of [...(assets.css ?? []), ...(assets.js ?? [])]) {
    doc.head.appendChild(createExecutableFragment(doc, markup));
  }
}

const attachBehaviors = (doc: Document, nodes: Node[]): void => {
  const win = doc.defaultView as any;
  if (!win?.Drupal?.attachBehaviors) {
    return;
  }
  for (const node of nodes) {
    if (node.nodeType === Node.ELEMENT_NODE) {
      try {
        win.Drupal.attachBehaviors(node as HTMLElement, win.drupalSettings);
      } catch {
        // A behavior throwing must not break the preview update.
      }
    }
  }
};

/**
 * Swaps one component's rendered markup into the live preview document.
 *
 * @returns true when applied; false when the caller must fall back to a full
 *   preview render.
 */
export function applyComponentHtml(
  doc: Document,
  uuid: string,
  html: string,
): boolean {
  const inserted = replaceMarkerRange(doc, uuid, html);
  if (!inserted) {
    return false;
  }
  attachBehaviors(doc, inserted);
  previewPerfMark('subtree-apply', { uuid });
  return true;
}

/**
 * Applies a full-page preview response as an in-place subtree swap of just
 * the edited component, extracting its marker range from the document string.
 *
 * @returns true when applied; false when the caller must fall back to the
 *   full srcdoc swap.
 */
export function applySubtreeFromFullPageHtml(
  uuid: string,
  fullPageHtml: string,
): boolean {
  const doc = getActivePreviewDocument();
  if (!doc) {
    return false;
  }
  const chunk = extractMarkerRangeHtml(fullPageHtml, uuid);
  if (!chunk) {
    return false;
  }
  return applyComponentHtml(doc, uuid, chunk);
}
