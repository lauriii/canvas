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

import { EditorState } from '@codemirror/state';
import { css as cssLang } from '@codemirror/lang-css';
import { javascript } from '@codemirror/lang-javascript';
import { basicSetup, EditorView } from 'codemirror';

import {
  loadCodeComponent,
  loadGlobalAssetLibrary,
  saveCodeComponent,
  saveGlobalAssetLibrary,
  type AssetLibrary,
  type CodeComponent,
} from './api.ts';
import {
  compileComponentCss,
  compileGlobalCss,
  compileJs,
  importedJsComponents,
  initCompiler,
} from './compile.ts';
import { unreachable } from './host.ts';
import { buildPreview } from './preview.ts';

const SAVE_DEBOUNCE_MS = 1000;

interface Editing {
  component: CodeComponent;
  library: AssetLibrary;
  componentAutoSaves: Record<string, unknown>;
  libraryAutoSaves: Record<string, unknown>;
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

/** The machine name comes from the hash route Canvas forwards to the iframe. */
function requestedComponentId(): string | null {
  const id = window.location.hash.replace(/^#\/?/, '').trim();
  return id === '' ? null : id;
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
  if (!id) {
    status('Open this with a component id: #/{machineName}');
    return;
  }

  status(`Loading ${id}…`);
  await initCompiler();

  const [componentResult, libraryResult] = await Promise.all([
    loadCodeComponent(id),
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
    libraryAutoSaves: libraryResult.autoSaves,
  };

  const save = debounce(async () => {
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
      editing.libraryAutoSaves = await saveGlobalAssetLibrary(
        editing.library,
        editing.libraryAutoSaves,
      );
      status('Saved');
    } catch (error) {
      status(`Save failed: ${String(error)}`);
    }
  }, SAVE_DEBOUNCE_MS);

  let revokePrevious: (() => void) | null = null;

  async function recompileAndPreview(): Promise<void> {
    const js = compileJs(editing.component.sourceCodeJs);
    editing.component.compiledJs = js.code;
    editing.component.compiledCss = await compileComponentCss(
      editing.component.sourceCodeCss,
      editing.library.css.original,
    );
    editing.library.css.compiled = await compileGlobalCss(
      editing.component.sourceCodeJs,
      editing.library.css.original,
    );

    const preview = buildPreview({
      compiledJs: editing.component.compiledJs,
      compiledCss: editing.component.compiledCss,
      compiledGlobalCss: editing.library.css.compiled,
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

  await recompileAndPreview();
  status(`Editing ${editing.component.name}`);
}

void boot().catch((error: unknown) => {
  status(`Failed: ${String(error)}`);
});
