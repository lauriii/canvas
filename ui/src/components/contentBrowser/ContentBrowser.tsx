import { useCallback, useEffect, useMemo, useState } from 'react';
import {
  ArrowDownIcon,
  ArrowUpIcon,
  CaretSortIcon,
  ChevronDownIcon,
  DotsHorizontalIcon,
  MagnifyingGlassIcon,
  PlusIcon,
} from '@radix-ui/react-icons';
import {
  Badge,
  Box,
  Button,
  DropdownMenu,
  Flex,
  Heading,
  IconButton,
  Select,
  Spinner,
  Table,
  Text,
  TextField,
} from '@radix-ui/themes';

import {
  DEFAULT_CONTENT_SORT,
  formatContentDate,
  getContentListSources,
  getContentTypeFilterOptions,
  getCreateContentOptions,
  getDisplayTitle,
  getNextSort,
  getSourceKey,
  getStatusBadge,
  mergeContentLists,
  paginateContentItems,
  sortContentItems,
  toApiSort,
} from '@/components/contentBrowser/contentBrowserHelpers';
import EmptyStateCallout from '@/components/EmptyStateCallout';
import ErrorCard from '@/components/error/ErrorCard';
import {
  getTemplatedEntityGroups,
  PAGE_ENTITY_TYPE,
} from '@/features/navigator/templatedContent';
import useDebounce from '@/hooks/useDebounce';
import useEditorNavigation from '@/hooks/useEditorNavigation';
import { useGetContentTemplatesQuery } from '@/services/componentAndLayout';
import {
  useCreateContentMutation,
  useGetContentListQuery,
} from '@/services/content';
import { getCanvasSettings } from '@/utils/drupal-globals';

import type {
  ContentListSource,
  ContentSort,
  ContentSortKey,
  CreateContentOption,
} from '@/components/contentBrowser/contentBrowserHelpers';
import type { ContentStub } from '@/types/Content';

import styles from '@/components/contentBrowser/ContentBrowser.module.css';

/** A stable empty list so fetcher results compare equal across renders. */
const EMPTY_ITEMS: ContentStub[] = [];

/** One source's reported query state. */
interface SourceResult {
  entityType: string;
  items: ContentStub[];
  isLoading: boolean;
  hasError: boolean;
}

/**
 * A headless per-source fetcher. The set of listed entity types is dynamic
 * (Canvas pages plus whatever is templated), and hooks cannot run in a loop
 * over a dynamic list, so the browser mounts one of these per source and each
 * reports its query state up for merging.
 */
const ContentListFetcher = ({
  source,
  search,
  sort,
  onResult,
}: {
  source: ContentListSource;
  search: string;
  sort: string;
  onResult: (key: string, result: SourceResult) => void;
}) => {
  const { data, isLoading, error } = useGetContentListQuery({
    entityType: source.entityType,
    bundle: source.bundle,
    search: search || undefined,
    sort,
  });
  const items = data?.items ?? EMPTY_ITEMS;
  const hasError = error !== undefined;
  const sourceKey = getSourceKey(source);
  const { entityType } = source;
  useEffect(() => {
    onResult(sourceKey, { entityType, items, isLoading, hasError });
  }, [onResult, sourceKey, entityType, items, isLoading, hasError]);
  return null;
};

const SortableColumnHeader = ({
  label,
  sortKey,
  sort,
  onSort,
}: {
  label: string;
  sortKey: ContentSortKey;
  sort: ContentSort;
  onSort: (key: ContentSortKey) => void;
}) => {
  const isActive = sort.key === sortKey;
  const ariaSort = isActive
    ? sort.direction === 'asc'
      ? 'ascending'
      : 'descending'
    : undefined;
  return (
    <Table.ColumnHeaderCell aria-sort={ariaSort}>
      <button
        type="button"
        className={styles.sortButton}
        onClick={() => onSort(sortKey)}
      >
        {label}
        {isActive ? (
          sort.direction === 'asc' ? (
            <ArrowUpIcon />
          ) : (
            <ArrowDownIcon />
          )
        ) : (
          <CaretSortIcon />
        )}
      </button>
    </Table.ColumnHeaderCell>
  );
};

