import { describe, expect, it, vi } from 'vitest';

import {
  canvasTreeToSpec,
  defineComponentCatalog,
  defineComponentRegistry,
  renderCanvasTree,
  renderSpec,
  specToCanvasTree,
} from './json-render-utils';

import type React from 'react';
import type { ComponentMetadata } from '@drupal-canvas/discovery';
import type { Spec } from '@json-render/core';
import type { CanvasComponentTree } from './json-render-utils';

const UUID_PATTERN =
  /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;

describe('canvasTreeToSpec', () => {
  describe('single component without children', () => {
    it('should convert a basic component', () => {
      const components: CanvasComponentTree = [
        {
          parent_uuid: null,
          slot: null,
          uuid: '69cb59d5-353f-43b0-9e90-a3e3c0960afe',
          component_id: 'Button',
          component_version: 'a681ae184a8f6b7f',
          inputs: {
            label: 'Click me',
            variant: 'primary',
          },
          label: 'My Button',
        },
      ];

      const result = canvasTreeToSpec(components);

      expect(result).toEqual({
        root: '69cb59d5-353f-43b0-9e90-a3e3c0960afe',
        elements: {
          '69cb59d5-353f-43b0-9e90-a3e3c0960afe': {
            type: 'Button',
            props: {
              label: 'Click me',
              variant: 'primary',
            },
          },
        },
      });
    });

    it('should handle component with no props', () => {
      const components: CanvasComponentTree = [
        {
          parent_uuid: null,
          slot: null,
          uuid: 'd82adfe4-bab4-434b-bdbf-b434a7dfb380',
          component_id: 'Divider',
          component_version: 'a681ae184a8f6b7f',
          inputs: {},
          label: null,
        },
      ];

      const result = canvasTreeToSpec(components);

      expect(result).toEqual({
        root: 'd82adfe4-bab4-434b-bdbf-b434a7dfb380',
        elements: {
          'd82adfe4-bab4-434b-bdbf-b434a7dfb380': {
            type: 'Divider',
            props: {},
          },
        },
      });
    });
  });

  describe('components with named slots', () => {
    it('should convert component with multiple named slots', () => {
      const components: CanvasComponentTree = [
        {
          parent_uuid: null,
          slot: null,
          uuid: '26fe2511-4808-40df-8d90-6164ec3b5ac5',
          component_id: 'Layout',
          component_version: 'a681ae184a8f6b7f',
          inputs: {},
          label: 'Layout',
        },
        {
          parent_uuid: '26fe2511-4808-40df-8d90-6164ec3b5ac5',
          slot: 'header',
          uuid: '8abe4e58-75aa-4ffc-94fd-c9cefaa2f37c',
          component_id: 'Heading',
          component_version: 'a681ae184a8f6b7f',
          inputs: {
            text: 'Title',
          },
          label: 'Header',
        },
        {
          parent_uuid: '26fe2511-4808-40df-8d90-6164ec3b5ac5',
          slot: 'body',
          uuid: '075778c1-fa2a-4090-b407-447ed387f1a3',
          component_id: 'Paragraph',
          component_version: 'a681ae184a8f6b7f',
          inputs: {
            text: 'Content',
          },
          label: 'Body',
        },
        {
          parent_uuid: '26fe2511-4808-40df-8d90-6164ec3b5ac5',
          slot: 'footer',
          uuid: '877f73d2-2f59-48e2-827a-0b777b011216',
          component_id: 'Button',
          component_version: 'a681ae184a8f6b7f',
          inputs: {
            label: 'Action',
          },
          label: 'Footer Button',
        },
      ];

      const result = canvasTreeToSpec(components);

      expect(result).toEqual({
        root: '26fe2511-4808-40df-8d90-6164ec3b5ac5',
        elements: {
          '26fe2511-4808-40df-8d90-6164ec3b5ac5': {
            type: 'Layout',
            props: {},
            slots: {
              header: ['8abe4e58-75aa-4ffc-94fd-c9cefaa2f37c'],
              body: ['075778c1-fa2a-4090-b407-447ed387f1a3'],
              footer: ['877f73d2-2f59-48e2-827a-0b777b011216'],
            },
          },
          '8abe4e58-75aa-4ffc-94fd-c9cefaa2f37c': {
            type: 'Heading',
            props: {
              text: 'Title',
            },
          },
          '075778c1-fa2a-4090-b407-447ed387f1a3': {
            type: 'Paragraph',
            props: {
              text: 'Content',
            },
          },
          '877f73d2-2f59-48e2-827a-0b777b011216': {
            type: 'Button',
            props: {
              label: 'Action',
            },
          },
        },
      });
    });

    it('should convert slot with multiple children and preserve order', () => {
      const components: CanvasComponentTree = [
        {
          parent_uuid: null,
          slot: null,
          uuid: '181550d5-912e-4aef-ac59-3a270b1d1595',
          component_id: 'List',
          component_version: 'a681ae184a8f6b7f',
          inputs: {},
          label: 'List',
        },
        {
          parent_uuid: '181550d5-912e-4aef-ac59-3a270b1d1595',
          slot: 'items',
          uuid: '271ec173-2f8c-4cbe-9aaf-0d1069d401a2',
          component_id: 'Item',
          component_version: 'a681ae184a8f6b7f',
          inputs: {
            label: 'First',
          },
          label: 'First Item',
        },
        {
          parent_uuid: '181550d5-912e-4aef-ac59-3a270b1d1595',
          slot: 'items',
          uuid: '00b1191b-9fa8-43af-97be-f7e1a6469692',
          component_id: 'Item',
          component_version: 'a681ae184a8f6b7f',
          inputs: {
            label: 'Second',
          },
          label: 'Second Item',
        },
      ];

      const result = canvasTreeToSpec(components);

      expect(
        result.elements['181550d5-912e-4aef-ac59-3a270b1d1595'].slots,
      ).toEqual({
        items: [
          '271ec173-2f8c-4cbe-9aaf-0d1069d401a2',
          '00b1191b-9fa8-43af-97be-f7e1a6469692',
        ],
      });
    });
  });

  describe('nested components', () => {
    it('should convert deeply nested component tree', () => {
      const components: CanvasComponentTree = [
        {
          parent_uuid: null,
          slot: null,
          uuid: 'f221b7d5-7e9d-47fe-a80e-f2a7f003729d',
          component_id: 'Card',
          component_version: 'a681ae184a8f6b7f',
          inputs: {
            title: 'Card',
          },
          label: 'Card',
        },
        {
          parent_uuid: 'f221b7d5-7e9d-47fe-a80e-f2a7f003729d',
          slot: 'action',
          uuid: '84e5e54d-0ba2-4cb1-aa5c-abed4efdd501',
          component_id: 'Button',
          component_version: 'a681ae184a8f6b7f',
          inputs: {
            label: 'Click',
          },
          label: 'Button',
        },
        {
          parent_uuid: '84e5e54d-0ba2-4cb1-aa5c-abed4efdd501',
          slot: 'icon',
          uuid: 'e6510361-c9b5-4360-80ca-5f9c760d3a72',
          component_id: 'Icon',
          component_version: 'a681ae184a8f6b7f',
          inputs: {
            name: 'arrow',
          },
          label: 'Icon',
        },
      ];

      const result = canvasTreeToSpec(components);

      expect(
        result.elements['f221b7d5-7e9d-47fe-a80e-f2a7f003729d'].slots,
      ).toEqual({
        action: ['84e5e54d-0ba2-4cb1-aa5c-abed4efdd501'],
      });
      expect(
        result.elements['84e5e54d-0ba2-4cb1-aa5c-abed4efdd501'].slots,
      ).toEqual({
        icon: ['e6510361-c9b5-4360-80ca-5f9c760d3a72'],
      });
      expect(Object.keys(result.elements)).toHaveLength(3);
    });
  });

  describe('multiple root components', () => {
    it('should wrap multiple root components under a canvas:component-tree element', () => {
      const components: CanvasComponentTree = [
        {
          parent_uuid: null,
          slot: null,
          uuid: 'f2fe5384-762a-43ca-abcb-f3632fc6ed45',
          component_id: 'Button',
          component_version: 'a681ae184a8f6b7f',
          inputs: { label: 'Submit' },
          label: 'Button 1',
        },
        {
          parent_uuid: null,
          slot: null,
          uuid: 'e0d22200-78bf-43c1-a1ea-a37f1eceadb6',
          component_id: 'Text',
          component_version: 'a681ae184a8f6b7f',
          inputs: { content: 'Info' },
          label: 'Text 1',
        },
      ];

      const result = canvasTreeToSpec(components);

      expect(result.root).toBe('canvas:component-tree');
      expect(result.elements['canvas:component-tree']).toEqual({
        type: 'canvas:component-tree',
        props: {},
        children: [
          'f2fe5384-762a-43ca-abcb-f3632fc6ed45',
          'e0d22200-78bf-43c1-a1ea-a37f1eceadb6',
        ],
      });
      expect(
        result.elements['f2fe5384-762a-43ca-abcb-f3632fc6ed45'],
      ).toMatchObject({ type: 'Button' });
      expect(
        result.elements['e0d22200-78bf-43c1-a1ea-a37f1eceadb6'],
      ).toMatchObject({ type: 'Text' });
    });
  });

  describe('edge cases', () => {
    it('should throw when the component tree is empty', () => {
      expect(() => canvasTreeToSpec([])).toThrow(
        'Canvas component tree has no root component (no component with null parent_uuid).',
      );
    });

    it('should parse JSON-string inputs', () => {
      const components = [
        {
          parent_uuid: null,
          slot: null,
          uuid: '69cb59d5-353f-43b0-9e90-a3e3c0960afe',
          component_id: 'Button',
          component_version: 'a681ae184a8f6b7f',
          // Simulate an API response that serializes inputs as a JSON string.
          inputs:
            '{"label":"Click me","variant":"primary"}' as unknown as Record<
              string,
              unknown
            >,
          label: 'Button',
        },
      ];

      const result = canvasTreeToSpec(components);

      expect(
        result.elements['69cb59d5-353f-43b0-9e90-a3e3c0960afe'].props,
      ).toEqual({ label: 'Click me', variant: 'primary' });
    });

    it('should throw when a component references an unknown parent', () => {
      const components: CanvasComponentTree = [
        {
          parent_uuid: null,
          slot: null,
          uuid: '23f19d0d-ce8d-4287-9444-dc70d6cfd810',
          component_id: 'Card',
          component_version: 'a681ae184a8f6b7f',
          inputs: {},
          label: 'Card',
        },
        {
          parent_uuid: 'ffffffff-ffff-ffff-ffff-ffffffffffff',
          slot: 'body',
          uuid: '104dc725-712d-400c-9912-add5bfc48e17',
          component_id: 'Text',
          component_version: 'a681ae184a8f6b7f',
          inputs: {},
          label: 'Orphan',
        },
      ];

      expect(() => canvasTreeToSpec(components)).toThrow(
        'Component "104dc725-712d-400c-9912-add5bfc48e17" references unknown or out-of-order parent "ffffffff-ffff-ffff-ffff-ffffffffffff".',
      );
    });

    it('should throw when a component has a parent_uuid but no slot', () => {
      const components: CanvasComponentTree = [
        {
          parent_uuid: null,
          slot: null,
          uuid: '23f19d0d-ce8d-4287-9444-dc70d6cfd810',
          component_id: 'Card',
          component_version: 'a681ae184a8f6b7f',
          inputs: {},
          label: 'Card',
        },
        {
          parent_uuid: '23f19d0d-ce8d-4287-9444-dc70d6cfd810',
          slot: null,
          uuid: '104dc725-712d-400c-9912-add5bfc48e17',
          component_id: 'Text',
          component_version: 'a681ae184a8f6b7f',
          inputs: {},
          label: 'Invalid',
        },
      ];

      expect(() => canvasTreeToSpec(components)).toThrow(
        'Component "104dc725-712d-400c-9912-add5bfc48e17" has a parent_uuid but no slot.',
      );
    });
  });
});

