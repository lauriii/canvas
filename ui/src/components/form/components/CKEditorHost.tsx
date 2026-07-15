import { useRef } from 'react';
import { CKEditor } from '@ckeditor/ckeditor5-react';

import type { Editor } from '@ckeditor/ckeditor5-core';
import type { FormatType } from '@drupal-canvas/types';

// Type definitions for CKEditor
interface RegExpConfig {
  regexp: {
    pattern: string;
  };
}

interface FuncConfig {
  func: {
    name: string;
    invoke?: boolean;
    args?: any[];
  };
}

interface EditorInstance extends Editor {
  getData: () => string;
}

type ConfigValue = RegExpConfig | FuncConfig | ConfigObject | any[] | primitive;
type primitive = string | number | boolean | null | undefined;

interface ConfigObject {
  [key: string]: ConfigValue;
}

// Below are several functions borrowed from core's ckeditor5.js.
// @todo use core versions after landing https://www.drupal.org/i/3521761
function buildRegexp(config: RegExpConfig): RegExp {
  const { pattern } = config.regexp;

  const main = pattern.match(/\/(.+)\/.*/)?.[1] || '';
  const options = pattern.match(/\/.+\/(.*)/)?.[1] || '';

  return new RegExp(main, options);
}

function findFunc(scope: any, name: string): Function | null {
  if (!scope) {
    return null;
  }
  const parts = name.includes('.') ? name.split('.') : [name];

  if (parts.length > 1) {
    return findFunc(scope[parts.shift()!], parts.join('.'));
  }
  return typeof scope[parts[0]] === 'function' ? scope[parts[0]] : null;
}

function buildFunc(config: FuncConfig): any {
  const { func } = config;
  // Assuming a global object.
  const fn = findFunc(window, func.name);
  if (typeof fn === 'function') {
    const result = func.invoke ? fn(...(func.args || [])) : fn;
    return result;
  }
  return null;
}

function processConfig(
  config: ConfigObject | null,
): Record<string, any> | null {
  /**
   * Processes an array in config recursively.
   *
   * @param config - An array that should be processed recursively.
   * @return An array that has been processed recursively.
   */
  function processArray(config: any[]): any[] {
    return config.map((item) => {
      if (typeof item === 'object' && item !== null) {
        return processConfig(item as ConfigObject);
      }

      return item;
    });
  }

  if (config === null) {
    return null;
  }

  return Object.entries(config).reduce<Record<string, any>>(
    (processed, [key, value]) => {
      if (typeof value === 'object' && value !== null) {
        // Check for null values.
        if (!value) {
          return processed;
        }
        if (Object.prototype.hasOwnProperty.call(value, 'func')) {
          processed[key] = buildFunc(value as FuncConfig);
        } else if (Object.prototype.hasOwnProperty.call(value, 'regexp')) {
          processed[key] = buildRegexp(value as RegExpConfig);
        } else if (Array.isArray(value)) {
          processed[key] = processArray(value);
        } else {
          processed[key] = processConfig(value as ConfigObject);
        }
      } else {
        processed[key] = value;
      }

      return processed;
    },
    {},
  );
}

/**
 * Select CKEditor 5 plugin classes to include.
 *
 * Found in the CKEditor 5 global JavaScript object as {package.Class}.
 *
 * @param plugins - List of package and Class name of plugins
 * @return List of JavaScript Classes to add in the extraPlugins property of config.
 */
function selectPlugins(plugins: string[]): any[] {
  return plugins.map((pluginDefinition) => {
    const [build, name] = pluginDefinition.split('.');
    // Define a more specific type for window.CKEditor5
    const ckEditor = (window as any).CKEditor5;
    if (ckEditor?.[build] && ckEditor?.[build]?.[name]) {
      return ckEditor[build][name];
    }

    console.warn(`Failed to load ${build} - ${name}`);
    return null;
  });
}
// This concludes the functions borrowed from core's ckeditor5.js.

export interface CKEditorHostProps {
  /**
   * The format's editor settings, exactly as the editor module computes them
   * (`drupalSettings.editor.formats[<format>].editorSettings`).
   */
  editorSettings: NonNullable<FormatType['editorSettings']>;
  /**
   * The markup loaded into the editor at mount. The editor is uncontrolled
   * after mount: to reset it (undo/redo, format switch), remount via `key`.
   */
  initialValue: string;
  onChange: (markup: string) => void;
  disabled?: boolean;
  /** Informs the editor's minimum height, like a textarea's rows. */
  minRows?: number;
}

/**
 * Mounts a CKEditor 5 classic editor from a text format's configured editor
 * settings, using the plugin classes Drupal's asset libraries expose on the
 * `window.CKEditor5` globals.
 *
 * Callers must ensure those globals are loaded first: the escape hatch loads
 * them through the form response's attachments, the native formatted text
 * widget through the text-editor-settings query.
 *
 * @see ui/src/components/form/components/drupal/DrupalFormattedTextArea.tsx
 * @see ui/src/components/form/widgets/widgets/FormattedTextWidgets.tsx
 */
const CKEditorHost = ({
  editorSettings,
  initialValue,
  onChange,
  disabled = false,
  minRows,
}: CKEditorHostProps) => {
  const editorRef = useRef<EditorInstance | null>(null);

  const { toolbar, plugins, config, language } = editorSettings;
  const extraPlugins = selectPlugins(plugins);
  const pluginConfig = processConfig(config) || {};
  const editorConfig = {
    extraPlugins,
    toolbar,
    ...pluginConfig,
    language: { ...(pluginConfig.language || {}), ...language },
    initialData: initialValue,
  };
  const { editorClassic } = (window as any).CKEditor5;
  const { ClassicEditor } = editorClassic;

  return (
    <CKEditor
      editor={ClassicEditor}
      config={editorConfig}
      disabled={disabled}
      onReady={(editor) => {
        editorRef.current = editor as EditorInstance;

        // If a minimum row count is given, let that inform the editor's
        // minimum height.
        if (minRows && Number(minRows)) {
          const editable = editor.ui.view.editable.element;
          if (editable) {
            const editorElement = editable.closest('.ck-editor');
            if (editorElement instanceof HTMLElement) {
              editorElement.style.setProperty(
                '--ck-min-height',
                `${Number(minRows) * 20}px`,
              );
            }
          }
        }
      }}
      onChange={() => {
        if (!editorRef.current) {
          return;
        }
        onChange(editorRef.current.getData());
      }}
    />
  );
};

export default CKEditorHost;
