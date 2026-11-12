/**
 * @file
 * Runs Drupal's CKEditor 5 on a rich text prop inside the preview iframe.
 *
 * The editor uses the text format's own editor settings, the same ones the
 * sidebar's formatted text widget uses, so what can be typed inline matches
 * the sidebar and the format. Only its editing engine runs in the preview:
 * Canvas renders the toolbar in the parent document.
 */
import {
  getFormatEditorSettings,
  processConfig,
  selectPlugins,
} from '@/utils/ckeditor5';

import type { Editor } from '@ckeditor/ckeditor5-core';
import type { CKEditor5FormatSettings } from '@/utils/ckeditor5';

// Plugins whose UI would open balloons positioned for the parent document,
// swapped for their editing-only parts. Canvas renders this UI itself.
const EDITING_ONLY_PLUGINS: Record<string, string> = {
  'link.Link': 'link.LinkEditing',
};

// CKEditor 5 packages known to work on an element in the preview document.
// Formats using others, such as media embeds or Linkit, open dialogs and
// pickers that assume the editor's own document, so they stay sidebar-only.
const SUPPORTED_PACKAGES = new Set([
  'alignment',
  'autoformat',
  'basicStyles',
  'blockQuote',
  'drupalEmphasis',
  'drupalHtmlEngine',
  'essentials',
  'heading',
  'horizontalLine',
  'htmlSupport',
  'indent',
  'link',
  'list',
  'paragraph',
  'pasteFromOffice',
  'removeFormat',
  'specialCharacters',
]);

export class UnsupportedFormatError extends Error {}

/**
 * Waits for Drupal to attach a format's CKEditor 5 settings.
 *
 * They arrive with the component instance form, which loads when the
 * component is selected, so a quick double-click can get here first.
 */
export async function waitForFormatEditorSettings(
  format: string,
  timeout = 4000,
): Promise<CKEditor5FormatSettings> {
  const started = Date.now();
  for (;;) {
    const settings = getFormatEditorSettings(format);
    if (settings) {
      return settings;
    }
    const formatInfo = (window as any).drupalSettings?.editor?.formats?.[
      format
    ];
    if (formatInfo && formatInfo.editor !== 'ckeditor5') {
      throw new UnsupportedFormatError(`"${format}" does not use CKEditor 5.`);
    }
    if (Date.now() - started > timeout) {
      throw new UnsupportedFormatError(
        `No CKEditor 5 settings for the "${format}" format.`,
      );
    }
    await new Promise((resolve) => setTimeout(resolve, 50));
  }
}

/** Toolbar items of the format's editor configuration, in order. */
export function getFormatToolbarItems(format: string): string[] {
  const toolbar = getFormatEditorSettings(format)?.toolbar;
  return (Array.isArray(toolbar) ? toolbar : toolbar?.items) ?? [];
}

/**
 * Creates the editor on an element, with the given HTML as its content.
 */
export async function createRichTextEditor(
  element: HTMLElement,
  format: string,
  initialData: string,
): Promise<Editor> {
  const settings = await waitForFormatEditorSettings(format);
  const unsupported = settings.plugins.filter(
    (name) => !SUPPORTED_PACKAGES.has(name.split('.')[0]),
  );
  if (unsupported.length) {
    throw new UnsupportedFormatError(
      `The "${format}" format uses plugins that only work in the sidebar: ${unsupported.join(', ')}.`,
    );
  }
  const plugins = selectPlugins(
    settings.plugins.map((name) => EDITING_ONLY_PLUGINS[name] ?? name),
  ).filter(Boolean);
  const config = processConfig(settings.config) ?? {};
  const { DecoupledEditor } = (window as any).CKEditor5.editorDecoupled;
  return DecoupledEditor.create(element, {
    ...config,
    plugins,
    // The toolbar is rendered by Canvas.
    toolbar: [],
    language: { ...(config.language ?? {}), ...(settings.language ?? {}) },
    initialData,
  });
}

/**
 * Destroys the editor and leaves its element showing what the author saw.
 *
 * CKEditor empties the element on destroy. Its editing view is a safe
 * rendering of the content: unsafe URLs and attributes are already
 * neutralized there, unlike in its data. So that view is put back, minus the
 * editor's own markup, until the server render replaces it.
 */
export async function destroyRichTextEditor(
  editor: Editor,
  element: HTMLElement,
): Promise<void> {
  const shown = Array.from(element.childNodes).map((node) =>
    node.cloneNode(true),
  );
  await editor.destroy();
  element.replaceChildren(...shown);
  // Editing UI rendered into the content, such as the fillers that keep empty
  // blocks reachable and the handles of widgets like horizontal lines.
  element
    .querySelectorAll(
      'br[data-cke-filler], .ck-widget__type-around, .ck-widget__selection-handle, .ck-widget__resizer, .ck-fake-selection-container',
    )
    .forEach((node) => node.remove());
  element.querySelectorAll('[class]').forEach((node) => {
    node.classList.remove(
      ...Array.from(node.classList).filter((name) => name.startsWith('ck')),
    );
    if (!node.classList.length) {
      node.removeAttribute('class');
    }
  });
}

/**
 * Returns the editor's value in the prop's stored shape.
 *
 * CKEditor always produces paragraphs; a single-line prop stores the inline
 * content of its one paragraph.
 */
export function getRichTextValue(editor: Editor, inline: boolean): string {
  const data = (editor as any).getData() as string;
  if (!inline) {
    return data;
  }
  const body = new DOMParser().parseFromString(data, 'text/html').body;
  const [only] = Array.from(body.children);
  return body.children.length === 1 && only.localName === 'p'
    ? only.innerHTML
    : data;
}
