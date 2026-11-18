import { useState } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { Theme } from '@radix-ui/themes';
import { fireEvent, render, screen } from '@testing-library/react';

import TemplatedContentGroups from '@/components/sidePanel/TemplatedContentGroups';

import type { ContentStub } from '@/types/Content';

const PAGE_SIZE = 2;
const TOTAL = 40;

const loadMore = vi.fn();

// Every loaded row is viewable but not editable (no edit-form link).
const viewOnlyRows = (count: number) =>
  Array.from({ length: count }, (_, index) => ({
    id: index + 1,
    title: `Article ${index + 1}`,
    status: true,
    links: {},
  })) as unknown as ContentStub[];

vi.mock('@/hooks/usePaginatedContentList', () => ({
  usePaginatedContentList: () => {
    const [loaded, setLoaded] = useState(PAGE_SIZE);
    return {
      items: viewOnlyRows(loaded),
      isLoading: false,
      error: undefined,
      hasMore: loaded < TOTAL,
      handleLoadMore: () => {
        loadMore();
        setLoaded((count) => count + PAGE_SIZE);
      },
    };
  },
}));

// Simulate an observer that is in view: it fires once per mount.
vi.mock('@/components/InfiniteScrollObserver', async () => {
  const { useEffect } = await import('react');
  return {
    default: ({ onLoadMore }: { onLoadMore?: () => void }) => {
      // eslint-disable-next-line react-hooks/exhaustive-deps
      useEffect(() => onLoadMore?.(), []);
      return null;
    },
  };
});

vi.mock('@assets/icons/folder.svg?react', () => ({
  default: () => null,
}));

vi.mock('react-router', () => ({ useParams: () => ({}) }));

vi.mock('@/hooks/useEditorNavigation', () => ({
  default: () => ({ navigateToEditor: vi.fn() }),
}));

describe('TemplatedContentGroups', () => {
  it('stops auto-loading pages without editable rows and offers a button', () => {
    render(
      <Theme>
        <TemplatedContentGroups
          groups={[{ entityType: 'node', title: 'Content', bundles: [] }]}
          searchTerm=""
        />
      </Theme>,
    );

    // The first load plus three more that each add no editable row.
    expect(loadMore).toHaveBeenCalledTimes(4);
    const button = screen.getByRole('button', { name: 'Load more' });

    fireEvent.click(button);
    // One manual load, then auto-loading resumes until the cap again.
    expect(loadMore).toHaveBeenCalledTimes(9);
    expect(
      screen.getByRole('button', { name: 'Load more' }),
    ).toBeInTheDocument();
  });
});