const ContentBrowserRow = ({
  item,
  onOpen,
}: {
  item: ContentStub;
  onOpen: (item: ContentStub) => void;
}) => {
  // The presence of the edit-form link is the access gate: rows without it
  // render without any navigation affordance.
  const canOpen = Boolean(item.links['edit-form']);
  const hasActions = canOpen || Boolean(item.path);
  const badge = getStatusBadge(item);
  return (
    <Table.Row
      data-testid={`canvas-content-browser-row-${item.entityType ?? ''}-${item.id}`}
      className={canOpen ? styles.clickableRow : undefined}
      onClick={canOpen ? () => onOpen(item) : undefined}
    >
      <Table.Cell>
        <Flex align="center" gap="2">
          <Text weight="medium">{getDisplayTitle(item)}</Text>
          {/* An auto-saved label means unsaved draft changes; new entities
              already carry the Draft status badge, so skip the second marker. */}
          {item.autoSaveLabel !== null && !item.isNew && (
            <Badge color="amber" variant="soft">
              Draft
            </Badge>
          )}
          <Badge color={badge.color} variant="soft">
            {badge.label}
          </Badge>
        </Flex>
      </Table.Cell>
      <Table.Cell>{item.bundleLabel ?? '—'}</Table.Cell>
      <Table.Cell>{item.authorName ?? '—'}</Table.Cell>
      <Table.Cell>{formatContentDate(item.created)}</Table.Cell>
      <Table.Cell>{formatContentDate(item.changed)}</Table.Cell>
      <Table.Cell onClick={(event) => event.stopPropagation()}>
        {hasActions && (
          <DropdownMenu.Root>
            <DropdownMenu.Trigger>
              <IconButton
                variant="ghost"
                color="gray"
                aria-label={`Actions for ${getDisplayTitle(item)}`}
              >
                <DotsHorizontalIcon />
              </IconButton>
            </DropdownMenu.Trigger>
            <DropdownMenu.Content align="end">
              {canOpen && (
                <DropdownMenu.Item onSelect={() => onOpen(item)}>
                  Open in editor
                </DropdownMenu.Item>
              )}
              {Boolean(item.path) && (
                <DropdownMenu.Item
                  onSelect={() =>
                    window.open(item.path, '_blank', 'noopener,noreferrer')
                  }
                >
                  View page
                </DropdownMenu.Item>
              )}
            </DropdownMenu.Content>
          </DropdownMenu.Root>
        )}
      </Table.Cell>
    </Table.Row>
  );
};

/**
 * The full-page content browser ("Canvas / Content"): a searchable, sortable,
 * filterable table of all Canvas-editable content. Lists Canvas pages plus
 * every templated entity type, merged client-side (see the helpers module for
 * the v1 pagination limitation) and paginated 25 per page. Selecting a row
 * opens the entity in the editor.
 */
