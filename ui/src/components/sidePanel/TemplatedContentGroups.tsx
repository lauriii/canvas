import { useCallback, useEffect, useRef, useState } from 'react';
import clsx from 'clsx';
import { useParams } from 'react-router';
import FolderIcon from '@assets/icons/folder.svg?react';
import * as Collapsible from '@radix-ui/react-collapsible';
import { ChevronRightIcon } from '@radix-ui/react-icons';
import { Box, Button, Flex, Skeleton, Text } from '@radix-ui/themes';

import EmptyStateCallout from '@/components/EmptyStateCallout';
import ErrorCard from '@/components/error/ErrorCard';
import InfiniteScrollObserver from '@/components/InfiniteScrollObserver';
import { PageListItem } from '@/components/pageInfo/PageList';
import { ListIndentContext } from '@/components/sidePanel/ListIndentContext';
import useEditorNavigation from '@/hooks/useEditorNavigation';
import { usePaginatedContentList } from '@/hooks/usePaginatedContentList';
import { getQueryErrorMessage } from '@/utils/error-handling';

import type { TemplatedEntityGroup } from '@/features/navigator/templatedContent';
import type { ContentStub } from '@/types/Content';

import listStyles from '@/components/list/List.module.css';

// The list is access-checked for viewing, but only editable rows are shown.
// A user who may view but not edit much of a large bundle would otherwise page
// through all of it: after this many pages in a row without a new editable
// row, loading continues only on demand.
const MAX_FRUITLESS_AUTO_LOADS = 3;

/**
 * One templated entity type's entities, as a collapsible folder. Selecting an
 * entity opens it in the per-content editor. Empty groups are hidden.
 */
