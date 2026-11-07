/**
 * @file
 * Builds CKEditor 5 configuration from Drupal's editor settings for a format.
 *
 * Shared by the sidebar's formatted text widget and inline text editing.
 */

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

type ConfigValue =
  RegExpConfig | FuncConfig | CKEditor5ConfigObject | any[] | primitive;
type primitive = string | number | boolean | null | undefined;

export interface CKEditor5ConfigObject {
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

export function processConfig(
  config: CKEditor5ConfigObject | null,
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
        return processConfig(item as CKEditor5ConfigObject);
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
          processed[key] = processConfig(value as CKEditor5ConfigObject);
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
export function selectPlugins(plugins: string[]): any[] {
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

/** Drupal's CKEditor 5 settings for a text format, from `drupalSettings`. */
export interface CKEditor5FormatSettings {
  toolbar: { items: string[] } | string[];
  plugins: string[];
  config: CKEditor5ConfigObject;
  language?: Record<string, any>;
}

/**
 * Returns the CKEditor 5 settings Drupal attached for a text format.
 *
 * Drupal attaches them with any form that renders the format's widget, such
 * as the component instance form.
 */
export function getFormatEditorSettings(
  format: string,
): CKEditor5FormatSettings | null {
  const settings = (window as any).drupalSettings?.editor?.formats?.[format];
  return settings?.editor === 'ckeditor5' && (window as any).CKEditor5
    ? settings.editorSettings
    : null;
}
