import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';

import ComponentList from '@/components/list/ComponentList';

import type { ComponentsList } from '@/types/Component';

const queryMocks = vi.hoisted(() => ({
  components: vi.fn(),
  folders: vi.fn(),
}));

vi.mock('@/services/componentAndLayout', () => ({
  useGetComponentsQuery: queryMocks.components,
  useGetFoldersQuery: queryMocks.folders,
}));

vi.mock('react-error-boundary', () => ({
  useErrorBoundary: () => ({ showBoundary: vi.fn() }),
}));

vi.mock('@/components/list/ListItem', () => ({
  default: () => null,
}));

vi.mock('@/components/list/LibraryItemList', () => ({
  default: ({
    items,
  }: {
    items?: Record<string, { id: string; name: string }>;
  }) => (
    <div>
      {Object.values(items ?? {}).map((item) => (
        <span key={item.id}>{item.name}</span>
      ))}
    </div>
  ),
}));

const baseComponent = {
  default_markup: '',
  css: '',
  js_header: '',
  js_footer: '',
  version: '1',
  broken: false,
};

const components: ComponentsList = {
  external: {
    ...baseComponent,
    id: 'js.external',
    name: 'External component',
    library: 'primary_components',
    source: 'Code component',
    type: 'external',
    transforms: [],
  },
  react: {
    ...baseComponent,
    id: 'js.react',
    name: 'React component',
    library: 'primary_components',
    source: 'Code component',
    type: 'react',
    transforms: [],
  },
  sdc: {
    ...baseComponent,
    id: 'sdc.example',
    name: 'SDC component',
    library: 'elements',
    source: 'SDC',
    propSources: {},
    metadata: {},
    transforms: {},
  },
  block: {
    ...baseComponent,
    id: 'block.example',
    name: 'Block component',
    library: 'dynamic_components',
    source: 'Blocks',
  },
};

describe('ComponentList', () => {
  beforeEach(() => {
    queryMocks.components.mockReturnValue({
      data: components,
      error: undefined,
      isLoading: false,
    });
    queryMocks.folders.mockReturnValue({
      data: undefined,
      error: undefined,
      isLoading: false,
    });
  });

  it('shows all component sources by default', () => {
    render(<ComponentList searchTerm="" externalComponentsOnly={false} />);

    expect(screen.getByText('External component')).toBeInTheDocument();
    expect(screen.getByText('React component')).toBeInTheDocument();
    expect(screen.getByText('SDC component')).toBeInTheDocument();
    expect(screen.getByText('Block component')).toBeInTheDocument();
  });

  it('shows only external Code Components in configured headless mode', () => {
    render(<ComponentList searchTerm="" externalComponentsOnly={true} />);

    expect(screen.getByText('External component')).toBeInTheDocument();
    expect(screen.queryByText('React component')).not.toBeInTheDocument();
    expect(screen.queryByText('SDC component')).not.toBeInTheDocument();
    expect(screen.queryByText('Block component')).not.toBeInTheDocument();
  });
});