const TemplatedContentGroup = ({
  group,
  searchTerm,
  onEmptyChange,
}: {
  group: TemplatedEntityGroup;
  searchTerm: string;
  onEmptyChange: (entityType: string, isEmpty: boolean) => void;
}) => {
  const { entityType: routeEntityType, entityId } = useParams();
  const { navigateToEditor } = useEditorNavigation();
  const [isOpen, setIsOpen] = useState(true);
  const { items, isLoading, error, hasMore, handleLoadMore } =
    usePaginatedContentList(group.entityType, searchTerm);
  const [fruitlessLoads, setFruitlessLoads] = useState(0);
  const editableCountAtLastLoad = useRef<number | null>(null);

  // The list is access-checked for viewing, but a row's only action is
  // opening the per-content editor, so show only entities the user may edit
  // (the server adds the edit-form link exactly when update access is
  // allowed).
  const editableItems = (items ?? []).filter(
    (item) => item.links?.['edit-form'],
  );

  // Report emptiness up, for the panel's single empty state. A group is only
  // empty once pagination is exhausted: later pages may hold editable rows.
  const isEmpty =
    !isLoading && !error && editableItems.length === 0 && !hasMore;
  useEffect(() => {
    onEmptyChange(group.entityType, isEmpty);
  }, [onEmptyChange, group.entityType, isEmpty]);

  // A new search starts a new list. (Not on mount: the observer's effect runs
  // first and may already have loaded a page.)
  const previousSearchTerm = useRef(searchTerm);
  useEffect(() => {
    if (previousSearchTerm.current !== searchTerm) {
      previousSearchTerm.current = searchTerm;
      setFruitlessLoads(0);
      editableCountAtLastLoad.current = null;
    }
  }, [searchTerm]);

  const handleAutoLoad = () => {
    const editableCount = editableItems.length;
    const isFruitless = editableCountAtLastLoad.current === editableCount;
    setFruitlessLoads((count) => (isFruitless ? count + 1 : 0));
    editableCountAtLastLoad.current = editableCount;
    handleLoadMore();
  };
  const handleManualLoad = () => {
    setFruitlessLoads(0);
    editableCountAtLastLoad.current = null;
    handleLoadMore();
  };
  const canAutoLoad = fruitlessLoads < MAX_FRUITLESS_AUTO_LOADS;
  // Remounting the observer after each loaded page makes it fire again while
  // still in view, i.e. when the page added no visible rows.
  const loadedCount = items?.length ?? 0;

  if (isLoading) {
    return <Skeleton height="1.2rem" width="100%" my="3" />;
  }

  // Hide groups with no matching entities so the panel does not fill up with
  // empty bundle headings (especially while searching).
  if (isEmpty) {
    return null;
  }

  // No editable rows yet, but more pages remain: render only the observer, so
  // it keeps loading. Errors and stopped auto-loading render below instead.
  if (!error && editableItems.length === 0 && canAutoLoad) {
    return (
      <InfiniteScrollObserver key={loadedCount} onLoadMore={handleAutoLoad} />
    );
  }

  const handleSelect = (item: ContentStub) => {
    navigateToEditor(group.entityType, item.id);
  };

  return (
    <Collapsible.Root open={isOpen} onOpenChange={setIsOpen} asChild>
      <Box data-testid={`canvas-templated-content-${group.entityType}`}>
        <Flex align="center" className={listStyles.folderTrigger} pt="2" pb="2">
          {/* One native button spanning the whole row: keyboard focusable, and
              Radix adds aria-expanded. The visible title labels it. */}
          <Collapsible.Trigger asChild>
            <button type="button" className={listStyles.folderButton}>
              <Flex pl="2" align="center" flexShrink="0">
                <FolderIcon className={listStyles.folderIcon} />
              </Flex>
              <Flex px="2" align="center" flexGrow="1" style={{ minWidth: 0 }}>
                <Text size="1" weight="medium" truncate>
                  {group.title}
                </Text>
              </Flex>
              <Flex px="2" align="center" flexShrink="0">
                <ChevronRightIcon
                  className={clsx(listStyles.chevron, {
                    [listStyles.isOpen]: isOpen,
                  })}
                />
              </Flex>
            </button>
          </Collapsible.Trigger>
        </Flex>
        <Collapsible.Content>
          {error && (
            <ErrorCard
              title="An unexpected error has occurred while loading content."
              error={getQueryErrorMessage(error)}
            />
          )}
          {!error && (
            <ListIndentContext.Provider value={1}>
              <Flex direction="column" gap="1">
                {editableItems.map((item) => {
                  const isSelected =
                    routeEntityType === group.entityType &&
                    String(entityId) === String(item.id);
                  return (
                    <PageListItem
                      key={`${item.id}-${item.status}`}
                      item={item}
                      isSelected={isSelected}
                      isHomepage={false}
                      onSelect={handleSelect}
                    />
                  );
                })}
              </Flex>
            </ListIndentContext.Provider>
          )}
          {hasMore &&
            (canAutoLoad ? (
              <InfiniteScrollObserver
                key={loadedCount}
                onLoadMore={handleAutoLoad}
              />
            ) : (
              <Flex px="2" py="1">
                <Button size="1" variant="ghost" onClick={handleManualLoad}>
                  Load more
                </Button>
              </Flex>
            ))}
        </Collapsible.Content>
      </Box>
    </Collapsible.Root>
  );
};

/**
 * Renders the Content panel's templated-entity groups (entities of templated
 * bundles with active exposed slots), one collapsible section per entity type.
 * The single shared search term filters every group.
 */
const TemplatedContentGroups = ({
  groups,
  searchTerm,
}: {
  groups: TemplatedEntityGroup[];
  searchTerm: string;
}) => {
  const [emptyGroups, setEmptyGroups] = useState<Record<string, boolean>>({});
  const handleEmptyChange = useCallback(
    (entityType: string, isEmpty: boolean) => {
      setEmptyGroups((previous) =>
        previous[entityType] === isEmpty
          ? previous
          : { ...previous, [entityType]: isEmpty },
      );
    },
    [],
  );
  if (groups.length === 0) {
    return null;
  }
  // Every group hides itself when empty; show one aggregate empty state so
  // the panel is never silently blank (e.g. a search with zero results).
  const allEmpty = groups.every(
    (group) => emptyGroups[group.entityType] === true,
  );
  return (
    <>
      {allEmpty && (
        <EmptyStateCallout title="No content found" variant="surface" />
      )}
      {groups.map((group) => (
        <TemplatedContentGroup
          key={group.entityType}
          group={group}
          searchTerm={searchTerm}
          onEmptyChange={handleEmptyChange}
        />
      ))}
    </>
  );
};

export default TemplatedContentGroups;
