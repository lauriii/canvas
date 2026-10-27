/**
 * @file
 * Framework-independent string translation with the same signatures as
 * Drupal's `Drupal.t()` and `Drupal.formatPlural()`. `useTranslation()` binds
 * them to the context's translations.
 */

import type { Translations } from './context-types.js';

/** Placeholder replacements, e.g. `{ '@name': name }`. */
export type TranslationArgs = Record<string, string | number>;

export interface TranslationOptions {
  /** The string context, to tell apart identical source strings. */
  context?: string;
}

/**
 * Translates a string, like `Drupal.t()`.
 *
 * Unlike `Drupal.t()`, the result is plain text and never HTML: all
 * placeholder prefixes (`@`, `%`, `:`) insert the value as is, and `%` does not
 * add `<em>` markup. React escapes the text when it renders it.
 */
export type TranslateFunction = (
  str: string,
  args?: TranslationArgs,
  options?: TranslationOptions,
) => string;

/**
 * Formats a singular or plural string for a count, like
 * `Drupal.formatPlural()`. `@count` is replaced with the count. The result is
 * plain text, as with `TranslateFunction`.
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
  const index = formula
    ? (formula[count] ?? formula.default ?? 0)
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
  const keys = Object.keys(args ?? {});
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
  return translated.replace(pattern, (key) => String(args[key]));
}