describe('specToCanvasTree', () => {
  it('should reuse the key as uuid when the key is a valid uuid', () => {
    const spec: Spec = {
      root: '69cb59d5-353f-43b0-9e90-a3e3c0960afe',
      elements: {
        '69cb59d5-353f-43b0-9e90-a3e3c0960afe': {
          type: 'Button',
          props: { label: 'Click me', variant: 'primary' },
        },
      },
    };

    const result = specToCanvasTree(spec);

    expect(result).toHaveLength(1);
    expect(result[0].uuid).toBe('69cb59d5-353f-43b0-9e90-a3e3c0960afe');
    expect(result[0]).toMatchObject({
      parent_uuid: null,
      slot: null,
      component_id: 'Button',
      component_version: null,
      inputs: { label: 'Click me', variant: 'primary' },
      label: null,
    });
  });

  it('should generate a uuid when the key is not a valid uuid', () => {
    const spec: Spec = {
      root: 'button',
      elements: {
        button: {
          type: 'Button',
          props: { label: 'Click me', variant: 'primary' },
        },
      },
    };

    const result = specToCanvasTree(spec);

    expect(result).toHaveLength(1);
    expect(result[0].uuid).toMatch(UUID_PATTERN);
    expect(result[0]).toMatchObject({
      parent_uuid: null,
      slot: null,
      component_id: 'Button',
      component_version: null,
      inputs: { label: 'Click me', variant: 'primary' },
      label: null,
    });
  });

  it('should wire parent_uuid of children to the generated uuid of their parent and preserve slot order', () => {
    const spec: Spec = {
      root: 'layout',
      elements: {
        layout: {
          type: 'Layout',
          props: {},
          children: ['text'],
          slots: { footer: ['button-a', 'button-b'] },
        },
        text: {
          type: 'Text',
          props: { content: 'Hello' },
        },
        'button-a': {
          type: 'Button',
          props: { label: 'Cancel' },
        },
        'button-b': {
          type: 'Button',
          props: { label: 'OK' },
        },
      },
    };

    const [layout, text, buttonA, buttonB] = specToCanvasTree(spec);

    expect(layout.uuid).toMatch(UUID_PATTERN);
    expect(layout.parent_uuid).toBeNull();
    expect(layout.slot).toBeNull();
    expect(layout.component_id).toBe('Layout');

    expect(text.parent_uuid).toBe(layout.uuid);
    expect(text.slot).toBe('children');
    expect(text.component_id).toBe('Text');

    expect(buttonA.parent_uuid).toBe(layout.uuid);
    expect(buttonA.slot).toBe('footer');
    expect(buttonA.inputs).toEqual({ label: 'Cancel' });

    expect(buttonB.parent_uuid).toBe(layout.uuid);
    expect(buttonB.slot).toBe('footer');
    expect(buttonB.inputs).toEqual({ label: 'OK' });
  });

  it('should throw when a slot references an unknown element key', () => {
    const spec: Spec = {
      root: 'layout',
      elements: {
        layout: {
          type: 'Layout',
          props: {},
          slots: { body: ['missing-key'] },
        },
      },
    };

    expect(() => specToCanvasTree(spec)).toThrow(
      'Element key "missing-key" not found in elements map.',
    );
  });

  it('should generate unique uuids for each node', () => {
    const spec: Spec = {
      root: 'fragment',
      elements: {
        fragment: {
          type: 'Fragment',
          props: {},
          children: ['text-a', 'text-b'],
        },
        'text-a': { type: 'Text', props: {} },
        'text-b': { type: 'Text', props: {} },
      },
    };

    const result = specToCanvasTree(spec);
    const uuids = result.map((n) => n.uuid);

    expect(new Set(uuids).size).toBe(uuids.length);
  });

  it('should resolve $state prop expressions to literal values', () => {
    const spec: Spec = {
      root: 'heading',
      elements: {
        heading: {
          type: 'Heading',
          props: { text: { $state: '/title' }, level: 2 },
        },
      },
      state: { title: 'Resolved Title' },
    };

    const result = specToCanvasTree(spec);

    expect(result).toHaveLength(1);
    expect(result[0].inputs).toEqual({ text: 'Resolved Title', level: 2 });
  });
});

