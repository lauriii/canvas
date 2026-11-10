import { MemoryRouter, useLocation, useNavigate } from 'react-router';
import { describe, expect, it, vi } from 'vitest';
import { act, screen } from '@testing-library/react';

import { makeStore } from '@/app/store';
import ListItem from '@/components/list/ListItem';
import {
  NodeType,
  selectLayout,
  setInitialLayoutModel,
} from '@/features/layout/layoutModelSlice';
import { LayoutItemType } from '@/features/ui/primaryPanelSlice';
import { setSelection } from '@/features/ui/uiSlice';
import { StableComponentSelectionProvider } from '@/hooks/useStableComponentSelection';
import { renderWithProviders } from '@/utils/test-utils';

import type { ReactNode } from 'react';
import type { CanvasComponent, JSComponent } from '@/types/Component';

const componentItemRenders = vi.hoisted(() => vi.fn());

vi.mock('@/components/ComponentPreview', () => ({
  default: () => null,
}));

vi.mock('@/components/HeadlessComponentPreview', () => ({
  default: ({ component }: { component: JSComponent }) => (
    <div>{component.name} app preview</div>
  ),
}));

vi.mock('@/components/list/CodeComponentItem', () => ({
  default: ({ component }: { component: JSComponent }) => (
    <div>{component.name}</div>
  ),
}));

vi.mock('@/components/list/ComponentItem', () => ({
  default: ({ insertMenuItem }: { insertMenuItem: ReactNode }) => {
    componentItemRenders();
    return <>{insertMenuItem}</>;
  },
}));

vi.mock('@/components/list/PatternItem', () => ({
  default: () => null,
}));

// Render menu items as plain buttons, without a Radix menu around them.
vi.mock('@/components/UnifiedMenu', () => {
  const UnifiedMenu = {
    Item: ({
      children,
      onClick,
      disabled,
    }: {
      children: ReactNode;
      onClick: () => void;
      disabled?: boolean;
    }) => (
      <button type="button" onClick={onClick} disabled={disabled}>
        {children}
      </button>
    ),
    Label: () => null,
    Separator: () => null,
  };
  return { default: UnifiedMenu, UnifiedMenu };
});

vi.mock('@/hooks/useCanvasHeadlessSettings', () => ({
  useCanvasHeadlessSettings: () => ({
    frontendUrl: 'https://frontend.example',
    frontends: ['https://frontend.example'],
    frontendOrigin: 'https://frontend.example',
    draftUrl: 'https://frontend.example/api/draft',
    assertionUrl: '/canvas-headless/assertion',
  }),
}));

const jsComponent: JSComponent = {
  id: 'js.example',
  name: 'Example',
  library: 'primary_components',
  source: 'Code component',
  type: 'external',
  default_markup: '',
  css: '',
  js_header: '',
  js_footer: '',
  version: '1',
  broken: false,
  transforms: [],
};

const blockComponent: CanvasComponent = {
  id: 'block.example',
  name: 'Block component',
  library: 'dynamic_components',
  source: 'Blocks',
  default_markup: '',
  css: '',
  js_header: '',
  js_footer: '',
  version: '1',
  broken: false,
};

const componentNode = (uuid: string) => ({
  nodeType: NodeType.Component as const,
  uuid,
  type: 'block.example@1',
  slots: [],
});

let navigate: ReturnType<typeof useNavigate>;

const Location = () => {
  navigate = useNavigate();
  return <div data-testid="path">{useLocation().pathname}</div>;
};

/**
 * Renders a ListItem the way the editor does: inside the router and the
 * stable selection provider, with a content region holding two components.
 */
const renderListItem = (item: CanvasComponent | JSComponent) => {
  const store = makeStore();
  store.dispatch(
    setInitialLayoutModel({
      layout: [
        {
          nodeType: NodeType.Region,
          id: 'content',
          name: 'Content',
          components: [componentNode('uuid-a'), componentNode('uuid-b')],
        },
      ],
      model: {},
      updatePreview: false,
    }),
  );
  return renderWithProviders(
    <MemoryRouter initialEntries={['/editor/canvas_page/7']}>
      <StableComponentSelectionProvider>
        <Location />
        <ListItem item={item} type={LayoutItemType.COMPONENT} />
      </StableComponentSelectionProvider>
    </MemoryRouter>,
    { store },
  );
};

describe('ListItem', () => {
  it('shows an app preview for an external component without default markup', async () => {
    const { user } = renderListItem(jsComponent);

    await user.hover(screen.getByText('Example'));

    expect(await screen.findByText('Example app preview')).toBeInTheDocument();
  });

  it('does not re-render when the selection or the route changes', () => {
    const { store } = renderListItem(blockComponent);
    componentItemRenders.mockClear();

    act(() => {
      store.dispatch(setSelection({ items: ['uuid-a'] }));
    });
    act(() => navigate('/editor/canvas_page/7/component/uuid-a'));
    act(() => {
      store.dispatch(setSelection({ items: [] }));
    });
    act(() => navigate('/editor/canvas_page/7'));

    expect(componentItemRenders).not.toHaveBeenCalled();
  });

  it('inserts after the selected component and selects the new one', async () => {
    const { store, user } = renderListItem(blockComponent);
    act(() => {
      store.dispatch(setSelection({ items: ['uuid-a'] }));
    });

    await user.click(screen.getByRole('button', { name: 'Insert' }));

    const [region] = selectLayout(store.getState());
    expect(region.components.map((node) => node.uuid)).toEqual([
      'uuid-a',
      expect.any(String),
      'uuid-b',
    ]);
    const newUuid = region.components[1].uuid;
    expect(store.getState().ui.selection.items).toEqual([newUuid]);
    expect(screen.getByTestId('path')).toHaveTextContent(
      `/editor/canvas_page/7/component/${newUuid}`,
    );
  });
});
