import { describe, expect, it } from 'vitest';

import {
  mergePackageJsonDependencies,
  removeHeadlessSdkDependencies,
} from './merge-package-json';

describe('mergePackageJsonDependencies', () => {
  it('adds a pulled dependency missing from the local file', () => {
    const local = JSON.stringify({ dependencies: { react: '^18.0.0' } });
    const pulled = JSON.stringify({
      dependencies: { react: '^19.0.0', lodash: '^4.17.21' },
    });

    const { output, added } = mergePackageJsonDependencies(local, pulled);

    expect(added).toEqual(['lodash']);
    const merged = JSON.parse(output as string);
    // Existing entry keeps its local version (add-only).
    expect(merged.dependencies.react).toBe('^18.0.0');
    expect(merged.dependencies.lodash).toBe('^4.17.21');
  });

  it('returns null output when every pulled dependency already exists', () => {
    const local = JSON.stringify({
      dependencies: { react: '^18.0.0' },
      devDependencies: { typescript: '^5.0.0' },
      peerDependencies: { 'react-dom': '^18.0.0' },
    });
    const pulled = JSON.stringify({
      dependencies: {
        react: '^19.0.0',
        typescript: '^4.0.0',
        'react-dom': '^19.0.0',
      },
    });

    const { output, added } = mergePackageJsonDependencies(local, pulled);

    expect(output).toBeNull();
    expect(added).toEqual([]);
  });

  it('does not add a dependency present in devDependencies or peerDependencies', () => {
    const local = JSON.stringify({
      devDependencies: { typescript: '^5.0.0' },
      peerDependencies: { react: '^18.0.0' },
    });
    const pulled = JSON.stringify({
      dependencies: { typescript: '^4.0.0', react: '^19.0.0' },
    });

    const { output } = mergePackageJsonDependencies(local, pulled);

    expect(output).toBeNull();
  });

  it('creates a dependencies section when the local file lacks one', () => {
    const local = JSON.stringify({ name: 'p' });
    const pulled = JSON.stringify({ dependencies: { react: '^19.0.0' } });

    const { output, added } = mergePackageJsonDependencies(local, pulled);

    expect(added).toEqual(['react']);
    expect(JSON.parse(output as string).dependencies.react).toBe('^19.0.0');
  });

  it('preserves all other local fields', () => {
    const local = JSON.stringify({
      name: 'p',
      version: '1.0.0',
      scripts: { dev: 'next dev' },
      overrides: { postcss: '^8.0.0' },
      dependencies: {},
    });
    const pulled = JSON.stringify({
      name: 'remote',
      scripts: { dev: 'vite' },
      dependencies: { react: '^19.0.0' },
    });

    const merged = JSON.parse(
      mergePackageJsonDependencies(local, pulled).output as string,
    );

    expect(merged.name).toBe('p');
    expect(merged.version).toBe('1.0.0');
    expect(merged.scripts.dev).toBe('next dev');
    expect(merged.overrides.postcss).toBe('^8.0.0');
  });

  it('emits a 2-space indented file with a trailing newline', () => {
    const local = JSON.stringify({ dependencies: {} });
    const pulled = JSON.stringify({ dependencies: { react: '^19.0.0' } });

    const { output } = mergePackageJsonDependencies(local, pulled);

    expect(output).toMatch(/\n$/);
    expect(output).toContain('\n  "dependencies"');
  });

  it('ignores pulled devDependencies', () => {
    const local = JSON.stringify({ dependencies: {} });
    const pulled = JSON.stringify({
      dependencies: {},
      devDependencies: { eslint: '^9.0.0' },
    });

    const { output } = mergePackageJsonDependencies(local, pulled);

    expect(output).toBeNull();
  });

  it('throws when the local file is not valid JSON', () => {
    expect(() =>
      mergePackageJsonDependencies('{ "name": "p", }', '{}'),
    ).toThrow();
  });
});

describe('removeHeadlessSdkDependencies', () => {
  it('removes Headless SDK packages from dependencies and devDependencies', () => {
    const pulled = JSON.stringify({
      name: 'headless-app',
      scripts: { dev: 'next dev' },
      dependencies: {
        '@drupal-canvas/headless': '^0.1.0',
        '@drupal-canvas/headless-next': '^0.1.0',
        next: '^16.0.0',
        react: '^19.0.0',
      },
      devDependencies: {
        '@drupal-canvas/headless-react': '^0.1.0',
        '@drupal-canvas/cli': '^0.26.0',
        typescript: '^5.0.0',
      },
      peerDependencies: { '@drupal-canvas/headless': '^0.1.0' },
    });

    const filtered = JSON.parse(removeHeadlessSdkDependencies(pulled));

    expect(filtered).toEqual({
      name: 'headless-app',
      scripts: { dev: 'next dev' },
      dependencies: { next: '^16.0.0', react: '^19.0.0' },
      devDependencies: {
        '@drupal-canvas/cli': '^0.26.0',
        typescript: '^5.0.0',
      },
      // Not a section pull writes from, so it is left alone.
      peerDependencies: { '@drupal-canvas/headless': '^0.1.0' },
    });
    expect(Object.keys(filtered)).toEqual(Object.keys(JSON.parse(pulled)));
  });

  it('keeps packages that only resemble the Headless SDK', () => {
    const pulled = JSON.stringify({
      dependencies: {
        '@drupal-canvas/headlessui': '^1.0.0',
        '@other/headless': '^1.0.0',
        'drupal-canvas-headless': '^1.0.0',
      },
    });

    expect(removeHeadlessSdkDependencies(pulled)).toBe(pulled);
  });

  it('keeps a section that only held Headless SDK packages', () => {
    const pulled = JSON.stringify({
      dependencies: { '@drupal-canvas/headless': '^0.1.0' },
    });

    expect(JSON.parse(removeHeadlessSdkDependencies(pulled))).toEqual({
      dependencies: {},
    });
  });

  it('emits a 2-space indented file with a trailing newline when filtered', () => {
    const output = removeHeadlessSdkDependencies(
      JSON.stringify({ dependencies: { '@drupal-canvas/headless': '^0.1.0' } }),
    );

    expect(output).toBe('{\n  "dependencies": {}\n}\n');
  });

  it.each(['{ not json', 'null', '[]', '"text"'])(
    'returns %s unchanged',
    (pulled) => {
      expect(removeHeadlessSdkDependencies(pulled)).toBe(pulled);
    },
  );
});