const ContentBrowser = () => {
  const { navigateToEditor } = useEditorNavigation();
  const [searchTerm, setSearchTerm] = useState('');
  const debouncedSearch: string = useDebounce(searchTerm, 300);
  const [filterValue, setFilterValue] = useState('all');
  const [sort, setSort] = useState(DEFAULT_CONTENT_SORT);
  const [page, setPage] = useState(0);
  const [results, setResults] = useState<Record<string, SourceResult>>({});

  const {
    data: contentTemplates,
    isLoading: isTemplatesLoading,
    error: templatesError,
  } = useGetContentTemplatesQuery();
  const groups = useMemo(
    () => getTemplatedEntityGroups(contentTemplates),
    [contentTemplates],
  );
  const filterOptions = useMemo(
    () => getContentTypeFilterOptions(groups),
    [groups],
  );
  const activeFilter =
    filterOptions.find((option) => option.value === filterValue) ?? null;
  const sources = useMemo(
    () => getContentListSources(groups, activeFilter),
    [groups, activeFilter],
  );

  const handleResult = useCallback((key: string, result: SourceResult) => {
    setResults((current) => {
      const existing = current[key];
      if (
        existing &&
        existing.items === result.items &&
        existing.isLoading === result.isLoading &&
        existing.hasError === result.hasError
      ) {
        return current;
      }
      return { ...current, [key]: result };
    });
  }, []);

  // Only the currently listed sources contribute; results left over from a
  // previous filter selection are simply not read.
  const sourceResults = sources.map((source) => results[getSourceKey(source)]);
  const isListLoading =
    isTemplatesLoading ||
    sourceResults.some((result) => !result || result.isLoading);
  const hasSourceError = sourceResults.some((result) => result?.hasError);

  const sortedItems = useMemo(() => {
    const merged = mergeContentLists(
      sources.map((source) => ({
        entityType: source.entityType,
        items: results[getSourceKey(source)]?.items ?? EMPTY_ITEMS,
      })),
    );
    return sortContentItems(merged, sort);
  }, [sources, results, sort]);
  const {
    pageItems,
    pageCount,
    page: currentPage,
    totalCount,
  } = paginateContentItems(sortedItems, page);

  const createOptions = getCreateContentOptions(
    getCanvasSettings()?.contentEntityCreateOperations,
  );
  const [
    createContent,
    {
      data: createData,
      isSuccess: isCreateSuccess,
      isLoading: isCreating,
      error: createError,
    },
  ] = useCreateContentMutation();

  useEffect(() => {
    if (isCreateSuccess && createData) {
      navigateToEditor(createData.entity_type, createData.entity_id);
    }
  }, [isCreateSuccess, createData, navigateToEditor]);

  const handleCreate = (option: CreateContentOption) => {
    createContent(
      option.entityType === PAGE_ENTITY_TYPE
        ? { entity_type: PAGE_ENTITY_TYPE }
        : { entity_type: option.entityType, bundle: option.bundle },
    );
  };

  const handleOpen = useCallback(
    (item: ContentStub) => {
      navigateToEditor(item.entityType, item.id);
    },
    [navigateToEditor],
  );

  const handleSort = (key: ContentSortKey) => {
    setSort((current) => getNextSort(current, key));
    setPage(0);
  };

  const handleSearchChange = (value: string) => {
    setSearchTerm(value);
    setPage(0);
  };

  const handleFilterChange = (value: string) => {
    setFilterValue(value);
    setPage(0);
  };

  let body: React.ReactNode;
  if (templatesError) {
    body = (
      <ErrorCard
        title="An unexpected error has occurred while loading content."
        error={String(templatesError)}
      />
    );
  } else if (isListLoading) {
    body = (
      <Flex width="100%" justify="center" py="8">
        <Spinner size="3" loading={true} />
      </Flex>
    );
  } else if (totalCount === 0) {
    body = <EmptyStateCallout title="No content found" variant="surface" />;
  } else {
    body = (
      <>
        <Table.Root variant="surface" size="2">
          <Table.Header>
            <Table.Row>
              <SortableColumnHeader
                label="Title"
                sortKey="title"
                sort={sort}
                onSort={handleSort}
              />
              <Table.ColumnHeaderCell>Type</Table.ColumnHeaderCell>
              <Table.ColumnHeaderCell>Author</Table.ColumnHeaderCell>
              <SortableColumnHeader
                label="Created"
                sortKey="created"
                sort={sort}
                onSort={handleSort}
              />
              <SortableColumnHeader
                label="Updated"
                sortKey="changed"
                sort={sort}
                onSort={handleSort}
              />
              <Table.ColumnHeaderCell aria-label="Actions" />
            </Table.Row>
          </Table.Header>
          <Table.Body>
            {pageItems.map((item) => (
              <ContentBrowserRow
                key={`${item.entityType}-${item.id}`}
                item={item}
                onOpen={handleOpen}
              />
            ))}
          </Table.Body>
        </Table.Root>
        <Flex align="center" justify="between" mt="3" gap="3">
          <Text size="1" color="gray">
            {totalCount === 1 ? '1 item' : `${totalCount} items`}
          </Text>
          {pageCount > 1 && (
            <Flex align="center" gap="3">
              <Button
                variant="soft"
                size="1"
                disabled={currentPage === 0}
                onClick={() => setPage(currentPage - 1)}
              >
                Previous
              </Button>
              <Text size="1" color="gray">
                Page {currentPage + 1} of {pageCount}
              </Text>
              <Button
                variant="soft"
                size="1"
                disabled={currentPage >= pageCount - 1}
                onClick={() => setPage(currentPage + 1)}
              >
                Next
              </Button>
            </Flex>
          )}
        </Flex>
      </>
    );
  }

  return (
    <Box
      width="100%"
      className={styles.page}
      data-testid="canvas-content-browser"
    >
      {sources.map((source) => (
        <ContentListFetcher
          key={getSourceKey(source)}
          source={source}
          search={debouncedSearch}
          sort={toApiSort(sort)}
          onResult={handleResult}
        />
      ))}
      <Box maxWidth="1100px" mx="auto" px="6" py="6">
        <Flex direction="column" gap="4">
          <Flex justify="between" align="center" gap="3">
            <Heading as="h1" size="5">
              Content
            </Heading>
            {createOptions.length > 0 && (
              <DropdownMenu.Root>
                <DropdownMenu.Trigger>
                  <Button
                    data-testid="canvas-content-browser-create"
                    disabled={isCreating}
                  >
                    <PlusIcon />
                    Create content
                    <ChevronDownIcon />
                  </Button>
                </DropdownMenu.Trigger>
                <DropdownMenu.Content align="end">
                  {createOptions.map((option) => (
                    <DropdownMenu.Item
                      key={`${option.entityType}:${option.bundle}`}
                      onSelect={() => handleCreate(option)}
                    >
                      {option.label}
                    </DropdownMenu.Item>
                  ))}
                </DropdownMenu.Content>
              </DropdownMenu.Root>
            )}
          </Flex>
          {createError !== undefined && (
            <ErrorCard
              title="The content could not be created."
              error={String(createError)}
            />
          )}
          <Flex gap="2" align="center" wrap="wrap">
            <Box width="260px">
              <TextField.Root
                autoComplete="off"
                placeholder="Search content"
                aria-label="Search content"
                value={searchTerm}
                onChange={(event) => handleSearchChange(event.target.value)}
              >
                <TextField.Slot>
                  <MagnifyingGlassIcon height="16" width="16" />
                </TextField.Slot>
              </TextField.Root>
            </Box>
            <Select.Root value={filterValue} onValueChange={handleFilterChange}>
              <Select.Trigger aria-label="Filter by content type" />
              <Select.Content>
                <Select.Item value="all">All content</Select.Item>
                {filterOptions.map((option) => (
                  <Select.Item key={option.value} value={option.value}>
                    {option.label}
                  </Select.Item>
                ))}
              </Select.Content>
            </Select.Root>
          </Flex>
          {hasSourceError && (
            <Text size="1" color="red">
              Some content could not be loaded.
            </Text>
          )}
          {body}
        </Flex>
      </Box>
    </Box>
  );
};

export default ContentBrowser;
