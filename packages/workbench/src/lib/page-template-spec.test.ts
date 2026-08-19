import { describe, expect, it } from 'vitest';

import {
  normalizePageTemplateSpec,
  parsePageTemplateSpec,
} from './page-template-spec';

describe('normalizePageTemplateSpec', () => {
  it('wraps top-level elements in a canvas:component-tree root', () => {
    const normalized = normalizePageTemplateSpec({
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
    expect(normalizePageTemplateSpec({ elements: {} }).status).toBe(true);
    expect(
      normalizePageTemplateSpec({ elements: {}, status: false }).status,
    ).toBe(false);
  });

  it('excludes slot-referenced elements from the synthetic top-level children', () => {
    const normalized = normalizePageTemplateSpec({
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

describe('parsePageTemplateSpec', () => {
  it('parses a valid page template file', () => {
    const result = parsePageTemplateSpec(
      {
        label: 'Marketing',
        elements: {
          a: { type: 'js.logo', props: {} },
          content: { type: 'marker.page_content', props: {} },
        },
      },
      '/tmp/page-templates/marketing.json',
      { componentNames: ['js.logo'] },
    );

    expect(result.issues).toHaveLength(0);
    expect(result.pageTemplate).not.toBeNull();
    expect(result.pageTemplate?.spec.root).toBe('canvas:component-tree');
  });

  it('parses the optional status and default flags', () => {
    const result = parsePageTemplateSpec(
      {
        label: 'Marketing',
        description: 'Marketing pages.',
        status: true,
        default: true,
        elements: {
          a: { type: 'js.logo', props: {} },
          content: { type: 'marker.page_content', props: {} },
        },
      },
      '/tmp/page-templates/marketing.json',
      { componentNames: ['js.logo'] },
    );

    expect(result.issues).toHaveLength(0);
    expect(result.pageTemplate).toMatchObject({
      label: 'Marketing',
      description: 'Marketing pages.',
      status: true,
      isDefault: true,
    });
  });

  it('rejects a non-boolean status', () => {
    const result = parsePageTemplateSpec(
      { status: 'yes', elements: {} },
      '/tmp/page-templates/marketing.json',
    );

    expect(result.pageTemplate).toBeNull();
    expect(
      result.issues.some((issue) =>
        issue.message.includes('"status" must be a boolean'),
      ),
    ).toBe(true);
  });

  it('rejects a non-boolean default', () => {
    const result = parsePageTemplateSpec(
      { default: 'yes', elements: {} },
      '/tmp/page-templates/marketing.json',
    );

    expect(result.pageTemplate).toBeNull();
    expect(
      result.issues.some((issue) =>
        issue.message.includes('"default" must be a boolean'),
      ),
    ).toBe(true);
  });

  it('rejects legacy region `theme`/`region` keys with a helpful message', () => {
    const result = parsePageTemplateSpec(
      { theme: 'olivero', region: 'header', elements: {} },
      '/tmp/page-templates/marketing.json',
    );

    expect(result.pageTemplate).toBeNull();
    expect(
      result.issues.some((issue) =>
        issue.message.includes('unexpected top-level keys'),
      ),
    ).toBe(true);
  });

  it('rejects unexpected top-level keys', () => {
    const result = parsePageTemplateSpec(
      { title: 'Header', elements: {} },
      '/tmp/page-templates/marketing.json',
    );

    expect(
      result.issues.some((issue) =>
        issue.message.includes('unexpected top-level keys'),
      ),
    ).toBe(true);
  });

  it('rejects a page template file that defines canvas:component-tree directly', () => {
    const result = parsePageTemplateSpec(
      {
        elements: {
          'canvas:component-tree': { type: 'canvas:component-tree', props: {} },
        },
      },
      '/tmp/page-templates/marketing.json',
    );

    expect(result.pageTemplate).toBeNull();
    expect(
      result.issues.some((issue) =>
        issue.message.includes('canvas:component-tree'),
      ),
    ).toBe(true);
  });

  it('rejects elements referencing unknown component types', () => {
    const result = parsePageTemplateSpec(
      {
        label: 'Marketing',
        elements: {
          a: { type: 'js.unknown', props: {} },
          content: { type: 'marker.page_content', props: {} },
        },
      },
      '/tmp/page-templates/marketing.json',
      { componentNames: ['js.logo'] },
    );

    expect(result.pageTemplate).toBeNull();
    expect(result.issues.length).toBeGreaterThan(0);
  });

  it('rejects a page template without exactly one page content marker', () => {
    const result = parsePageTemplateSpec(
      { label: 'Marketing', elements: {} },
      '/tmp/page-templates/marketing.json',
    );

    expect(result.pageTemplate).toBeNull();
    expect(
      result.issues.some((issue) =>
        issue.message.includes('exactly one marker.page_content'),
      ),
    ).toBe(true);
  });

  it('rejects a disabled default page template', () => {
    const result = parsePageTemplateSpec(
      {
        label: 'Marketing',
        status: false,
        default: true,
        elements: {
          content: { type: 'marker.page_content', props: {} },
        },
      },
      '/tmp/page-templates/marketing.json',
    );

    expect(result.pageTemplate).toBeNull();
    expect(
      result.issues.some((issue) =>
        issue.message.includes('default page template cannot be disabled'),
      ),
    ).toBe(true);
  });
});
