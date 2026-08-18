/**
 * @file
 * SPIKE entry point: a CodeMirror editing surface, a live preview, and a
 * debounced save into Canvas's auto-save store — all inside a page extension.
 *
 * Deliberately vanilla TypeScript, no React: canvas_translate already proves a
 * React SPA runs in a page extension, so re-proving that would add install
 * weight and prove nothing. What is unproven, and what this file exercises, is
 * the *coupling* to the Canvas host.
 *
 * Route: /canvas/app/canvas_code_editor_spike#/{machineName}
 */

import { basicSetup, EditorView } from 'codemirror';
import { css as cssLang } from '@codemirror/lang-css';
import { javascript } from '@codemirror/lang-javascript';
import { EditorState } from '@codemirror/state';

import {
  listCodeComponents,
  loadCodeComponent,
  loadGlobalAssetLibrary,
  saveCodeComponent,
} from './api.ts';
import {
  compileComponentCss,
  compileGlobalCssForPreview,
  compileJs,
  importedJsComponents,
  initCompiler,
} from './compile.ts';
import { unreachable } from './host.ts';
import { buildPreview } from './preview.ts';

import type { AssetLibrary, CodeComponent } from './api.ts';

const SAVE_DEBOUNCE_MS = 1000;

interface Editing {
  component: CodeComponent;
  library: AssetLibrary;
  componentAutoSaves: Record<string, unknown>;
}

const el = (id: string): HTMLElement => {
  const node = document.getElementById(id);
  if (!node) {
    throw new Error(`missing #${id}`);
  }
  return node;
};

function status(message: string): void {
  el('status').textContent = message;
}

/**
 * The machine name comes from the hash route Canvas forwards to the iframe.
 *
 * Canvas takes the sub-path from its OWN url path and hands it to the iframe as a
 * fragment: `/canvas/app/{id}/component/card` becomes `#/component/card`.
 * @see ui/src/components/extensions/ExtensionPage.tsx
 *
 * Both shapes are accepted, because the two callers disagree: a human opening the
 * extension uses `#/card`, while the component edit link the proposal specifies
 * points at `/component/{machineName}`.
 */
