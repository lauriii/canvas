/**
 * @file
 * The save path: Canvas's config + auto-save HTTP API, from the extension.
 *
 * No wall here, and that is the point. These are ordinary same-origin,
 * session-authenticated endpoints, so `fetch(..., {credentials: 'same-origin'})`
 * plus `X-CSRF-Token` is all it takes — the same thing canvas_translate does
 * for its own routes.
 *
 * Note the auto-save routes are deliberately NOT `canvas_external_api: true`,
 * so they are cookie-only. That is fine for a local extension and impossible
 * for a remote one.
 *
 * @see canvas.routing.yml (canvas.api.config.get, canvas.api.config.auto-save.*)
 * @see src/Controller/ApiConfigAutoSaveControllers.php
 */

import { basePath } from './host.ts';

export interface CodeComponent {
  machineName: string;
  name: string;
  status: boolean;
  type?: 'react' | 'external';
  props: Record<string, unknown>;
  required: string[];
  slots: Record<string, unknown>;
  sourceCodeJs: string;
  sourceCodeCss: string;
  compiledJs: string;
  compiledCss: string;
  dataDependencies?: Record<string, unknown>;
  /**
   * Write-only. The server REQUIRES it on any PATCH that carries JS, and never
   * returns it: the client is expected to derive it from the source's imports.
   *
   * @see src/Entity/JavaScriptComponent.php ::updateFromClientSide()
   */
  importedJsComponents?: string[];
}

export interface AssetLibrary {
  id: string;
  label: string;
  css: { original: string; compiled: string };
  js: { original: string; compiled: string };
}

type AutoSaveHashes = Record<string, unknown>;

/**
 * Stable per-session id, for Canvas's optimistic-concurrency check.
 *
 * Lazy, and with a fallback: `crypto.randomUUID` is undefined outside a secure
 * context, so calling it at module scope would throw on a plain-HTTP dev site
 * and take the whole bundle down. Core uses the `uuid` package.
 */
let cachedClientInstanceId: string | null = null;

export function clientInstanceId(): string {
  cachedClientInstanceId ??=
    typeof crypto.randomUUID === 'function'
      ? crypto.randomUUID()
      : `spike-${Math.random().toString(36).slice(2)}`;
  return cachedClientInstanceId;
}

function url(path: string): string {
  return `${basePath()}${path}`;
}

let csrf: Promise<string> | null = null;

function csrfToken(): Promise<string> {
  csrf ??= fetch(url('session/token'), { credentials: 'same-origin' }).then(
    (response) => {
      if (!response.ok) {
        csrf = null;
        throw new Error(`CSRF token: ${response.status}`);
      }
      return response.text();
    },
  );
  return csrf;
}

async function get<T>(path: string): Promise<T> {
  const response = await fetch(url(path), { credentials: 'same-origin' });
  if (!response.ok) {
    throw new Error(`GET ${path}: ${response.status}`);
  }
  return (await response.json()) as T;
}

/**
 * Loads a code component, preferring its auto-save (draft) over the saved
 * entity, which is the same precedence useGetCodeEditorData applies.
 *
 * @see ui/src/features/code-editor/hooks/useGetCodeEditorData.ts
 */
export async function loadCodeComponent(id: string): Promise<{
  component: CodeComponent;
  autoSaves: AutoSaveHashes;
}> {
  const draft = await get<{ data?: CodeComponent; autoSaves: AutoSaveHashes }>(
    `canvas/api/v0/config/auto-save/js_component/${id}`,
  );
  if (draft.data) {
    return { component: draft.data, autoSaves: draft.autoSaves };
  }
  return {
    component: await get<CodeComponent>(
      `canvas/api/v0/config/js_component/${id}`,
    ),
    autoSaves: draft.autoSaves,
  };
}

export async function loadGlobalAssetLibrary(): Promise<{
  library: AssetLibrary;
  autoSaves: AutoSaveHashes;
}> {
  const draft = await get<{ data?: AssetLibrary; autoSaves: AutoSaveHashes }>(
    'canvas/api/v0/config/auto-save/asset_library/global',
  );
  if (draft.data) {
    return { library: draft.data, autoSaves: draft.autoSaves };
  }
  return {
    library: await get<AssetLibrary>(
      'canvas/api/v0/config/asset_library/global',
    ),
    autoSaves: draft.autoSaves,
  };
}

async function patchAutoSave(
  path: string,
  data: unknown,
  autoSaves: AutoSaveHashes,
): Promise<AutoSaveHashes> {
  const response = await fetch(url(path), {
    method: 'PATCH',
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': await csrfToken(),
    },
    body: JSON.stringify({ data, autoSaves, clientInstanceId: clientInstanceId() }),
  });
  if (response.status === 409) {
    // Someone else edited the same draft. Core's UI renders ConflictWarning.
    throw new Error('conflict');
  }
  if (!response.ok) {
    throw new Error(`PATCH ${path}: ${response.status}`);
  }
  const body = (await response.json()) as { autoSaves: AutoSaveHashes };
  return body.autoSaves;
}

export function saveCodeComponent(
  component: CodeComponent,
  autoSaves: AutoSaveHashes,
): Promise<AutoSaveHashes> {
  return patchAutoSave(
    `canvas/api/v0/config/auto-save/js_component/${component.machineName}`,
    component,
    autoSaves,
  );
}

// There is deliberately no saveGlobalAssetLibrary() here. Core PATCHes the
// global asset library on every compile, but it can only do so correctly because
// it owns the Tailwind class-name index that merges every component's utilities.
// That index is unpublished, so saving a globally-compiled stylesheet from an
// extension would drop other components' CSS.
// @see compile.ts::compileGlobalCssForPreview()