describe('round-trip: canvasTreeToSpec → specToCanvasTree', () => {
  it('should preserve structure through a round-trip', () => {
    const original: CanvasComponentTree = [
      {
        parent_uuid: null,
        slot: null,
        uuid: 'f221b7d5-7e9d-47fe-a80e-f2a7f003729d',
        component_id: 'Layout',
        component_version: 'a681ae184a8f6b7f',
        inputs: { title: 'Page' },
        label: 'Layout',
      },
      {
        parent_uuid: 'f221b7d5-7e9d-47fe-a80e-f2a7f003729d',
        slot: 'header',
        uuid: '84e5e54d-0ba2-4cb1-aa5c-abed4efdd501',
        component_id: 'Heading',
        component_version: 'a681ae184a8f6b7f',
        inputs: { text: 'Hello' },
        label: 'Heading',
      },
      {
        parent_uuid: 'f221b7d5-7e9d-47fe-a80e-f2a7f003729d',
        slot: 'children',
        uuid: 'e6510361-c9b5-4360-80ca-5f9c760d3a72',
        component_id: 'Text',
        component_version: 'a681ae184a8f6b7f',
        inputs: { content: 'Body' },
        label: 'Text',
      },
    ];

    const spec = canvasTreeToSpec(original);
    const result = specToCanvasTree(spec);

    expect(result).toHaveLength(3);

    const root = result.find((n) => n.parent_uuid === null)!;
    expect(root.uuid).toBe('f221b7d5-7e9d-47fe-a80e-f2a7f003729d');
    expect(root.component_id).toBe('Layout');
    expect(root.slot).toBeNull();
    expect(root.inputs).toEqual({ title: 'Page' });

    const heading = result.find((n) => n.component_id === 'Heading')!;
    expect(heading.uuid).toBe('84e5e54d-0ba2-4cb1-aa5c-abed4efdd501');
    expect(heading.parent_uuid).toBe(root.uuid);
    expect(heading.slot).toBe('header');
    expect(heading.inputs).toEqual({ text: 'Hello' });

    const text = result.find((n) => n.component_id === 'Text')!;
    expect(text.uuid).toBe('e6510361-c9b5-4360-80ca-5f9c760d3a72');
    expect(text.parent_uuid).toBe(root.uuid);
    expect(text.slot).toBe('children');
    expect(text.inputs).toEqual({ content: 'Body' });
  });

  it('should strip the synthetic canvas:component-tree wrapper for multiple root components', () => {
    const original: CanvasComponentTree = [
      {
        parent_uuid: null,
        slot: null,
        uuid: 'f2fe5384-762a-43ca-abcb-f3632fc6ed45',
        component_id: 'Button',
        component_version: 'a681ae184a8f6b7f',
        inputs: { label: 'Submit' },
        label: 'Button 1',
      },
      {
        parent_uuid: null,
        slot: null,
        uuid: 'e0d22200-78bf-43c1-a1ea-a37f1eceadb6',
        component_id: 'Text',
        component_version: 'a681ae184a8f6b7f',
        inputs: { content: 'Info' },
        label: 'Text 1',
      },
    ];

    const spec = canvasTreeToSpec(original);
    const result = specToCanvasTree(spec);

    expect(result).toHaveLength(2);
    expect(
      result.every((n) => n.component_id !== 'canvas:component-tree'),
    ).toBe(true);

    const button = result.find((n) => n.component_id === 'Button')!;
    expect(button.parent_uuid).toBeNull();
    expect(button.inputs).toEqual({ label: 'Submit' });

    const text = result.find((n) => n.component_id === 'Text')!;
    expect(text.parent_uuid).toBeNull();
    expect(text.inputs).toEqual({ content: 'Info' });
  });
});

