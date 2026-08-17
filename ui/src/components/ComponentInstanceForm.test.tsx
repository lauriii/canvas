import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import AppWrapper from '@tests/vitest/components/AppWrapper';

import { makeStore } from '@/app/store';
import ComponentInstanceForm from '@/components/ComponentInstanceForm';
import {
  initialState as layoutModelInitialState,
  NodeType,
} from '@/features/layout/layoutModelSlice';
import {
  EditorFrameContext,
  initialState as uiInitialState,
} from '@/features/ui/uiSlice';

import type {
  ComponentModels,
  ComponentNode,
  RegionNode,
} from '@/features/layout/layoutModelSlice';
import type { ComponentsList, FieldData } from '@/types/Component';
import type { UUID } from '@/types/UUID';

const queryMocks = vi.hoisted(() => ({
  components: vi.fn(),
  componentInstanceForm: vi.fn(),
}));

vi.mock('@/services/componentAndLayout', async () => {
  const actual = await vi.importActual('@/services/componentAndLayout');
  return {
    ...actual,
    useGetComponentsQuery: queryMocks.components,
  };
});

vi.mock('@/services/componentInstanceForm', async () => {
  const actual = await vi.importActual('@/services/componentInstanceForm');
  return {
    ...actual,
    useGetComponentInstanceFormQuery: queryMocks.componentInstanceForm,
  };
});

const BLOCK_INSTANCE_UUID = 'ba8ff5e4-5b98-4c11-9a35-1c1e7bd4a8f3' as UUID;
const BLOCK_COMPONENT_TYPE = 'block.system_branding_block';
const SDC_INSTANCE_UUID = 'd2c9a1f7-3e64-4a10-b8d2-5f0c7e9a4b61' as UUID;
const SDC_COMPONENT_TYPE = 'sdc.canvas_test_sdc.my-cta';

const SDC_PROP_SOURCES: FieldData = {
  text: {
    jsonSchema: { type: 'string' },
    sourceType: 'static:field_item:string',
    expression: 'ℹ︎string␟value',
    sourceTypeSettings: {},
    default_values: { source: {}, resolved: {} },
  },
};

// A block component has no `propSources` key, which is what makes
// `buildPreparedModel()` return its argument unchanged.
// @see \Drupal\canvas\Plugin\Canvas\ComponentSource\BlockComponent
const components: ComponentsList = {
  [BLOCK_COMPONENT_TYPE]: {
    id: BLOCK_COMPONENT_TYPE,
    name: 'Site branding',
    library: 'dynamic_components',
    source: 'Blocks',
    default_markup: '',
    css: '',
    js_header: '',
    js_footer: '',
    version: 'ab4d3ddce315cbe1',
    broken: false,
  },
  // A prop source component takes the other branch of `buildPreparedModel()`,
  // where `source` is rebuilt from the component's prop metadata.
  // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase
  [SDC_COMPONENT_TYPE]: {
    id: SDC_COMPONENT_TYPE,
    name: 'My CTA',
    library: 'elements',
    source: 'Module component',
    default_markup: '',
    css: '',
    js_header: '',
    js_footer: '',
    version: 'b1c2d3e4f5a6b7c8',
    broken: false,
    propSources: SDC_PROP_SOURCES,
    metadata: {},
    transforms: {},
  },
};

const componentInstance = (
  uuid: UUID,
  type: string,
  version: string,
): ComponentNode => ({
  nodeType: NodeType.Component,
  uuid,
  type: `${type}@${version}`,
  slots: [],
});

const layout: RegionNode[] = [
  {
    nodeType: NodeType.Region,
    id: 'content',
    name: 'Content',
    components: [
      componentInstance(
        BLOCK_INSTANCE_UUID,
        BLOCK_COMPONENT_TYPE,
        'ab4d3ddce315cbe1',
      ),
      componentInstance(
        SDC_INSTANCE_UUID,
        SDC_COMPONENT_TYPE,
        'b1c2d3e4f5a6b7c8',
      ),
    ],
  },
];

const renderForm = (model: ComponentModels, selected = BLOCK_INSTANCE_UUID) => {
  const store = makeStore({
    layoutModel: {
      past: [],
      present: {
        ...layoutModelInitialState,
        layout,
        model,
      },
      future: [],
    },
    ui: {
      ...uiInitialState,
      editorFrameContext: EditorFrameContext.ENTITY,
      selection: { consecutive: true, items: [selected] },
    },
  });

  return render(
    <AppWrapper store={store} location="/" path="/">
      <ComponentInstanceForm />
    </AppWrapper>,
  );
};

// Returns the `form_canvas_props` value of the query string the component
// instance form was requested with, or null if it was never requested.
const requestedProps = (): string | null => {
  const call = queryMocks.componentInstanceForm.mock.calls.at(-1);
  if (!call) {
    return null;
  }
  const { queryString } = call[0] as { queryString: string };
  return new URLSearchParams(queryString).get('form_canvas_props');
};

// Returns the text of the error boundary fallback, or null when the boundary
// did not catch anything.
const errorBoundaryText = (): string | null =>
  screen.queryByTestId('canvas-error-card')?.textContent ?? null;

describe('ComponentInstanceForm', () => {
  beforeEach(() => {
    queryMocks.components.mockReturnValue({
      data: components,
      error: undefined,
      isLoading: false,
    });
    queryMocks.componentInstanceForm.mockReturnValue({
      currentData: undefined,
      error: undefined,
      originalArgs: undefined,
      isFetching: false,
    });
  });

  // Regression test: the server may omit a component instance from the model
  // when it has nothing to configure. That used to raise the panel's error
  // boundary with "Cannot read properties of undefined (reading 'source')".
  // @see \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList::getClientSideRepresentation()
  it('does not raise the error boundary when a block is missing from the model', () => {
    renderForm({});

    expect(errorBoundaryText()).toBeNull();
    expect(requestedProps()).toBe(JSON.stringify({ resolved: {} }));
  });

  it('does not raise the error boundary when a prop source component is missing from the model', () => {
    renderForm({}, SDC_INSTANCE_UUID);

    expect(errorBoundaryText()).toBeNull();
    // The empty model gains a `source` rebuilt from the component's prop
    // metadata, which is what a component instance with no stored values gets.
    expect(requestedProps()).toBe(
      JSON.stringify({ resolved: {}, source: { text: SDC_PROP_SOURCES.text } }),
    );
  });

  it('requests the component instance form with the model when the model entry exists', () => {
    renderForm({ [BLOCK_INSTANCE_UUID]: { resolved: { label: 'Branding' } } });

    expect(errorBoundaryText()).toBeNull();
    expect(requestedProps()).toBe(
      JSON.stringify({ resolved: { label: 'Branding' } }),
    );
  });
});
