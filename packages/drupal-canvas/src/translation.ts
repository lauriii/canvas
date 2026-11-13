/**
 * @file
 * Framework-independent string translation with the same signatures as
 * `Drupal.t()` and `Drupal.formatPlural()`. `useTranslation()` binds them to
 * the context's translations.
 */

import type { Translations } from './context-types.js';

/**
 * Placeholder replacements, keyed by `@` placeholders, e.g.
 * `{ '@name': name }`. Drupal's other placeholder prefixes (`%`, `:` and `!`)
 * are not supported: such keys are ignored, so their placeholders stay in the
 * text.
 */
export type TranslationArgs = Record<`@${string}`, string | number>;

export interface TranslationOptions {
  /** The string context, to tell apart identical source strings. */
  context?: string;
}

/**
 * Translates a string, with the same arguments as `Drupal.t()`.
 *
 * Unlike `Drupal.t()`, which returns HTML, the result is text: `@`
 * placeholders insert their values as is, and HTML in the source string or its
 * translation is not interpreted. React escapes the text when it renders it.
 */
export type TranslateFunction = (
  str: string,
  args?: TranslationArgs,
  options?: TranslationOptions,
) => string;

/**
 * Formats a singular or plural string for a count, with the same arguments as
 * `Drupal.formatPlural()`. `@count` is replaced with the count. The result is
 * text, not HTML, as with `TranslateFunction`.
 */
export type FormatPluralFunction = (
  count: number,
  singular: string,
  plural: string,
  args?: TranslationArgs,
  options?: TranslationOptions,
) => string;

/** Joins plural forms, like Drupal's `PoItem::DELIMITER`. */
const PLURAL_DELIMITER = '\x03';

const escapeRegExp = (value: string) =>
  value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

const lookUp = (
  translations: Translations | null | undefined,
  str: string,
  options?: TranslationOptions,
): string | undefined =>
  translations?.strings?.[options?.context ?? '']?.[str] || undefined;

export function translate(
  translations: Translations | null | undefined,
  str: string,
  args?: TranslationArgs,
  options?: TranslationOptions,
): string {
  return replacePlaceholders(lookUp(translations, str, options) ?? str, args);
}

export function formatPlural(
  translations: Translations | null | undefined,
  count: number,
  singular: string,
  plural: string,
  args?: TranslationArgs,
  options?: TranslationOptions,
): string {
  const source = singular + PLURAL_DELIMITER + plural;
  const translated = lookUp(translations, source, options);
  const forms = replacePlaceholders(translated ?? source, {
    ...args,
    '@count': count,
  }).split(PLURAL_DELIMITER);
  // The formula indexes the translation's forms; source strings are English.
  const formula = translated ? translations?.pluralFormula : null;
  // Drupal stores the formula for counts up to 199 only, and larger counts
  // share the index of their remainder over 100.
  // @see locale_get_plural()
  const index = formula
    ? (formula[count > 199 ? 100 + (count % 100) : count] ??
      formula.default ??
      0)
    : count === 1
      ? 0
      : 1;
  // A translation with too few forms falls back to its last one.
  return forms[index] ?? forms[forms.length - 1];
}

function replacePlaceholders(
  translated: string,
  args: TranslationArgs | undefined,
): string {
  // Only `@` placeholders are supported: other keys are ignored.
  const keys = Object.keys(args ?? {}).filter(
    (key): key is keyof TranslationArgs => key.startsWith('@'),
  );
  if (!args || keys.length === 0) {
    return translated;
  }
  // Longest keys first, so `@name` does not replace part of `@names`; a
  // single pass, so values containing placeholders are not replaced again.
  // @see Drupal.stringReplace()
  const pattern = new RegExp(
    keys
      .sort((a, b) => b.length - a.length)
      .map(escapeRegExp)
      .join('|'),
    'g',
  );
  return translated.replace(pattern, (key) =>
    String(args[key as keyof TranslationArgs]),
  );
}