describe('renderSpec', () => {
  it('should render a single-root spec', () => {
    const spec: Spec = {
      root: 'btn',
      elements: {
        btn: {
          type: 'Button',
          props: { label: 'Go' },
        },
      },
    };

    const Button = (props: Record<string, unknown>) => (
      <button>{props.label as string}</button>
    );
    const result = renderSpec(spec, { Button });

    expect((result as React.ReactElement).type).toBe(Button);
    expect((result as React.ReactElement).props.label).toBe('Go');
  });

  it('should render a spec with children and slots', () => {
    const spec: Spec = {
      root: 'layout',
      elements: {
        layout: {
          type: 'Layout',
          props: {},
          children: ['text'],
          slots: { footer: ['btn'] },
        },
        text: {
          type: 'Text',
          props: { content: 'Hello' },
        },
        btn: {
          type: 'Button',
          props: { label: 'OK' },
        },
      },
    };

    const Layout = (props: Record<string, unknown>) => (
      <div>
        <main>{props.children as React.ReactNode}</main>
        <footer>{props.footer as React.ReactNode}</footer>
      </div>
    );
    const Text = (props: Record<string, unknown>) => (
      <p>{props.content as string}</p>
    );
    const Button = (props: Record<string, unknown>) => (
      <button>{props.label as string}</button>
    );
    const result = renderSpec(spec, { Layout, Text, Button });

    expect((result as React.ReactElement).type).toBe(Layout);
    expect((result as React.ReactElement).props.children[0].type).toBe(Text);
    expect((result as React.ReactElement).props.children[0].props.content).toBe(
      'Hello',
    );
    expect((result as React.ReactElement).props.footer[0].type).toBe(Button);
    expect((result as React.ReactElement).props.footer[0].props.label).toBe(
      'OK',
    );
  });

  it('should return null for unknown component types', () => {
    const spec: Spec = {
      root: 'x',
      elements: {
        x: { type: 'Missing', props: {} },
      },
    };

    expect(renderSpec(spec, {})).toBeNull();
  });

  it('should render multi-root spec transparently', () => {
    const spec: Spec = {
      root: 'canvas:component-tree',
      elements: {
        'canvas:component-tree': {
          type: 'canvas:component-tree',
          props: {},
          children: ['a', 'b'],
        },
        a: { type: 'Button', props: { label: 'A' } },
        b: { type: 'Button', props: { label: 'B' } },
      },
    };

    const Button = (props: Record<string, unknown>) => (
      <button>{props.label as string}</button>
    );
    const result = renderSpec(spec, { Button });
    const children = (result as React.ReactElement).props
      .children as React.ReactElement[];

    expect(children).toHaveLength(2);
    expect(children[0].type).toBe(Button);
    expect(children[0].props.label).toBe('A');
    expect(children[1].type).toBe(Button);
    expect(children[1].props.label).toBe('B');
  });
});

