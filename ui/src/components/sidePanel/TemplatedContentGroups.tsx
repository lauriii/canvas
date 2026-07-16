import { useCallback, useEffect, useState } from 'react';
import clsx from 'clsx';
import { useParams } from 'react-router-dom';
import FolderIcon from '@assets/icons/folder.svg?react';
import * as Collapsible from '@radix-ui/react-collapsible';
import { ChevronRightIcon } from '@radix-ui/react-icons';
import { Box, Flex, Skeleton, Text } from '@radix-ui/themes';

import EmptyStateCallout from '@/components/EmptyStateCallout';
import ErrorCard from '@/components/error/ErrorCard';
import InfiniteScrollObserver from '@/components/InfiniteScrollObserver';
import { PageListItem } from '@/components/pageInfo/PageList';
import { ListIndentContext } from '@/components/sidePanel/ListIndentContext';
import useEditorNavigation from '@/hooks/useEditorNavigation';
import { usePaginatedContentList } from '@/hooks/usePaginatedContentList';

import type { TemplatedEntityGroup } from '@/features/navigator/templatedContent';
import type { ContentStub } from '@/types/Content';

import listStyles from '@/components/list/List.module.css';

/**
 * One templated entity type's group, rendered as a collapsible content-type
 * folder: its entities (loaded from the per-entity type content list,
 * server-filtered to active-exposed-slot bundles and access checked), reusing
 * the pages row so selecting an entity opens it in the per-content editor.
 * Empty groups are hidden to keep the panel quiet, especially while searching.
 * Creating content is a single "Add new" control in the panel header, not here.
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

  // Report emptiness up, so the panel can show one aggregate empty state when
  // every group is empty (each empty group hides itself below).
  const isEmpty = !isLoading && !error && (items?.length ?? 0) === 0;
  useEffect(() => {
    onEmptyChange(group.entityType, isEmpty);
  }, [onEmptyChange, group.entityType, isEmpty]);

  if (isLoading) {
    return <Skeleton height="1.2rem" width="100%" my="3" />;
  }

  // Hide groups with no matching entities so the panel does not fill up with
  // empty bundle headings (especially while searching).
  if (isEmpty) {
    return null;
  }

  const handleSelect = (item: ContentStub) => {
    navigateToEditor(group.entityType, item.id);
  };

  return (
    <Collapsible.Root open={isOpen} onOpenChange={setIsOpen} asChild>
      <Box data-testid={`canvas-templated-content-${group.entityType}`}>
        <Flex align="center" className={listStyles.folderTrigger} pt="2" pb="2">
          <Collapsible.Trigger asChild>
            <Flex
              align="center"
              flexGrow="1"
              overflow="hidden"
              role="button"
              style={{ cursor: 'pointer', minWidth: 0 }}
            >
              <Flex pl="2" align="center" flexShrink="0">
                <FolderIcon className={listStyles.folderIcon} />
              </Flex>
              <Flex px="2" align="center" flexGrow="1" style={{ minWidth: 0 }}>
                <Text size="1" weight="medium" truncate>
                  {group.title}
                </Text>
              </Flex>
            </Flex>
          </Collapsible.Trigger>
          <Collapsible.Trigger asChild>
            <Flex
              px="2"
              align="center"
              flexShrink="0"
              role="button"
              aria-label={`${isOpen ? 'Collapse' : 'Expand'} ${group.title}`}
              style={{ cursor: 'pointer' }}
            >
              <ChevronRightIcon
                className={clsx(listStyles.chevron, {
                  [listStyles.isOpen]: isOpen,
                })}
              />
            </Flex>
          </Collapsible.Trigger>
        </Flex>
        <Collapsible.Content>
          {error && (
            <ErrorCard
              title="An unexpected error has occurred while loading content."
              error={String(error)}
            />
          )}
          {!error && (
            <ListIndentContext.Provider value={1}>
              <Flex direction="column" gap="1">
                {(items ?? []).map((item) => {
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
          {hasMore && <InfiniteScrollObserver onLoadMore={handleLoadMore} />}
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