function requestedComponentId(): string | null {
  const id = window.location.hash
    .replace(/^#\/?/, '')
    .replace(/^component\//, '')
    .trim();
  return id === '' ? null : id;
}

/**
 * Populates the component picker, and navigates on change.
 *
 * Tells Canvas about the navigation with `canvas:navigate`, the one documented
 * outbound message a page extension has, so the parent address bar tracks the
 * extension's own route.
 * @see packages/extensions/README.md
 */
async function initNavigation(currentId: string | null): Promise<void> {
  const select = el('component-nav') as HTMLSelectElement;
  let components: Record<string, { name: string; type?: string }>;
  try {
    components = await listCodeComponents();
  } catch {
    select.innerHTML = '<option value="">(could not load list)</option>';
    return;
  }
  const entries = Object.entries(components)
    // External components have no editable source, so do not offer them.
    .filter(([, c]) => c.type !== 'external')
    .sort((a, b) => a[1].name.localeCompare(b[1].name));
  select.innerHTML = '';
  for (const [machineName, component] of entries) {
    const option = document.createElement('option');
    option.value = machineName;
    option.textContent = component.name;
    option.selected = machineName === currentId;
    select.append(option);
  }
  select.addEventListener('change', () => {
    const next = select.value;
    if (!next) {
      return;
    }
    window.parent.postMessage(
      { type: 'canvas:navigate', subPath: `component/${next}` },
      window.location.origin,
    );
    window.location.hash = `#/${next}`;
    // The editor is built once per component; reload rather than tear down.
    window.location.reload();
  });
}

function debounce<A extends unknown[]>(
  fn: (...args: A) => void,
  ms: number,
): (...args: A) => void {
  let timer: ReturnType<typeof setTimeout> | undefined;
  return (...args: A) => {
    if (timer !== undefined) {
      clearTimeout(timer);
    }
    timer = setTimeout(() => fn(...args), ms);
  };
}

async function boot(): Promise<void> {
  const walls = unreachable();
  if (walls.includes('host-window')) {
    status(
      'BLOCKED: the Canvas host window is cross-origin, so none of the values ' +
        'the preview needs are reachable. A remotely hosted extension cannot ' +
        'host this editor at all.',
    );
    return;
  }
  if (walls.length > 0) {
    status(`Reached the host window, but missing: ${walls.join(', ')}`);
  }

  const id = requestedComponentId();
  // Navigation is drawn regardless, so an editor opened with no component is
  // still usable — there is no component list in Canvas's panel to fall back to.
  void initNavigation(id);
  if (!id) {
    status('Choose a component.');
    return;
  }

  status(`Loading ${id}…`);
  await initCompiler();

  const [componentResult, libraryResult] = await Promise.all([
    loadCodeComponent(id),
    // Read only: the global asset library's CSS is the Tailwind configuration
    // the preview compiles against. The spike never writes it back.
    loadGlobalAssetLibrary(),
  ]);

  if (componentResult.component.type === 'external') {
    // The server rejects any source or compiled field on an external code
    // component, so there is nothing to edit here. Core's UI reaches the same
    // conclusion in CodeEditorContainer and redirects away.
    // @see src/Entity/JavaScriptComponent.php ::updateFromClientSide()
    status(`${id} is an external component and has no editable source.`);
    return;
  }

  const editing: Editing = {
    component: componentResult.component,
    library: libraryResult.library,
    componentAutoSaves: componentResult.autoSaves,
  };

  // Monotonic revision of the source. A compile captures it on entry and only
  // commits its artifacts if it is still the newest when it finishes; otherwise
  // an older, slower compile could publish stale compiled output over a newer
  // one, and the debounced save would persist it.
  let revision = 0;
  let compiledRevision = -1;

  const save = debounce(async () => {
    if (compiledRevision !== revision) {
      // The newest source has not compiled yet. The compile that settles it
      // schedules the next save, so drop this one rather than persist stale
      // artifacts.
      return;
    }
    status('Saving…');
    try {
      editing.componentAutoSaves = await saveCodeComponent(
        {
          ...editing.component,
          importedJsComponents: importedJsComponents(
            editing.component.sourceCodeJs,
          ),
        },
        editing.componentAutoSaves,
      );
      status('Saved');
    } catch (error) {
      status(`Save failed: ${String(error)}`);
    }
  }, SAVE_DEBOUNCE_MS);

  let revokePrevious: (() => void) | null = null;

  async function recompileAndPreview(): Promise<void> {
    const mine = ++revision;
    const sourceJs = editing.component.sourceCodeJs;
    const sourceCss = editing.component.sourceCodeCss;
    const configurationCss = editing.library.css.original;

    const js = compileJs(sourceJs);
    let componentCss: string;
    let globalCss: string;
    try {
      componentCss = await compileComponentCss(sourceCss, configurationCss);
      globalCss = await compileGlobalCssForPreview(sourceJs, configurationCss);
    } catch (error) {
      status(`CSS compile error: ${String(error)}`);
      return;
    }

    if (mine !== revision) {
      // Superseded while awaiting: a newer compile owns the artifacts now.
      return;
    }
    editing.component.compiledJs = js.code;
    editing.component.compiledCss = componentCss;
    compiledRevision = mine;

    const preview = buildPreview({
      compiledJs: js.code,
      compiledCss: componentCss,
      compiledGlobalCss: globalCss,
      // The spike does not port the props/slots editor; example values off the
      // stored prop schema are enough to render something.
      propValues: {},
      slotNames: [],
    });

    const frame = el('preview') as HTMLIFrameElement;
    if (!preview.ok) {
      frame.removeAttribute('srcdoc');
      status(`Preview blocked, missing: ${preview.missing.join(', ')}`);
      return;
    }
    revokePrevious?.();
    revokePrevious = preview.revoke;
    frame.srcdoc = preview.srcDoc;
    if (js.error) {
      status(`Compile error: ${js.error}`);
    }
  }

  const onChange = (apply: (value: string) => void) =>
    EditorView.updateListener.of((update) => {
      if (!update.docChanged) {
        return;
      }
      apply(update.state.doc.toString());
      void recompileAndPreview();
      save();
    });

  new EditorView({
    state: EditorState.create({
      doc: editing.component.sourceCodeJs,
      extensions: [
        basicSetup,
        javascript({ jsx: true, typescript: true }),
        onChange((value) => {
          editing.component.sourceCodeJs = value;
        }),
      ],
    }),
    parent: el('editor-js'),
  });

  new EditorView({
    state: EditorState.create({
      doc: editing.component.sourceCodeCss,
      extensions: [
        basicSetup,
        cssLang(),
        onChange((value) => {
          editing.component.sourceCodeCss = value;
        }),
      ],
    }),
    parent: el('editor-css'),
  });

  // Neutral status first: recompileAndPreview() may report a blocked preview or
  // a compile error, and that message must not be overwritten straight after.
  status(`Editing ${editing.component.name}`);
  await recompileAndPreview();
}

void boot().catch((error: unknown) => {
  status(`Failed: ${String(error)}`);
});