describe('renderSpec state resolving', () => {
  it('should resolve $state prop expressions from spec.state', () => {
    const spec: Spec = {
      root: 'heading',
      elements: {
        heading: {
          type: 'Heading',
          props: { text: { $state: '/title' } },
        },
      },
      state: { title: 'Hello from state' },
    };

    const Heading = (props: Record<string, unknown>) => (
      <h1>{props.text as string}</h1>
    );
    const result = renderSpec(spec, { Heading });

    expect((result as React.ReactElement).type).toBe(Heading);
    expect((result as React.ReactElement).props.text).toBe('Hello from state');
  });

  it('should resolve $cond/$then/$else expressions', () => {
    const spec: Spec = {
      root: 'badge',
      elements: {
        badge: {
          type: 'Badge',
          props: {
            variant: {
              $cond: { $state: '/active' },
              $then: 'success',
              $else: 'muted',
            },
          },
        },
      },
      state: { active: true },
    };

    const Badge = (_props: Record<string, unknown>) => <span />;
    const resultActive = renderSpec(spec, { Badge });

    expect((resultActive as React.ReactElement).type).toBe(Badge);
    expect((resultActive as React.ReactElement).props.variant).toBe('success');

    // With active = false, should resolve to $else.
    const specInactive: Spec = { ...spec, state: { active: false } };
    const resultInactive = renderSpec(specInactive, { Badge });
    expect((resultInactive as React.ReactElement).props.variant).toBe('muted');
  });

  it('should work unchanged for specs without state', () => {
    const spec: Spec = {
      root: 'btn',
      elements: {
        btn: {
          type: 'Button',
          props: { label: 'Go' },
        },
      },
    };

    const Button = (props: Record<string, unknown>) => (
      <button>{props.label as string}</button>
    );
    const result = renderSpec(spec, { Button });

    expect((result as React.ReactElement).type).toBe(Button);
    expect((result as React.ReactElement).props.label).toBe('Go');
  });
});

