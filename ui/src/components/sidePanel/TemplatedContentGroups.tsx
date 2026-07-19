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
 * server-filtered to enabled-template bundles and access checked), reusing
 * the pages row so selecting an entity opens it in the per-content editor.
 * Empty groups are hidden to keep the panel quiet, especially while searching.
 * Creating content is a single "Add new" control in the panel header, not here.
 */
const TemplatedContentGroup = ({
  group,
  bundle,
  bundleLabel,
  searchTerm,
  onEmptyChange,
}: {
  group: TemplatedEntityGroup;
  bundle: string;
  bundleLabel: string;
  searchTerm: string;
  onEmptyChange: (groupKey: string, isEmpty: boolean) => void;
}) => {
  const { entityType: routeEntityType, entityId } = useParams();
  const { navigateToEditor } = useEditorNavigation();
  const [isOpen, setIsOpen] = useState(true);
  const { items, isLoading, error, hasMore, handleLoadMore } =
    usePaginatedContentList(group.entityType, searchTerm, bundle);
  const groupKey = `${group.entityType}:${bundle}`;

  // The list is access-checked for viewing, but a row's only action is
  // opening the per-content editor, so show only entities the user may edit
  // (the server adds the edit-form link exactly when update access is
  // allowed).
  const editableItems = (items ?? []).filter(
    (item) => !!item.links?.['edit-form'],
  );

  // Report emptiness up, so the panel can show one aggregate empty state when
  // every group is empty (each empty group hides itself below). A page whose
  // rows are all filtered out does not make the group empty while more pages
  // remain: an editable entity may sit on a later page, so emptiness is only
  // final once pagination is exhausted.
  const isEmpty =
    !isLoading && !error && editableItems.length === 0 && !hasMore;
  useEffect(() => {
    onEmptyChange(groupKey, isEmpty);
  }, [onEmptyChange, groupKey, isEmpty]);

  if (isLoading) {
    return <Skeleton height="1.2rem" width="100%" my="3" />;
  }

  // Hide groups with no matching entities so the panel does not fill up with
  // empty bundle headings (especially while searching).
  if (isEmpty) {
    return null;
  }

  // No editable rows on the pages loaded so far, but more remain: render just
  // the observer (no group heading yet), so it keeps loading pages until an
  // editable row appears or pagination is exhausted. A failed request falls
  // through instead, so its error card (and retry) render below.
  if (!error && editableItems.length === 0) {
    return <InfiniteScrollObserver onLoadMore={handleLoadMore} />;
  }

  const handleSelect = (item: ContentStub) => {
    navigateToEditor(group.entityType, item.id);
  };

  return (
    <Collapsible.Root open={isOpen} onOpenChange={setIsOpen} asChild>
      <Box
        data-testid={`canvas-templated-content-${group.entityType}-${bundle}`}
      >
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
                  {bundleLabel}
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
              error={String(error)}
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
          {hasMore && <InfiniteScrollObserver onLoadMore={handleLoadMore} />}
        </Collapsible.Content>
      </Box>
    </Collapsible.Root>
  );
};

/**
 * Renders the Content panel's templated-entity groups (entities of bundles
 * with an enabled full-view template), one collapsible section per entity
 * type (bundle): content is sorted per content type, the content type is
 * the folder. The single shared search term filters every folder.
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
    (groupKey: string, isEmpty: boolean) => {
      setEmptyGroups((previous) =>
        previous[groupKey] === isEmpty
          ? previous
          : { ...previous, [groupKey]: isEmpty },
      );
    },
    [],
  );
  // One folder per content type (bundle), per the design.
  const bundleFolders = groups.flatMap((group) =>
    group.bundles.map(({ bundle, label }) => ({ group, bundle, label })),
  );
  if (bundleFolders.length === 0) {
    return null;
  }
  // Every folder hides itself when empty; show one aggregate empty state so
  // the panel is never silently blank (e.g. a search with zero results).
  const allEmpty = bundleFolders.every(
    ({ group, bundle }) =>
      emptyGroups[`${group.entityType}:${bundle}`] === true,
  );
  return (
    <>
      {allEmpty && (
        <EmptyStateCallout title="No content found" variant="surface" />
      )}
      {bundleFolders.map(({ group, bundle, label }) => (
        <TemplatedContentGroup
          key={`${group.entityType}:${bundle}`}
          group={group}
          bundle={bundle}
          bundleLabel={label}
          searchTerm={searchTerm}
          onEmptyChange={handleEmptyChange}
        />
      ))}
    </>
  );
};

export default TemplatedContentGroups;
