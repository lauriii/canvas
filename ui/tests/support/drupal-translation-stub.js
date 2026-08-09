/**
 * @file
 * Provides `Drupal.t()` and `Drupal.formatPlural()` outside of Drupal.
 *
 * In the browser these come from `core/misc/drupal.js`, which the `canvas-ui`
 * asset library depends on. Vitest and Storybook render the same components
 * without Drupal, so they need an equivalent. This is a port of core's
 * implementation, kept behaviorally identical so that a test asserting on
 * translated output is asserting on what Drupal would produce.
 *
 * @see core/misc/drupal.js
 */

/**
 * Escapes a value for insertion as text, like Drupal.checkPlain().
 */
function checkPlain(str) {
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

/**
 * Substitutes placeholders, like Drupal.stringReplace().
 *
 * Longest key first, and replaced text is never searched again, so a value
 * containing another placeholder's name cannot be substituted into.
 */
function stringReplace(str, args, keys) {
  if (str.length === 0) {
    return str;
  }
  if (!Array.isArray(keys)) {
    keys = Object.keys(args || {});
    keys.sort((a, b) => a.length - b.length);
  }
  if (keys.length === 0) {
    return str;
  }
  const key = keys.pop();
  const fragments = str.split(key);
  if (keys.length) {
    for (let i = 0; i < fragments.length; i++) {
      fragments[i] = stringReplace(fragments[i], args, keys.slice(0));
    }
  }
  return fragments.join(args[key]);
}

/**
 * Replaces placeholders in a string, like Drupal.formatString().
 *
 * The prefix decides the treatment: `@` escapes, `!` passes through, and
 * anything else is escaped and wrapped by the `placeholder` theme function.
 * There is no `:` URL handling in JavaScript, unlike PHP's t().
 */
function formatString(str, args) {
  const processedArgs = {};
  Object.keys(args || {}).forEach((key) => {
    switch (key.charAt(0)) {
      case '@':
        processedArgs[key] = checkPlain(args[key]);
        break;

      case '!':
        processedArgs[key] = args[key];
        break;

      default:
        processedArgs[key] =
          `<em class="placeholder">${checkPlain(args[key])}</em>`;
        break;
    }
  });
  return stringReplace(str, processedArgs, null);
}

/**
 * Installs the translation functions on the global `Drupal` object.
 *
 * Translations are read from `window.drupalTranslations` on every call, the
 * same global Drupal's generated JavaScript translation file writes, so a test
 * can set it and get translated output back.
 */
export function installDrupalTranslationStub() {
  const t = (str, args, options) => {
    const context = options?.context || '';
    const translations = window.drupalTranslations;
    let result = translations?.strings?.[context]?.[str] ?? str;
    if (args) {
      result = formatString(result, args);
    }
    return result;
  };

  const formatPlural = (count, singular, plural, args, options) => {
    const allArgs = { ...args, '@count': count };
    const delimiter =
      window.drupalSettings?.pluralDelimiter ??
      // \x03, the delimiter core joins the singular and plural source with.
      // @see \Drupal\Component\Gettext\PoItem::DELIMITER
      String.fromCharCode(3);
    const translations = t(
      singular + delimiter + plural,
      allArgs,
      options,
    ).split(delimiter);
    let index = 0;
    const formula = window.drupalTranslations?.pluralFormula;
    if (formula) {
      index = count in formula ? formula[count] : formula.default;
    } else if (count !== 1) {
      index = 1;
    }
    return translations[index];
  };

  window.Drupal = { ...window.Drupal, t, formatPlural };
  globalThis.Drupal = window.Drupal;
}