describe('renderCanvasTree', () => {
  it('should call the component with props from inputs', () => {
    const components: CanvasComponentTree = [
      {
        parent_uuid: null,
        slot: null,
        uuid: '69cb59d5-353f-43b0-9e90-a3e3c0960afe',
        component_id: 'Button',
        component_version: null,
        inputs: { label: 'Click me', variant: 'primary' },
        label: null,
      },
    ];

    const Button = (props: Record<string, unknown>) => (
      <button data-variant={props.variant as string}>
        {props.label as string}
      </button>
    );
    const result = renderCanvasTree(components, { Button });

    expect(result).toEqual(<Button label="Click me" variant="primary" />);
  });

  it('should normalize rich text objects to plain string props', async () => {
    const components: CanvasComponentTree = [
      {
        parent_uuid: null,
        slot: null,
        uuid: '91f69efe-3b49-4b74-82bf-ce70bb35f9c7',
        component_id: 'Paragraph',
        component_version: null,
        inputs: {
          text: {
            value: '<p>Hello <em>world</em>.</p>',
            format: 'canvas_html_block',
          },
        },
        label: null,
      },
    ];

    const Paragraph = (props: Record<string, unknown>) => (
      <p>{props.text as string}</p>
    );
    const result = await renderCanvasTree(components, { Paragraph });

    expect((result as React.ReactElement).type).toBe(Paragraph);
    expect((result as React.ReactElement).props.text).toBe(
      '<p>Hello <em>world</em>.</p>',
    );
  });

  it('should normalize wrapped image objects to flattened image props', async () => {
    const components: CanvasComponentTree = [
      {
        parent_uuid: null,
        slot: null,
        uuid: 'f770f017-7ec8-4f52-a259-4dcb9b1a0b6f',
        component_id: 'Hero',
        component_version: null,
        inputs: {
          image: {
            sourceType: 'default-relative-url',
            value: {
              src: 'https://example.com/hero.jpg',
              alt: 'Hero image',
              width: 1200,
              height: 800,
            },
            componentId: 'js.hero',
          },
        },
        label: null,
      },
    ];

    const Hero = (props: Record<string, unknown>) => (
      <img src={(props.image as Record<string, unknown>).src as string} />
    );
    const result = await renderCanvasTree(components, { Hero });

    expect((result as React.ReactElement).type).toBe(Hero);
    expect((result as React.ReactElement).props.image).toEqual({
      src: 'https://example.com/hero.jpg',
      alt: 'Hero image',
      width: 1200,
      height: 800,
    });
  });

  it('should return null for components not in the registry', () => {
    const components: CanvasComponentTree = [
      {
        parent_uuid: null,
        slot: null,
        uuid: '69cb59d5-353f-43b0-9e90-a3e3c0960afe',
        component_id: 'Unknown',
        component_version: null,
        inputs: {},
        label: null,
      },
    ];

    const result = renderCanvasTree(components, {});

    expect(result).toBeNull();
  });

  it('should pass children as a prop', () => {
    const components: CanvasComponentTree = [
      {
        parent_uuid: null,
        slot: null,
        uuid: 'f221b7d5-7e9d-47fe-a80e-f2a7f003729d',
        component_id: 'Wrapper',
        component_version: null,
        inputs: {},
        label: null,
      },
      {
        parent_uuid: 'f221b7d5-7e9d-47fe-a80e-f2a7f003729d',
        slot: 'children',
        uuid: '84e5e54d-0ba2-4cb1-aa5c-abed4efdd501',
        component_id: 'Text',
        component_version: null,
        inputs: { content: 'Hello' },
        label: null,
      },
    ];

    const Wrapper = (props: Record<string, unknown>) => (
      <div>{props.children as React.ReactNode}</div>
    );
    const Text = (props: Record<string, unknown>) => (
      <p>{props.content as string}</p>
    );
    const result = renderCanvasTree(components, { Wrapper, Text });

    expect((result as React.ReactElement).type).toBe(Wrapper);
    const children = (result as React.ReactElement).props
      .children as React.ReactElement[];
    expect(children).toHaveLength(1);
    expect(children[0].type).toBe(Text);
    expect(children[0].props.content).toBe('Hello');
  });

  it('should pass named slots as props', () => {
    const components: CanvasComponentTree = [
      {
        parent_uuid: null,
        slot: null,
        uuid: '26fe2511-4808-40df-8d90-6164ec3b5ac5',
        component_id: 'Layout',
        component_version: null,
        inputs: {},
        label: null,
      },
      {
        parent_uuid: '26fe2511-4808-40df-8d90-6164ec3b5ac5',
        slot: 'header',
        uuid: '8abe4e58-75aa-4ffc-94fd-c9cefaa2f37c',
        component_id: 'Heading',
        component_version: null,
        inputs: { text: 'Title' },
        label: null,
      },
    ];

    const Layout = (props: Record<string, unknown>) => (
      <div className="layout">
        <header>{props.header as React.ReactNode}</header>
      </div>
    );
    const Heading = (props: Record<string, unknown>) => (
      <h1>{props.text as string}</h1>
    );
    const result = renderCanvasTree(components, { Layout, Heading });

    expect((result as React.ReactElement).type).toBe(Layout);
    const header = (result as React.ReactElement).props
      .header as React.ReactElement[];
    expect(header).toHaveLength(1);
    expect(header[0].type).toBe(Heading);
    expect(header[0].props.text).toBe('Title');
  });

  it('should render multiple root components wrapped transparently', () => {
    const components: CanvasComponentTree = [
      {
        parent_uuid: null,
        slot: null,
        uuid: 'f2fe5384-762a-43ca-abcb-f3632fc6ed45',
        component_id: 'Button',
        component_version: null,
        inputs: { label: 'A' },
        label: null,
      },
      {
        parent_uuid: null,
        slot: null,
        uuid: 'e0d22200-78bf-43c1-a1ea-a37f1eceadb6',
        component_id: 'Button',
        component_version: null,
        inputs: { label: 'B' },
        label: null,
      },
    ];

    const Button = (props: Record<string, unknown>) => (
      <button>{props.label as string}</button>
    );
    const result = renderCanvasTree(components, { Button });

    const fragmentChildren = (result as React.ReactElement).props
      .children as React.ReactElement[];
    expect(fragmentChildren).toHaveLength(2);
    expect(fragmentChildren[0].type).toBe(Button);
    expect(fragmentChildren[0].props.label).toBe('A');
    expect(fragmentChildren[1].type).toBe(Button);
    expect(fragmentChildren[1].props.label).toBe('B');
  });
});

