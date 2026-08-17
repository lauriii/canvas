import { Provider } from 'react-redux';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { DndContext } from '@dnd-kit/core';
import { TargetIcon } from '@radix-ui/react-icons';
import { Theme } from '@radix-ui/themes';
import { fireEvent, render, screen } from '@testing-library/react';

import { makeStore } from '@/app/store';
import {
  addVariant,
  NodeType,
  personalizePage,
  setInitialLayoutModel,
} from '@/features/layout/layoutModelSlice';
import {
  DEFAULT_VARIANT_ID,
  findRootSwitch,
  getCaseVariantId,
  getContentSlot,
  getSwitchCases,
} from '@/features/layout/personalizationUtils';
import { setPreviewedVariant } from '@/features/ui/uiSlice';

import ComponentLayer from './ComponentLayer';

import type { ReactNode } from 'react';
import type { AppStore } from '@/app/store';
import type {
  ComponentNode,
  SlotNode,
} from '@/features/layout/layoutModelSlice';
import type * as PersonalizationService from '@/services/personalization';

const { handleComponentSelection } = vi.hoisted(() => ({
  handleComponentSelection: vi.fn(),
}));

vi.mock('@/services/personalization', async (importOriginal) => {
  const actual = await importOriginal<typeof PersonalizationService>();
  return {
    ...actual,
    useGetSegmentsQuery: () => ({
      data: {
        returning: {
          id: 'returning',
          label: 'Returning visitors',
          status: true,
          weight: 0,
        },
      },
      isLoading: false,
      error: undefined,
    }),
  };
});

// Names in the layer tree come from the components listing; the tests only
// need stable, readable names per node.
vi.mock('@/hooks/useGetComponentName', () => ({
  default: (node: ComponentNode | SlotNode | null) => {
    if (!node) {
      return '';
    }
    if (node.nodeType === NodeType.Slot) {
      return (node as SlotNode).name;
    }
    return (node as ComponentNode).type.split('@')[0];
  },
}));

vi.mock('@/hooks/useComponentSelection', () => ({
  default: () => ({ handleComponentSelection }),
}));

vi.mock('@/features/layout/preview/ComponentContextMenu', () => ({
  default: ({ children }: { children: ReactNode }) => <>{children}</>,
  ComponentContextMenuContent: () => null,
}));

// The real drop zone renders anonymous divs; the mock exposes the structural
// props so drop-path wiring can be asserted.
vi.mock('@/features/layout/layers/LayersDropZone', () => ({
  default: ({
    layer,
    position,
    indent,
  }: {
    layer: ComponentNode | SlotNode;
    position: string;
    indent: number;
  }) => (
    <div
      data-testid="layers-dropzone"
      data-layer-id={'uuid' in layer ? layer.uuid : layer.id}
      data-position={position}
      data-indent={indent}
    />
  ),
}));

const HERO_UUID = 'hero-uuid';

const getSwitch = (store: AppStore): ComponentNode => {
  const rootSwitch = findRootSwitch(
    store.getState().layoutModel.present.layout[0],
  );
  if (!rootSwitch) {
    throw new Error('Expected personalizePage to create a root switch.');
  }
  return rootSwitch;
};

const findCase = (store: AppStore, variantId: string): ComponentNode => {
  const model = store.getState().layoutModel.present.model;
  const caseNode = getSwitchCases(getSwitch(store)).find(
    (candidate) => getCaseVariantId(model, candidate) === variantId,
  );
  if (!caseNode) {
    throw new Error(`Expected the switch to have a "${variantId}" case.`);
  }
  return caseNode;
};

const buildStore = ({ withHero = true }: { withHero?: boolean } = {}): {
  store: AppStore;
  switchUuid: string;
} => {
  const store = makeStore();
  store.dispatch(
    setInitialLayoutModel({
      layout: [
        {
          nodeType: NodeType.Region,
          id: 'content',
          name: 'Content',
          components: withHero
            ? [
                {
                  nodeType: NodeType.Component,
                  uuid: HERO_UUID,
                  type: 'sdc.hero@1',
                  slots: [],
                },
              ]
            : [],
        },
      ],
      model: withHero ? { [HERO_UUID]: { resolved: { title: 'Hello' } } } : {},
      updatePreview: false,
      isInitialized: true,
      translations: {},
    }),
  );
  store.dispatch(
    personalizePage({
      switchComponentType: 'p13n.switch@v1',
      caseComponentType: 'p13n.case@v1',
    }),
  );
  return { store, switchUuid: getSwitch(store).uuid };
};

const renderSwitchLayer = (store: AppStore): ComponentNode => {
  const switchNode = getSwitch(store);
  render(
    <Provider store={store}>
      <Theme>
        <DndContext>
          <ComponentLayer component={switchNode} indent={1} index={0} />
        </DndContext>
      </Theme>
    </Provider>,
  );
  return switchNode;
};

const getDropZones = () =>
  screen.getAllByTestId('layers-dropzone') as HTMLElement[];

