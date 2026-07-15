import { describe, expect, it } from 'vitest';

import {
  buildClientWidgetContext,
  registerClientWidget,
  resolveClientWidget,
  resolveNativeWidgetForProp,
} from './registry';

import type { FieldDataItem } from '@/types/Component';
import type { ClientWidgetDefinition } from './types';

const makeDefinition = (): ClientWidgetDefinition => ({
  component: () => null,
  codec: {
    toModel: (v) => ({ resolved: v }),
    fromModel: (s, r) => r ?? s,
  },
});

const fieldData = (overrides: Partial<FieldDataItem> = {}): FieldDataItem =>
  ({
    expression: 'ℹ︎string␟value',
    sourceType: 'static:field_item:string',
    field_widget: 'test_widget',
    default_values: { resolved: {}, source: {} },
    jsonSchema: { type: 'string' },
    ...overrides,
  }) as FieldDataItem;

const settings = { native: true, disabledWidgets: [] as string[] };

describe('client widget registry', () => {
  it('registers, resolves, and overrides widgets by id', () => {
    const first = makeDefinition();
    const second = makeDefinition();
    registerClientWidget('test_widget', first);
    expect(resolveClientWidget('test_widget')).toBe(first);
    // Registering the same id again replaces the widget (override semantics).
    registerClientWidget('test_widget', second);
    expect(resolveClientWidget('test_widget')).toBe(second);
  });

  it('resolves unregistered ids to undefined (escape hatch)', () => {
    expect(resolveClientWidget('nonexistent_widget')).toBeUndefined();
    const context = buildClientWidgetContext(
      'prop',
      'sdc.test.foo',
      'v1',
      fieldData({ field_widget: 'nonexistent_widget' }),
    );
    expect(resolveNativeWidgetForProp(context, settings)).toBeUndefined();
  });

  it('honors the kill switch', () => {
    registerClientWidget('test_widget', makeDefinition());
    const context = buildClientWidgetContext(
      'prop',
      'sdc.test.foo',
      'v1',
      fieldData(),
    );
    expect(
      resolveNativeWidgetForProp(context, {
        native: false,
        disabledWidgets: [],
      }),
    ).toBeUndefined();
    expect(resolveNativeWidgetForProp(context, settings)).toBeDefined();
  });

  it('honors the per-widget-id disable list', () => {
    registerClientWidget('test_widget', makeDefinition());
    const context = buildClientWidgetContext(
      'prop',
      'sdc.test.foo',
      'v1',
      fieldData(),
    );
    expect(
      resolveNativeWidgetForProp(context, {
        native: true,
        disabledWidgets: ['test_widget'],
      }),
    ).toBeUndefined();
  });

  it('honors widget eligibility checks', () => {
    registerClientWidget('picky_widget', {
      ...makeDefinition(),
      isEligible: (context) => 'enum' in context.jsonSchema,
    });
    const ineligible = buildClientWidgetContext(
      'prop',
      'sdc.test.foo',
      'v1',
      fieldData({ field_widget: 'picky_widget' }),
    );
    expect(resolveNativeWidgetForProp(ineligible, settings)).toBeUndefined();
    const eligible = buildClientWidgetContext(
      'prop',
      'sdc.test.foo',
      'v1',
      fieldData({
        field_widget: 'picky_widget',
        jsonSchema: { type: 'string', enum: ['a'] },
      }),
    );
    expect(resolveNativeWidgetForProp(eligible, settings)).toBeDefined();
  });

  it('sends multi-value props to the hatch unless the widget handles them', () => {
    registerClientWidget('single_only_widget', makeDefinition());
    registerClientWidget('multi_capable_widget', {
      ...makeDefinition(),
      handlesMultipleValues: true,
    });
    const multiContext = (widgetId: string) =>
      buildClientWidgetContext(
        'prop',
        'sdc.test.foo',
        'v1',
        fieldData({
          field_widget: widgetId,
          sourceTypeSettings: { cardinality: -1 },
        }),
      );
    expect(
      resolveNativeWidgetForProp(multiContext('single_only_widget'), settings),
    ).toBeUndefined();
    expect(
      resolveNativeWidgetForProp(multiContext('multi_capable_widget'), settings),
    ).toBeDefined();
    // Array-typed props behave like multi-cardinality props.
    const arrayContext = buildClientWidgetContext(
      'prop',
      'sdc.test.foo',
      'v1',
      fieldData({
        field_widget: 'single_only_widget',
        jsonSchema: {
          type: 'array',
          items: { type: 'string' },
        } as FieldDataItem['jsonSchema'],
      }),
    );
    expect(
      resolveNativeWidgetForProp(arrayContext, settings),
    ).toBeUndefined();
  });

  it('derives cardinality and requiredness in the context', () => {
    const context = buildClientWidgetContext(
      'prop',
      'sdc.test.foo',
      'v1',
      fieldData({
        required: true,
        sourceTypeSettings: { cardinality: -1 },
      }),
    );
    expect(context.cardinality).toBe(-1);
    expect(context.required).toBe(true);
  });
});
