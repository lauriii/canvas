import { useEffect, useState } from 'react';

import useDebounce from '@/hooks/useDebounce';
import { useGetContentListQuery } from '@/services/content';

export const usePaginatedContentList = (
  entityType: string,
  searchTerm: string,
  // A language: the content that exists in it is listed first.
  langcode?: string,
  // Waits, for example until the language is known.
  skip = false,
) => {
  const [offset, setOffset] = useState(0);
  const debouncedSearchTerm = useDebounce(searchTerm, 300);

  const { data, isLoading, error, isSuccess } = useGetContentListQuery(
    {
      entityType,
      search: debouncedSearchTerm,
      offset,
      langcode,
    },
    { skip },
  );
  const items = data?.items;
  const totalCount = data?.totalCount;

  // Reset offset when the search or the language changes.
  useEffect(() => {
    setOffset(0);
  }, [debouncedSearchTerm, langcode]);

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
    // Waiting counts as loading.
    isLoading: isLoading || skip,
    error,
    isSuccess,
    hasMore,
    handleLoadMore,
    debouncedSearchTerm,
  };
};
