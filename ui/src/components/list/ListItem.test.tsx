import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';

import ListItem from '@/components/list/ListItem';
import { LayoutItemType } from '@/features/ui/primaryPanelSlice';
import { renderWithProviders } from '@/utils/test-utils';

import type { JSComponent } from '@/types/Component';

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
  default: () => null,
}));

vi.mock('@/components/list/PatternItem', () => ({
  default: () => null,
}));

vi.mock('@/hooks/useCanvasHeadlessSettings', () => ({
  useCanvasHeadlessSettings: () => ({
    frontendUrl: 'https://frontend.example',
    frontends: ['https://frontend.example'],
    frontendOrigin: 'https://frontend.example',
    draftUrl: 'https://frontend.example/api/draft',
    assertionUrl: '/canvas-headless/assertion',
  }),
}));

vi.mock('@/hooks/useComponentSelection', () => ({
  default: () => ({ setSelectedComponent: vi.fn() }),
}));

const component: JSComponent = {
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

describe('ListItem', () => {
  it('shows an app preview for an external component without default markup', async () => {
    const { user } = renderWithProviders(
      <MemoryRouter initialEntries={['/editor/canvas_page/7']}>
        <ListItem item={component} type={LayoutItemType.COMPONENT} />
      </MemoryRouter>,
    );

    await user.hover(screen.getByText('Example'));

    expect(await screen.findByText('Example app preview')).toBeInTheDocument();
  });
});
