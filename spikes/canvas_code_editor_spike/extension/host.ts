/**
 * @file
 * Everything the extension needs from the Canvas host, and how it gets it.
 *
 * THIS FILE IS THE SPIKE RESULT. Each `WALL(n)` below is a value the core code
 * editor reads straight out of its own `window`, that an extension document
 * does not have. The extension can only reach them through `window.parent`,
 * which is (a) an undocumented internal and (b) impossible for a
 * remotely-hosted extension, because `allow-same-origin` on the iframe only
 * grants same-origin access when the extension itself is served from the site.
 *
 * @see spikes/canvas_code_editor_spike/FINDINGS.md
 */

interface DrupalSettings {
  path?: { baseUrl?: string };
  canvas?: {
    canvasModulePath?: string;
    permissions?: Record<string, boolean>;
  };
  [key: string]: unknown;
}

/**
 * The parent Canvas UI window, when it is reachable.
 *
 * Returns null for a cross-origin (remotely hosted) extension: reading any
 * property of a cross-origin window throws. That is not an error to recover
 * from, it is the boundary the spike is measuring.
 */
function canvasWindow(): (Window & { drupalSettings?: DrupalSettings }) | null {
  try {
    const parent = window.parent as Window & {
      drupalSettings?: DrupalSettings;
    };
    // Touch a property so a cross-origin parent throws here and not later.
    void parent.document.title;
    return parent;
  } catch {
    return null;
  }
}

/** WALL(1): `drupalSettings.path.baseUrl`, for building API URLs. */
export function basePath(): string {
  return canvasWindow()?.drupalSettings?.path?.baseUrl ?? '/';
}

/**
 * WALL(2): `drupalSettings.canvas.canvasModulePath`.
 *
 * The preview iframe loads Canvas's own preview runtime from
 * `{canvasModulePath}/ui/dist/assets/code-editor-preview.js`. That script is
 * copied verbatim (not bundled) by Vite and does `import { h, render } from
 * 'preact'` — a bare specifier that only resolves because the preview document
 * inherits Canvas's import map. See WALL(3).
 *
 * @see ui/lib/code-editor-preview.js
 * @see ui/src/features/code-editor/Preview.tsx
 */
export function previewRuntimeUrl(): string | null {
  const modulePath = canvasWindow()?.drupalSettings?.canvas?.canvasModulePath;
  if (!modulePath) {
    return null;
  }
  return `${basePath()}${modulePath}/ui/dist/assets/code-editor-preview.js`;
}

/**
 * WALL(3): the Canvas import map.
 *
 * `Preview.tsx` copies it out of its own document with
 * `document.querySelectorAll('script[type="importmap"]')`. It is emitted by
 * `GlobalImports::getImportMap()` as a response attachment on the Canvas boot
 * route, so it exists only as a `<script>` tag in the host HTML — there is no
 * endpoint that returns it as data.
 *
 * Without it the preview cannot resolve `preact`, `react/jsx-runtime`, or any
 * `@/components/*` import, which is every non-trivial code component.
 *
 * @see src/GlobalImports.php
 * @see src/Render/ImportMapResponseAttachmentsProcessor.php
 * @see src/Controller/CanvasController.php (`import_maps`)
 */
export function importMapTags(): string {
  const host = canvasWindow();
  if (!host) {
    return '';
  }
  const tags = host.document.querySelectorAll('script[type="importmap"]');
  return Array.from(tags)
    .map((el) => {
      try {
        const map = JSON.parse(el.textContent || '{}');
        if (map.imports) {
          // The preview resolves sibling code components through their
          // auto-saved (draft) JS, exactly as Preview.tsx does.
          map.imports['@/components/'] =
            `${basePath()}canvas/api/v0/auto-saves/js/js_component/`;
        }
        return `<script type="importmap">${JSON.stringify(map)}</script>`;
      } catch {
        return el.outerHTML;
      }
    })
    .join('\n');
}

/**
 * WALL(4): `drupalSettings` minus the Canvas-only keys.
 *
 * `code-editor-preview.js` refuses to boot without a `drupalSettings` object,
 * because code components may read `drupalSettings.canvasData.v0`.
 *
 * @see ui/lib/code-editor-preview.js
 */
export function previewDrupalSettings(): DrupalSettings | null {
  const settings = canvasWindow()?.drupalSettings;
  if (!settings) {
    return null;
  }
  // eslint-disable-next-line @typescript-eslint/no-unused-vars
  const { canvas, canvasExtension, ...rest } = settings;
  return rest;
}

/**
 * WALL(5): whether the user may edit code components.
 *
 * Only ever exposed as `drupalSettings.canvas.permissions.codeComponents`. The
 * API routes do enforce it, so an extension can discover it by getting a 403 —
 * but not before rendering an editor it must then take away.
 *
 * @see src/Controller/CanvasController.php
 */
export function canEditCodeComponents(): boolean | null {
  const permissions = canvasWindow()?.drupalSettings?.canvas?.permissions;
  return permissions ? Boolean(permissions.codeComponents) : null;
}

/** Which walls this document could not get past. */
export function unreachable(): string[] {
  const missing: string[] = [];
  if (!canvasWindow()) {
    // Cross-origin: every wall at once.
    return ['host-window'];
  }
  if (!previewRuntimeUrl()) missing.push('canvasModulePath');
  if (!importMapTags()) missing.push('importMap');
  if (!previewDrupalSettings()) missing.push('drupalSettings');
  if (canEditCodeComponents() === null) missing.push('permissions');
  return missing;
}
