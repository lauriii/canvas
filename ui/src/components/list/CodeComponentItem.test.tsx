import { forwardRef } from 'react';
import {
  MemoryRouter,
  Route,
  Routes,
  useLocation,
  useNavigate,
} from 'react-router';
import { describe, expect, it, vi } from 'vitest';
import { Theme } from '@radix-ui/themes';
import { act, fireEvent, screen } from '@testing-library/react';

import { makeStore } from '@/app/store';
import CodeComponentItem from '@/components/list/CodeComponentItem';
import {
  NodeType,
  setInitialLayoutModel,
  setLayoutModel,
} from '@/features/layout/layoutModelSlice';
import { setActivePanel } from '@/features/ui/primaryPanelSlice';
import { SelectedCodeComponentProvider } from '@/hooks/useSelectedCodeComponent';
import { renderWithProviders } from '@/utils/test-utils';

import type { RegionNode } from '@/features/layout/layoutModelSlice';
import type { JSComponent } from '@/types/Component';

const sidebarNodeRenders = vi.hoisted(() => vi.fn());

vi.mock('@/components/sidePanel/SidebarNode', () => ({
  default: forwardRef<
    HTMLDivElement,
    { title: string; selected?: boolean; dropdownMenuContent?: unknown }
  >(({ title, selected, dropdownMenuContent, ...props }, ref) => {
    void dropdownMenuContent;
    sidebarNodeRenders();
    return (
      <div ref={ref} data-selected={selected} {...props}>
        {title}
      </div>
    );
  }),
}));

vi.mock('@/utils/permissions', () => ({
  hasPermission: () => true,
  hasPermissions: () => true,
  hasAnyPermission: () => true,
}));

const component: JSComponent = {
  id: 'js.example',
  name: 'Example',
  library: 'primary_components',
  source: 'Code component',
  type: 'react',
  default_markup: '',
  css: '',
  js_header: '',
  js_footer: '',
  version: '1',
  broken: false,
  transforms: [],
};

const contentRegion = (types: string[]): RegionNode[] => [
  {
    nodeType: NodeType.Region,
    id: 'content',
    name: 'Content',
    components: types.map((type, index) => ({
      nodeType: NodeType.Component,
      uuid: `uuid-${index}`,
      type,
      slots: [],
    })),
  },
];

let navigate: ReturnType<typeof useNavigate>;

const Location = () => {
  navigate = useNavigate();
  return <div data-testid="path">{useLocation().pathname}</div>;
};

/**
 * Renders the item in the Library panel, inside the provider that the editor
 * renders around the panels.
 */
const renderItem = (path: string, initialEntry: string) => {
  const store = makeStore();
  store.dispatch(setActivePanel('library'));
  store.dispatch(
    setInitialLayoutModel({
      layout: contentRegion(['sdc.example.card@1']),
      model: {},
      updatePreview: false,
    }),
  );
  const shell = (
    <SelectedCodeComponentProvider>
      <Location />
      <Theme>
        <CodeComponentItem component={component} exposed={true} />
      </Theme>
    </SelectedCodeComponentProvider>
  );
  return renderWithProviders(
    <MemoryRouter initialEntries={[initialEntry]}>
      <Routes>
        <Route path={path} element={shell} />
      </Routes>
    </MemoryRouter>,
    { store },
  );
};

describe('CodeComponentItem', () => {
  it('does not re-render when the route or the layout changes', () => {
    const { store } = renderItem('*', '/editor/canvas_page/7');
    sidebarNodeRenders.mockClear();

    act(() => navigate('/editor/canvas_page/7/component/uuid-0'));
    act(() => {
      store.dispatch(
        setLayoutModel({
          layout: contentRegion(['sdc.example.card@1', 'js.example@1']),
          model: {},
          updatePreview: false,
        }),
      );
    });

    expect(sidebarNodeRenders).not.toHaveBeenCalled();
  });

  it('shows the component open in the code editor as selected', () => {
    renderItem(
      '/code-editor/component/:codeComponentId',
      '/code-editor/component/example',
    );
    expect(screen.getByText('Example')).toHaveAttribute(
      'data-selected',
      'true',
    );

    act(() => navigate('/code-editor/component/other'));
    expect(screen.getByText('Example')).toHaveAttribute(
      'data-selected',
      'false',
    );
  });

  it('reads the layout when a menu item is clicked', async () => {
    const { store } = renderItem('*', '/editor/canvas_page/7');
    act(() => {
      store.dispatch(
        setLayoutModel({
          layout: contentRegion(['js.example@1']),
          model: {},
          updatePreview: false,
        }),
      );
    });

    fireEvent.contextMenu(screen.getByText('Example'));
    fireEvent.click(
      await screen.findByRole('menuitem', { name: 'Remove from components' }),
    );

    expect(store.getState().codeComponentDialog.isInLayoutDialogOpen).toBe(
      true,
    );
  });

  it('opens the code editor from the "Edit code" menu item', async () => {
    renderItem('*', '/editor/canvas_page/7');

    fireEvent.contextMenu(screen.getByText('Example'));
    fireEvent.click(await screen.findByRole('menuitem', { name: 'Edit code' }));

    expect(screen.getByTestId('path')).toHaveTextContent(
      '/code-editor/component/example',
    );
  });
});