describe('ComponentLayer personalization section', () => {
  beforeEach(() => {
    handleComponentSelection.mockClear();
  });

  it('renders one section row with the targeting icon and audience badge', () => {
    const { store } = buildStore();
    renderSwitchLayer(store);

    const title = screen.getByText('Personalized: Default');
    const row = title.closest('.canvas-drag-handle') as HTMLElement;
    expect(row).not.toBeNull();

    // The leading icon of the section row is the targeting icon.
    const { container: iconProbe } = render(<TargetIcon />);
    const expectedIconPath = iconProbe.querySelector('path')?.getAttribute('d');
    expect(expectedIconPath).toBeTruthy();
    const rowIconPaths = Array.from(row.querySelectorAll('svg path')).map(
      (path) => path.getAttribute('d'),
    );
    expect(rowIconPaths).toContain(expectedIconPath);

    // The audience of the active variant shows as a compact badge.
    expect(screen.getByLabelText('Audience')).toHaveTextContent(
      'Everyone (fallback)',
    );
  });

  it('selects the switch when the section row is clicked', () => {
    const { store, switchUuid } = buildStore();
    renderSwitchLayer(store);

    // A plain click event is used because the drag sensor of the layer tree
    // intercepts simulated pointer sequences in jsdom.
    fireEvent.click(screen.getByText('Personalized: Default'));

    expect(handleComponentSelection).toHaveBeenCalledWith(switchUuid, false);
  });

  it('renders the active case children directly under the section row', () => {
    const { store } = buildStore();
    const switchNode = renderSwitchLayer(store);

    // The author's component renders; the switch slot row, the case row, and
    // the case slot row do not.
    expect(screen.getByText('sdc.hero')).toBeInTheDocument();
    expect(screen.queryByText('content')).toBeNull();
    expect(screen.queryByText('p13n.case')).toBeNull();
    expect(screen.queryByText('Default — Everyone (fallback)')).toBeNull();

    // The child sits one level under the section row.
    const heroZones = getDropZones().filter(
      (zone) => zone.dataset.layerId === HERO_UUID,
    );
    expect(heroZones.map((zone) => zone.dataset.position).sort()).toEqual([
      'bottom',
      'top',
    ]);
    heroZones.forEach((zone) => expect(zone.dataset.indent).toBe('2'));

    // No drop zone attaches to the hidden plumbing slots.
    const defaultCase = findCase(store, DEFAULT_VARIANT_ID);
    const plumbingSlotIds = [
      getContentSlot(switchNode)?.id,
      getContentSlot(defaultCase)?.id,
    ];
    getDropZones().forEach((zone) => {
      expect(plumbingSlotIds).not.toContain(zone.dataset.layerId);
    });
  });

  it('titles the section by the previewed variant and hides inactive cases', () => {
    const { store, switchUuid } = buildStore();
    store.dispatch(
      addVariant({
        switchUuid,
        variantId: 'special_offer',
        segments: ['returning'],
        sourceVariantId: DEFAULT_VARIANT_ID,
      }),
    );
    store.dispatch(
      setPreviewedVariant({ switchUuid, variantId: 'special_offer' }),
    );
    renderSwitchLayer(store);

    expect(screen.getByText('Personalized: Special offer')).toBeInTheDocument();
    expect(screen.getByLabelText('Audience')).toHaveTextContent(
      'Returning visitors',
    );

    // Only the active case's copy of the component renders, wired to the
    // clone's UUID, so the inactive default case stays out of the tree.
    const offerCase = findCase(store, 'special_offer');
    const clonedHero = getContentSlot(offerCase)?.components[0];
    expect(clonedHero).toBeDefined();
    expect(clonedHero?.uuid).not.toBe(HERO_UUID);
    expect(screen.getAllByText('sdc.hero')).toHaveLength(1);
    const dropZones = getDropZones();
    expect(
      dropZones.some((zone) => zone.dataset.layerId === clonedHero?.uuid),
    ).toBe(true);
    expect(dropZones.some((zone) => zone.dataset.layerId === HERO_UUID)).toBe(
      false,
    );
  });

  it('shows the empty drop zone of the active case slot under the section row', () => {
    const { store } = buildStore({ withHero: false });
    const switchNode = renderSwitchLayer(store);

    const defaultCase = findCase(store, DEFAULT_VARIANT_ID);
    const caseSlotId = getContentSlot(defaultCase)?.id;
    expect(caseSlotId).toBeTruthy();

    // The empty drop zone targets the case's content slot, not the switch's,
    // so dropped components land inside the active variant.
    const emptyZone = getDropZones().find(
      (zone) => zone.dataset.layerId === caseSlotId,
    );
    expect(emptyZone).toBeDefined();
    expect(emptyZone?.dataset.position).toBe('bottom');
    expect(emptyZone?.dataset.indent).toBe('2');
    expect(
      getDropZones().some(
        (zone) => zone.dataset.layerId === getContentSlot(switchNode)?.id,
      ),
    ).toBe(false);
  });
});