describe('defineComponentRegistry', () => {
  it('should build a registry from components with JS entry paths', async () => {
    const MyButton = (props: Record<string, unknown>) => (
      <button>{props.label as string}</button>
    );

    vi.doMock('/fake/Button.js', () => ({ default: MyButton }));

    const registry = await defineComponentRegistry([
      { name: 'Button', jsEntryPath: '/fake/Button.js' },
    ]);

    expect(registry['js.button']).toBe(MyButton);

    vi.doUnmock('/fake/Button.js');
  });

  it('should skip components with null jsEntryPath', async () => {
    const registry = await defineComponentRegistry([
      { name: 'NoEntry', jsEntryPath: null },
    ]);

    expect(registry).toEqual({});
  });

  it('should skip modules whose default export is not a function', async () => {
    vi.doMock('/fake/NotAComponent.js', () => ({
      default: 'not a function',
    }));

    const registry = await defineComponentRegistry([
      { name: 'Bad', jsEntryPath: '/fake/NotAComponent.js' },
    ]);

    expect(registry).toEqual({});

    vi.doUnmock('/fake/NotAComponent.js');
  });

  it('should handle a mix of valid and invalid components', async () => {
    const ValidComp = () => <span>valid</span>;

    vi.doMock('/fake/Valid.js', () => ({ default: ValidComp }));
    vi.doMock('/fake/Invalid.js', () => ({ default: 42 }));

    const registry = await defineComponentRegistry([
      { name: 'Valid', jsEntryPath: '/fake/Valid.js' },
      { name: 'Invalid', jsEntryPath: '/fake/Invalid.js' },
      { name: 'NoPath', jsEntryPath: null },
    ]);

    expect(Object.keys(registry)).toEqual(['Valid', 'js.valid']);
    expect(registry.Valid).toBe(ValidComp);
    expect(registry['js.valid']).toBe(ValidComp);

    vi.doUnmock('/fake/Valid.js');
    vi.doUnmock('/fake/Invalid.js');
  });
});

