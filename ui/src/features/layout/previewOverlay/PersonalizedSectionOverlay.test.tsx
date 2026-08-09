import { Provider } from 'react-redux';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';

import { makeStore } from '@/app/store';
import { NodeType, setLayoutModel } from '@/features/layout/layoutModelSlice';
import {
  setEditorFrameViewPort,
  setPreviewedVariant,
} from '@/features/ui/uiSlice';

import PersonalizedSectionOverlay from './PersonalizedSectionOverlay';

import type { CanvasGeometry } from '@drupal-canvas/preview-geometry';
import type {
  ComponentModels,
  ComponentNode,
  RegionNode,
  SlotNode,
} from '@/features/layout/layoutModelSlice';
import type { PreviewDomMaps } from '@/features/layout/preview/PreviewDomContext';
import type { CanvasGeometryMap } from '@/features/layout/preview/PreviewGeometryContext';

const mocks = vi.hoisted(() => ({
  componentsMap: null as PreviewDomMaps['componentsMap'] | null,
  geometryMap: {
    component: {},
    slot: {},
    region: {},
  } as CanvasGeometryMap,
}));

vi.mock('@/features/layout/preview/PreviewDomContext', () => ({
  usePreviewDom: () =>
    mocks.componentsMap ? { componentsMap: mocks.componentsMap } : null,
}));

vi.mock('@/features/layout/preview/PreviewGeometryContext', () => ({
  usePreviewGeometry: () => ({ geometryMap: mocks.geometryMap }),
}));

const SWITCH_UUID = 'switch-1';
const CASE_UUID = 'case-coupon';
const DEFAULT_CASE_UUID = 'case-default';

const makeCase = (uuid: string): ComponentNode => ({
  nodeType: NodeType.Component,
  uuid,
  type: 'p13n.case@v1',
  slots: [
    {
      nodeType: NodeType.Slot,
      id: `${uuid}/content`,
      name: 'content',
      components: [],
    },
  ],
});

const switchSlot: SlotNode = {
  nodeType: NodeType.Slot,
  id: `${SWITCH_UUID}/content`,
  name: 'content',
  components: [makeCase(CASE_UUID), makeCase(DEFAULT_CASE_UUID)],
};

const layout: RegionNode[] = [
  {
    nodeType: NodeType.Region,
    id: 'content',
    name: 'Content',
    components: [
      {
        nodeType: NodeType.Component,
        uuid: SWITCH_UUID,
        type: 'p13n.switch@v1',
        slots: [switchSlot],
      },
    ],
  },
];

const model: ComponentModels = {
  [SWITCH_UUID]: { resolved: { variants: ['coupon_campaign', 'default'] } },
  [CASE_UUID]: {
    resolved: { variant_id: 'coupon_campaign', segments: ['coupon_users'] },
  },
  [DEFAULT_CASE_UUID]: {
    resolved: { variant_id: 'default', segments: ['default'] },
  },
};

interface Rect {
  top: number;
  left: number;
  width: number;
  height: number;
}

function makeElement(rect: Rect): HTMLElement {
  const element = document.createElement('div');
  element.getBoundingClientRect = () =>
    ({
      top: rect.top,
      left: rect.left,
      width: rect.width,
      height: rect.height,
      right: rect.left + rect.width,
      bottom: rect.top + rect.height,
      x: rect.left,
      y: rect.top,
      toJSON: () => ({}),
    }) as DOMRect;
  return element;
}

const caseGeometry: CanvasGeometry = {
  type: 'component',
  id: CASE_UUID,
  rect: { top: 0, right: 0, bottom: 0, left: 0, width: 0, height: 0 },
  markerFormat: 'comment',
};

function renderOverlay({
  previewedVariant,
  scale,
}: { previewedVariant?: string; scale?: number } = {}) {
  const store = makeStore();
  store.dispatch(setLayoutModel({ layout, model, updatePreview: false }));
  if (previewedVariant) {
    store.dispatch(
      setPreviewedVariant({
        switchUuid: SWITCH_UUID,
        variantId: previewedVariant,
      }),
    );
  }
  if (scale) {
    store.dispatch(setEditorFrameViewPort({ scale }));
  }
  render(
    <Provider store={store}>
      <PersonalizedSectionOverlay />
    </Provider>,
  );
}

describe('PersonalizedSectionOverlay', () => {
  beforeEach(() => {
    // Two sibling elements whose union spans top 100, left 10, right 250,
    // bottom 200.
    mocks.componentsMap = {
      [CASE_UUID]: {
        elements: [
          makeElement({ top: 100, left: 50, width: 200, height: 40 }),
          makeElement({ top: 140, left: 10, width: 100, height: 60 }),
        ],
      },
      [DEFAULT_CASE_UUID]: {
        elements: [makeElement({ top: 100, left: 0, width: 500, height: 500 })],
      },
    };
    mocks.geometryMap = {
      component: { [CASE_UUID]: caseGeometry },
      slot: {},
      region: {},
    };
  });

  it('draws one union rectangle with a variant tab for the active case', () => {
    renderOverlay({ previewedVariant: 'coupon_campaign' });

    const sections = screen.getAllByTestId('canvas-personalized-section');
    expect(sections).toHaveLength(1);
    expect(sections[0]).toHaveStyle({
      top: '100px',
      left: '10px',
      width: '240px',
      height: '100px',
    });
    expect(
      screen.getByTestId('canvas-personalized-section-label'),
    ).toHaveTextContent('Coupon campaign');
  });

  it('scales the rectangle with the editor viewport', () => {
    renderOverlay({ previewedVariant: 'coupon_campaign', scale: 0.5 });

    expect(screen.getByTestId('canvas-personalized-section')).toHaveStyle({
      top: '50px',
      left: '5px',
      width: '120px',
      height: '50px',
    });
  });

  it('renders nothing while previewing the default variant', () => {
    renderOverlay();

    expect(
      screen.queryByTestId('canvas-personalized-section'),
    ).not.toBeInTheDocument();
  });

  it('renders nothing while the active case is not measured', () => {
    mocks.geometryMap = { component: {}, slot: {}, region: {} };
    renderOverlay({ previewedVariant: 'coupon_campaign' });

    expect(
      screen.queryByTestId('canvas-personalized-section'),
    ).not.toBeInTheDocument();
  });

  it('renders nothing without a same-origin preview DOM', () => {
    mocks.componentsMap = null;
    renderOverlay({ previewedVariant: 'coupon_campaign' });

    expect(
      screen.queryByTestId('canvas-personalized-section'),
    ).not.toBeInTheDocument();
  });
});
