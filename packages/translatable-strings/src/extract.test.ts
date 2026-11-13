import { describe, expect, it } from 'vitest';

import {
  getTranslatableStringsFromSource,
  normalizeTranslatableStrings,
} from './extract';

const HOOK = "import { useTranslation } from 'drupal-canvas/react';\n";

const strings = (source: string) => {
  const result = getTranslatableStringsFromSource(source);
  if (!('translatableStrings' in result)) {
    throw new Error(JSON.stringify(result.errors));
  }
  return result.translatableStrings;
};

const sources = (source: string) => strings(source).map((s) => s.source);

describe('getTranslatableStringsFromSource', () => {
  it('extracts t() and formatPlural() calls with their context', () => {
    expect(
      strings(`${HOOK}
        export default function Greeting({ name, files }) {
          const { t, formatPlural } = useTranslation();
          const at = (value) => value;
          return <p title={at('Not a t() call')}>
            {t('Hello @name', { '@name': String(name) })}
            {t("It's @count", { '@count': 1 }, { context: 'Counter' })}
            {t('Untranslated')} {t('Hello @name', {})}
            {formatPlural(Math.max(files, 0), '1 file', '@count files')}
            {formatPlural(files.length, "1 day", "@count days", {}, { context: 'Age' })}
          </p>;
        }`),
    ).toEqual([
      { source: '1 file', plural: '@count files' },
      { source: 'Hello @name' },
      { source: 'Untranslated' },
      { source: '1 day', plural: '@count days', context: 'Age' },
      { source: "It's @count", context: 'Counter' },
    ]);
  });

  it('handles nesting, escapes and options-only contexts', () => {
    expect(
      strings(`${HOOK}
        const { t, formatPlural } = useTranslation();
        t('May', {}, { 'context': 'Long month name' });
        t('Loading\\u2026 \\uD83D\\uDE00 \\x41 it\\'s \\
continued');
        t('Hello @name', { '@name': t('Guest', {}, { context: 'Visitor' }) });
        t('Hi @name', { '@name': String(t('Friend')) }, { context: 'Greeting' });
        t('Closing @x', { '@x': ')' }, { context: 'Parenthesis' });
        formatPlural(Math.max(n, 0), '1 item', '@count items', { '@x': String(t('Inner')) });
        t('Decoy @name', { '@name': "context: 'Visitor'", context: 'Arguments' }, { nested: { context: 'Nested' }, context: 'Options', context: 'Last' });
        formatPlural(n, '1 decoy', '@count decoys', { context: 'Arguments' });
        formatPlural(n, '1 option', '@count options', {}, { context: 'Plural' });
      `),
    ).toEqual([
      { source: '1 decoy', plural: '@count decoys' },
      { source: '1 item', plural: '@count items' },
      { source: 'Friend' },
      { source: 'Hello @name' },
      { source: 'Inner' },
      { source: "Loading… \u{1F600} A it's continued" },
      { source: 'Hi @name', context: 'Greeting' },
      { source: 'Decoy @name', context: 'Last' },
      { source: 'May', context: 'Long month name' },
      { source: 'Closing @x', context: 'Parenthesis' },
      { source: '1 option', plural: '@count options', context: 'Plural' },
      { source: 'Guest', context: 'Visitor' },
    ]);
  });

  it('follows the bindings of the hook', () => {
    expect(
      sources(`
        import { useTranslation as useI18n } from 'drupal-canvas/react';
        import * as canvas from 'drupal-canvas/react';
        const { t: tr, 'formatPlural': plural } = useI18n();
        tr('Renamed');
        plural(n, 'One key', '@count keys');
        const i18n = canvas.useTranslation();
        i18n.t('Member');
        i18n.formatPlural(n, 'One member', '@count members');
        useI18n().t('Direct');
        canvas.useTranslation().formatPlural(n, 'One direct', '@count direct');
      `),
    ).toEqual([
      'Direct',
      'Member',
      'One direct',
      'One key',
      'One member',
      'Renamed',
    ]);
  });

  it('ignores functions that do not come from the hook', () => {
    expect(
      sources(`
        import { useTranslation } from 'drupal-canvas/react';
        import { useTranslation as other } from 'drupal-canvas';
        import type { useTranslation as typeOnly } from 'drupal-canvas/react';
        import { t } from 'some-library';
        t('Imported elsewhere');
        other().t('Other module');
        const { t: fromOther } = other();
        fromOther('Other module destructured');
        function formatPlural() {}
        formatPlural(n, 'Local function', 'Local functions');
        const { t: tr } = useTranslation();
        tr('Kept');
        const i18n = useTranslation();
        i18n['t']('Computed member');
      `),
    ).toEqual(['Kept']);
  });

  it('respects shadowing', () => {
    expect(
      sources(`${HOOK}
        const { t } = useTranslation();
        t('Outer');
        function parameter(t) { t('Parameter'); }
        function local() { function t() {} t('Local function'); }
        try {} catch (t) { t('Catch clause'); }
        { let t = () => ''; t('Block'); }
        function hook() {
          const useTranslation = () => ({ t: () => '' });
          useTranslation().t('Shadowed hook');
        }
      `),
    ).toEqual(['Outer']);
  });

  it('does not follow reassigned or escaped bindings', () => {
    expect(
      sources(`${HOOK}
        let { t } = useTranslation();
        t('Reassigned');
        t = (s) => s;
        let i18n = useTranslation();
        i18n.t('Reassigned object');
        i18n = null;
        const { t: tr, ...rest } = useTranslation();
        const alias = tr;
        alias('Escaped');
        rest.formatPlural(n, 'Rest', 'Rests');
        const { t: defaulted = () => '' } = useTranslation();
        defaulted('Default');
        tr('Kept');
      `),
    ).toEqual(['Kept']);
  });

  it('skips calls without string literals', () => {
    expect(
      sources(`${HOOK}
        const { t, formatPlural } = useTranslation();
        t(\`Template\`);
        t(variable);
        t('Concatenated ' + name);
        t(...args);
        t('Spread arguments', ...args);
        t('Options identifier', {}, options);
        t('Options call', {}, getOptions());
        t('Dynamic context', {}, { context: name });
        t('Template context', {}, { context: \`Context\` });
        t('Spread after', {}, { context: 'A', ...options });
        t('Computed after', {}, { context: 'A', [key]: 'B' });
        t('Getter', {}, { get context() { return 'A'; } });
        formatPlural(n, singular, '@count plurals');
        formatPlural(n, 'One template', \`@count templates\`);
        formatPlural(n, 'One option', '@count options', {}, options);
        t('Kept');
      `),
    ).toEqual(['Kept']);
  });

  it('lets a later static context override unknown ones', () => {
    expect(
      strings(`${HOOK}
        const { t } = useTranslation();
        t('Overridden', {}, { ...options, context: 'A' });
        t('Overridden computed', {}, { [key]: 'B', context: 'B' });
        t('Empty context', {}, { context: '' });
        t('Spread placeholders', { ...values }, { context: 'C' });
      `),
    ).toEqual([
      { source: 'Empty context' },
      { source: 'Overridden', context: 'A' },
      { source: 'Overridden computed', context: 'B' },
      { source: 'Spread placeholders', context: 'C' },
    ]);
  });

  it('reports literals that Drupal cannot store', () => {
    const result = getTranslatableStringsFromSource(`${HOOK}
const { t, formatPlural } = useTranslation();
t('');
formatPlural(n, 'One item', '');
t('Delimiter \\x03');
t('Context', {}, { context: 'Month – long' });
t('Long context', {}, { context: '${'a'.repeat(256)}' });
t('Longest context', {}, { context: '${'a'.repeat(255)}' });
t('Valid');
`);
    expect(result).toEqual({
      errors: [
        {
          message: 'A translatable string must not be empty.',
          line: 4,
          column: 3,
        },
        {
          message: 'A translatable string must not be empty.',
          line: 5,
          column: 29,
        },
        {
          message:
            'A translatable string must not contain the U+0003 character, which separates plural forms.',
          line: 6,
          column: 3,
        },
        {
          message: 'A translation context must contain only ASCII characters.',
          line: 7,
          column: 29,
        },
        {
          message:
            'A translation context must not be longer than 255 characters.',
          line: 8,
          column: 34,
        },
      ],
    });
  });

  it('does not report invalid literals of skipped calls', () => {
    expect(
      sources(`${HOOK}
        const { t } = useTranslation();
        t('', {}, options);
        t('Dynamic', {}, { context: 'Month – long', ...options });
        t('Kept');
      `),
    ).toEqual(['Kept']);
  });

  it('reports parse errors', () => {
    expect(getTranslatableStringsFromSource(`${HOOK}const x = ;`)).toEqual({
      errors: [
        {
          message: expect.stringContaining('Unexpected token'),
          line: 2,
          column: 11,
        },
      ],
      syntaxError: true,
    });
  });

  it('returns no strings without the hook', () => {
    expect(
      getTranslatableStringsFromSource("t('Not using the hook');"),
    ).toEqual({
      translatableStrings: [],
    });
  });
});

describe('normalizeTranslatableStrings', () => {
  it('deduplicates and sorts by context, source and plural', () => {
    expect(
      normalizeTranslatableStrings([
        { source: 'b' },
        { source: 'a', plural: 'as' },
        { source: 'a' },
        { source: 'a', context: 'A' },
        { source: 'a' },
        { source: 'a', plural: 'aa' },
        { source: 'a', plural: 'as' },
      ]),
    ).toEqual([
      { source: 'a' },
      { source: 'a', plural: 'aa' },
      { source: 'a', plural: 'as' },
      { source: 'b' },
      { source: 'a', context: 'A' },
    ]);
  });

  it('compares code points, not UTF-16 code units', () => {
    // U+1F600 is encoded with surrogates (0xD83D 0xDE00) that sort before
    // U+FF5E in UTF-16, but its code point sorts after it.
    expect(
      normalizeTranslatableStrings([
        { source: '\u{1F600}' },
        { source: '～' },
        { source: 'z' },
      ]).map(({ source }) => source),
    ).toEqual(['z', '～', '\u{1F600}']);
  });
});
