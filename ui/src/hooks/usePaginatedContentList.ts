import { useEffect, useState } from 'react';

import useDebounce from '@/hooks/useDebounce';
import { useGetContentListQuery } from '@/services/content';

export const usePaginatedContentList = (
  entityType: string,
  searchTerm: string,
) => {
  const [offset, setOffset] = useState(0);
  const debouncedSearchTerm = useDebounce(searchTerm, 300);

  const { data, isLoading, error, isSuccess } = useGetContentListQuery({
    entityType,
    search: debouncedSearchTerm,
    offset,
  });
  const items = data?.items;
  const totalCount = data?.totalCount;

  // Reset offset when search changes.
  useEffect(() => {
    setOffset(0);
  }, [debouncedSearchTerm]);

  const hasMore =
    !debouncedSearchTerm &&
    totalCount !== null &&
    (items?.length ?? 0) < (totalCount ?? 0);

  function handleLoadMore() {
    setOffset(items?.length ?? 0);
  }

  return {
    items,
    totalCount,
    isLoading,
    error,
    isSuccess,
    hasMore,
    handleLoadMore,
    debouncedSearchTerm,
  };
};