describe('defineComponentCatalog', () => {
  it('should create a catalog from component metadata', () => {
    const metadata: ComponentMetadata[] = [
      {
        name: 'Button',
        machineName: 'button',
        status: true,
        props: {
          properties: {
            label: { title: 'Label', type: 'string' },
            variant: { title: 'Variant', type: 'string' },
          },
        },
        required: ['label'],
        slots: {},
      },
    ];

    const catalog = defineComponentCatalog(metadata);

    expect(catalog).toBeDefined();
    expect(catalog.componentNames).toEqual(['js.button']);
    expect(catalog.data.components['js.button']).toBeDefined();
  });

  it('should include slots in the catalog entry', () => {
    const metadata: ComponentMetadata[] = [
      {
        name: 'Card',
        machineName: 'card',
        status: true,
        props: {
          properties: {
            title: { title: 'Title', type: 'string' },
          },
        },
        required: [],
        slots: {
          header: { title: 'Header' },
          body: { title: 'Body' },
        },
      },
    ];

    const catalog = defineComponentCatalog(metadata);

    expect(catalog.data.components['js.card']).toBeDefined();
    expect(catalog.data.components['js.card'].slots).toEqual([
      'header',
      'body',
    ]);
  });

  it('should handle metadata with no props or slots', () => {
    const metadata: ComponentMetadata[] = [
      {
        name: 'Divider',
        machineName: 'divider',
        status: true,
        props: { properties: {} },
        required: [],
        slots: {},
      },
    ];

    const catalog = defineComponentCatalog(metadata);

    expect(catalog.componentNames).toEqual(['js.divider']);
    expect(catalog.data.components['js.divider'].slots).toEqual([]);
  });

  it('should handle multiple components', () => {
    const metadata: ComponentMetadata[] = [
      {
        name: 'Button',
        machineName: 'button',
        status: true,
        props: { properties: { label: { title: 'Label', type: 'string' } } },
        required: [],
        slots: {},
      },
      {
        name: 'Text',
        machineName: 'text',
        status: true,
        props: {
          properties: { content: { title: 'Content', type: 'string' } },
        },
        required: ['content'],
        slots: {},
      },
    ];

    const catalog = defineComponentCatalog(metadata);

    expect(catalog.componentNames).toEqual(['js.button', 'js.text']);
  });

  it('should produce a catalog that can generate a prompt', () => {
    const metadata: ComponentMetadata[] = [
      {
        name: 'Button',
        machineName: 'button',
        status: true,
        props: { properties: { label: { title: 'Label', type: 'string' } } },
        required: ['label'],
        slots: {},
      },
    ];

    const catalog = defineComponentCatalog(metadata);
    const prompt = catalog.prompt();

    expect(prompt).toContain('js.button');
  });

  it('should produce a catalog that can validate a spec', () => {
    const metadata: ComponentMetadata[] = [
      {
        name: 'Button',
        machineName: 'button',
        status: true,
        props: { properties: { label: { title: 'Label', type: 'string' } } },
        required: [],
        slots: {},
      },
    ];

    const catalog = defineComponentCatalog(metadata);

    const validSpec = {
      root: 'btn',
      elements: {
        btn: {
          type: 'js.button',
          props: { label: 'Click me' },
          children: [],
          slots: {},
        },
      },
    };
    expect(catalog.validate(validSpec).success).toBe(true);

    const invalidSpec = {
      root: 'btn',
      elements: {
        btn: {
          type: 'js.button',
        },
      },
    };
    expect(catalog.validate(invalidSpec).success).toBe(false);
  });
});
