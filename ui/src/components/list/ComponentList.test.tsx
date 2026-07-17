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
    name: 'Metadata-only external component',
    library: 'primary_components',
    source: 'Code component',
    type: 'external',
    hasFallbackImplementation: false,
    transforms: [],
  },
  convertedExternal: {
    ...baseComponent,
    id: 'js.converted_external',
    name: 'Converted external component',
    library: 'primary_components',
    source: 'Code component',
    type: 'external',
    hasFallbackImplementation: true,
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
  extension: {
    ...baseComponent,
    id: 'extension.example',
    name: 'Extension component',
    library: 'extension_components',
    source: 'Extension',
    propSources: {},
    metadata: {},
    transforms: {},
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
    render(<ComponentList searchTerm="" visibility="all" />);

    expect(
      screen.getByText('Metadata-only external component'),
    ).toBeInTheDocument();
    expect(
      screen.getByText('Converted external component'),
    ).toBeInTheDocument();
    expect(screen.getByText('React component')).toBeInTheDocument();
    expect(screen.getByText('SDC component')).toBeInTheDocument();
    expect(screen.getByText('Block component')).toBeInTheDocument();
    expect(screen.getByText('Extension component')).toBeInTheDocument();
  });

  it('shows only external Code Components in configured headless mode', () => {
    render(<ComponentList searchTerm="" visibility="external-only" />);

    expect(
      screen.getByText('Metadata-only external component'),
    ).toBeInTheDocument();
    expect(
      screen.getByText('Converted external component'),
    ).toBeInTheDocument();
    expect(screen.queryByText('React component')).not.toBeInTheDocument();
    expect(screen.queryByText('SDC component')).not.toBeInTheDocument();
    expect(screen.queryByText('Block component')).not.toBeInTheDocument();
    expect(screen.queryByText('Extension component')).not.toBeInTheDocument();
  });

  it('shows only standard components without headless preview access', () => {
    render(<ComponentList searchTerm="" visibility="non-external-only" />);

    expect(
      screen.queryByText('Metadata-only external component'),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByText('Converted external component'),
    ).not.toBeInTheDocument();
    expect(screen.getByText('React component')).toBeInTheDocument();
    expect(screen.getByText('SDC component')).toBeInTheDocument();
    expect(screen.getByText('Block component')).toBeInTheDocument();
    expect(screen.getByText('Extension component')).toBeInTheDocument();
  });

  it('shows standard and converted components in fallback mode', () => {
    render(
      <ComponentList
        searchTerm=""
        visibility="non-external-and-fallback-external"
      />,
    );

    expect(
      screen.queryByText('Metadata-only external component'),
    ).not.toBeInTheDocument();
    expect(
      screen.getByText('Converted external component'),
    ).toBeInTheDocument();
    expect(screen.getByText('React component')).toBeInTheDocument();
    expect(screen.getByText('SDC component')).toBeInTheDocument();
    expect(screen.getByText('Block component')).toBeInTheDocument();
    expect(screen.getByText('Extension component')).toBeInTheDocument();
  });
});
