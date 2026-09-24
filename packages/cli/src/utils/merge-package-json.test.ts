import { describe, expect, it } from 'vitest';

import { mergePackageJsonDependencies } from './merge-package-json';

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
