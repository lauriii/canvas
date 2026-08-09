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
 * Replaces placeholders in a string, like Drupal.formatString().
 */
function formatString(str, args) {
  return Object.keys(args).reduce(
    (result, key) => result.replaceAll(key, String(args[key])),
    str,
  );
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
