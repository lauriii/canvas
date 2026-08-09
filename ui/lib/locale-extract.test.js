// cspell:ignore Backticked

import fs from 'fs';
import os from 'os';
import path from 'path';
import { afterEach, describe, expect, it } from 'vitest';

import {
  countCallSites,
  countContextArguments,
  extractStrings,
  PLURAL_DELIMITER,
  verifyBundleIsScannable,
} from './locale-extract.js';

/**
 * Extraction has to keep working on what the build emits, not just on source.
 *
 * These are the shapes esbuild produces from the Canvas editor source: double
 * quotes, no whitespace, template literals folded into plain strings, and
 * everything on one line.
 */
const MINIFIED = `var e="World";var r=()=>Drupal.t("Save changes"),c=()=>Drupal.t("Hello @name",{"@name":e}),s=()=>Drupal.t("Add",{},{context:"Canvas component"}),p=()=>Drupal.formatPlural(3,"1 component","@count components"),l=()=>Drupal.formatPlural(3,"1 page","@count pages",{},{context:"Canvas"});`;

const tempDirs = [];

/**
 * Writes files into a throwaway directory and returns its path.
 */
function scratch(files) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'locale-extract-'));
  tempDirs.push(dir);
  for (const [name, contents] of Object.entries(files)) {
    fs.mkdirSync(path.dirname(path.join(dir, name)), { recursive: true });
    fs.writeFileSync(path.join(dir, name), contents);
  }
  return dir;
}

afterEach(() => {
  while (tempDirs.length) {
    fs.rmSync(tempDirs.pop(), { recursive: true, force: true });
  }
});

describe('extractStrings', () => {
  it('finds every kind of call in minified output', () => {
    expect(extractStrings(MINIFIED)).toEqual([
      { source: 'Save changes', context: '' },
      { source: 'Hello @name', context: '' },
      { source: 'Add', context: 'Canvas component' },
      {
        source: `1 component${PLURAL_DELIMITER}@count components`,
        context: '',
      },
      { source: `1 page${PLURAL_DELIMITER}@count pages`, context: 'Canvas' },
    ]);
  });

  it('finds calls wrapped across source lines by the formatter', () => {
    const code = `
      Drupal.t(
        'A string long enough that Prettier puts it on its own line',
      );
    `;
    expect(extractStrings(code)).toEqual([
      {
        source: 'A string long enough that Prettier puts it on its own line',
        context: '',
      },
    ]);
  });

  it('joins concatenated string literals', () => {
    expect(extractStrings(`x = Drupal.t('One ' + 'string');`)).toEqual([
      { source: 'One string', context: '' },
    ]);
  });

  it('cannot read a template literal, which is why the build checks', () => {
    const code = 'x = Drupal.t(`Backticked`);';
    expect(extractStrings(code)).toEqual([]);
    expect(countCallSites(code)).toBe(1);
  });

  it('cannot read a variable argument, which is why the build checks', () => {
    const code = 'const t = (s) => Drupal.t(s);';
    expect(extractStrings(code)).toEqual([]);
    expect(countCallSites(code)).toBe(1);
  });
});

describe('countContextArguments', () => {
  it('counts a call passing an options argument, readable or not', () => {
    expect(
      countContextArguments(`x = Drupal.t('Add', {}, { context: 'Canvas' });`),
    ).toBe(1);
    expect(countContextArguments(`x = Drupal.t('Add', {}, options);`)).toBe(1);
    expect(
      countContextArguments(
        `x = Drupal.formatPlural(n, '1 x', '@count x', {}, opts);`,
      ),
    ).toBe(1);
  });

  it('does not count a call with no options argument', () => {
    expect(countContextArguments(`x = Drupal.t('Add');`)).toBe(0);
    expect(
      countContextArguments(`x = Drupal.t('Hi @name', { '@name': n });`),
    ).toBe(0);
    expect(
      countContextArguments(`x = Drupal.formatPlural(n, '1 x', '@count x');`),
    ).toBe(0);
  });
});

describe('verifyBundleIsScannable', () => {
  it('passes when every source string is in the bundle', () => {
    const source = scratch({
      'Ok.tsx': `export const a = () => Drupal.t('Save changes');`,
    });
    const bundle = scratch({ 'index.js': MINIFIED });

    const { problems, strings, callSites } = verifyBundleIsScannable(source, [
      path.join(bundle, 'index.js'),
    ]);

    expect(problems).toEqual([]);
    expect(strings).toHaveLength(1);
    expect(callSites).toBe(1);
  });

  it('reports a call the scanner cannot read', () => {
    const source = scratch({
      'Bad.tsx': 'export const a = () => Drupal.t(`Backticked`);',
    });
    const bundle = scratch({ 'index.js': MINIFIED });

    const { problems } = verifyBundleIsScannable(source, [
      path.join(bundle, 'index.js'),
    ]);

    expect(problems).toHaveLength(1);
    expect(problems[0]).toContain('Bad.tsx');
    expect(problems[0]).toContain('only 0 extractable string(s)');
  });

  it('reports a context handed over as a shared variable', () => {
    const source = scratch({
      'Bad.tsx': [
        `const options = { context: 'Canvas' };`,
        `export const a = () => Drupal.t('Add', {}, options);`,
      ].join('\n'),
    });
    const bundle = scratch({
      'index.js': `x=Drupal.t("Add");`,
    });

    const { problems } = verifyBundleIsScannable(source, [
      path.join(bundle, 'index.js'),
    ]);

    expect(problems).toHaveLength(1);
    expect(problems[0]).toContain('pass an options argument');
  });

  it('reports a context handed over as a function parameter', () => {
    // Nothing in this file is an inline literal, so counting literals would see
    // zero and zero and wave it through, while Drupal registers "Add" with an
    // empty context and the UI looks it up with one.
    const source = scratch({
      'Bad.tsx': [
        `export const a = (options) => Drupal.t('Add', {}, options);`,
      ].join('\n'),
    });
    const bundle = scratch({
      'index.js': `x=Drupal.t("Add");`,
    });

    const { problems } = verifyBundleIsScannable(source, [
      path.join(bundle, 'index.js'),
    ]);

    expect(problems).toHaveLength(1);
    expect(problems[0]).toContain('pass an options argument');
  });

  it('accepts an inline context literal', () => {
    const source = scratch({
      'Ok.tsx': `export const a = () => Drupal.t('Add', {}, { context: 'Canvas component' });`,
    });
    const bundle = scratch({ 'index.js': MINIFIED });

    expect(
      verifyBundleIsScannable(source, [path.join(bundle, 'index.js')]).problems,
    ).toEqual([]);
  });

  it('reports a string that did not survive into the bundle', () => {
    const source = scratch({
      'Ok.tsx': `export const a = () => Drupal.t('Only in source');`,
    });
    const bundle = scratch({ 'index.js': MINIFIED });

    const { problems } = verifyBundleIsScannable(source, [
      path.join(bundle, 'index.js'),
    ]);

    expect(problems).toHaveLength(1);
    expect(problems[0]).toContain('not discoverable in the built bundle');
    expect(problems[0]).toContain('Only in source');
  });

  it('ignores tests and stories, which Drupal never loads', () => {
    const source = scratch({
      'Thing.stories.tsx': `Drupal.t('Never shipped');`,
      'Thing.test.tsx': `Drupal.t('Never shipped either');`,
    });
    const bundle = scratch({ 'index.js': MINIFIED });

    expect(
      verifyBundleIsScannable(source, [path.join(bundle, 'index.js')]).problems,
    ).toEqual([]);
  });
});
