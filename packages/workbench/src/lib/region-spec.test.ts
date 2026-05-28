import { describe, expect, it } from 'vitest';

import { normalizeRegionSpec, parseRegionSpec } from './region-spec';

describe('normalizeRegionSpec', () => {
  it('wraps top-level elements in a canvas:component-tree root', () => {
    const normalized = normalizeRegionSpec({
      elements: {
        'el-1': { type: 'js.logo', props: {} },
        'el-2': { type: 'js.nav', props: {} },
      },
    });

    expect(normalized.spec.root).toBe('canvas:component-tree');
    expect(normalized.spec.elements['canvas:component-tree']).toMatchObject({
      type: 'canvas:component-tree',
      children: ['el-1', 'el-2'],
    });
  });

  it('defaults status to true and preserves an explicit status', () => {
    expect(normalizeRegionSpec({ elements: {} }).status).toBe(true);
    expect(normalizeRegionSpec({ elements: {}, status: false }).status).toBe(
      false,
    );
  });

  it('excludes slot-referenced elements from the synthetic top-level children', () => {
    const normalized = normalizeRegionSpec({
      elements: {
        root: {
          type: 'js.header',
          props: {},
          slots: { branding: ['logo'] },
        },
        logo: { type: 'js.logo', props: {} },
      },
    });

    expect(normalized.spec.elements['canvas:component-tree'].children).toEqual([
      'root',
    ]);
  });
});

describe('parseRegionSpec', () => {
  it('parses a valid region file', () => {
    const result = parseRegionSpec(
      {
        elements: {
          a: { type: 'js.logo', props: {} },
        },
      },
      '/tmp/regions/header.json',
      { componentNames: ['js.logo'] },
    );

    expect(result.issues).toHaveLength(0);
    expect(result.region).not.toBeNull();
    expect(result.region?.spec.root).toBe('canvas:component-tree');
  });

  it('parses the optional status flag', () => {
    const result = parseRegionSpec(
      {
        status: false,
        elements: {
          a: { type: 'js.logo', props: {} },
        },
      },
      '/tmp/regions/header.json',
      { componentNames: ['js.logo'] },
    );

    expect(result.issues).toHaveLength(0);
    expect(result.region?.status).toBe(false);
  });

  it('rejects a non-boolean status', () => {
    const result = parseRegionSpec(
      { status: 'yes', elements: {} },
      '/tmp/regions/header.json',
    );

    expect(result.region).toBeNull();
    expect(
      result.issues.some((issue) =>
        issue.message.includes('"status" must be a boolean'),
      ),
    ).toBe(true);
  });

  it('rejects legacy `theme`/`region` keys with a helpful message', () => {
    const result = parseRegionSpec(
      { theme: 'olivero', region: 'header', elements: {} },
      '/tmp/regions/header.json',
    );

    expect(result.region).toBeNull();
    expect(
      result.issues.some((issue) =>
        issue.message.includes('unexpected top-level keys'),
      ),
    ).toBe(true);
  });

  it('rejects unexpected top-level keys', () => {
    const result = parseRegionSpec(
      { title: 'Header', elements: {} },
      '/tmp/regions/header.json',
    );

    expect(
      result.issues.some((issue) =>
        issue.message.includes('unexpected top-level keys'),
      ),
    ).toBe(true);
  });

  it('rejects a region file that defines canvas:component-tree directly', () => {
    const result = parseRegionSpec(
      {
        elements: {
          'canvas:component-tree': { type: 'canvas:component-tree', props: {} },
        },
      },
      '/tmp/regions/header.json',
    );

    expect(result.region).toBeNull();
    expect(
      result.issues.some((issue) =>
        issue.message.includes('canvas:component-tree'),
      ),
    ).toBe(true);
  });

  it('rejects elements referencing unknown component types', () => {
    const result = parseRegionSpec(
      {
        elements: {
          a: { type: 'js.unknown', props: {} },
        },
      },
      '/tmp/regions/header.json',
      { componentNames: ['js.logo'] },
    );

    expect(result.region).toBeNull();
    expect(result.issues.length).toBeGreaterThan(0);
  });
});
