import { useParams } from 'react-router-dom';
import NewTabIcon from '@assets/icons/new-tab.svg?react';
import { ChevronDownIcon, PlusIcon } from '@radix-ui/react-icons';
import {
  Box,
  Button,
  DropdownMenu,
  Flex,
  Heading,
  Skeleton,
} from '@radix-ui/themes';

import ErrorCard from '@/components/error/ErrorCard';
import InfiniteScrollObserver from '@/components/InfiniteScrollObserver';
import { PageListItem } from '@/components/pageInfo/PageList';
import { getAddNewOptions } from '@/features/navigator/templatedContent';
import useEditorNavigation from '@/hooks/useEditorNavigation';
import { usePaginatedContentList } from '@/hooks/usePaginatedContentList';
import { getBaseUrl, getCanvasSettings } from '@/utils/drupal-globals';

import type {
  AddNewOption,
  TemplatedEntityGroup,
} from '@/features/navigator/templatedContent';
import type { ContentStub } from '@/types/Content';

/**
 * "Add new" affordance for a templated group. Links out to Drupal's own entity
 * creation forms (opened in a new tab); the navigator does not create entities
 * in-app for v1. Renders nothing when the user cannot create any of the group's
 * bundles.
 */
const AddNewContent = ({ options }: { options: AddNewOption[] }) => {
  if (options.length === 0) {
    return null;
  }

  if (options.length === 1) {
    const [option] = options;
    return (
      <Button
        asChild
        variant="ghost"
        size="1"
        data-testid={`canvas-templated-content-add-${option.bundle}`}
      >
        <a href={option.url} target="_blank" rel="noreferrer">
          <PlusIcon />
          Add new
          <NewTabIcon />
        </a>
      </Button>
    );
  }

  return (
    <DropdownMenu.Root>
      <DropdownMenu.Trigger>
        <Button variant="ghost" size="1">
          <PlusIcon />
          Add new
          <ChevronDownIcon />
        </Button>
      </DropdownMenu.Trigger>
      <DropdownMenu.Content>
        {options.map((option) => (
          <DropdownMenu.Item
            key={option.bundle}
            onClick={() => window.open(option.url, '_blank')}
            data-testid={`canvas-templated-content-add-${option.bundle}`}
          >
            {option.label}
            <Flex ml="auto" align="center">
              <NewTabIcon />
            </Flex>
          </DropdownMenu.Item>
        ))}
      </DropdownMenu.Content>
    </DropdownMenu.Root>
  );
};

/**
 * One templated entity type's group: its entities (loaded from the per-entity
 * type content list, server-filtered to active-exposed-slot bundles and access
 * checked), reusing the pages row so selecting an entity opens it in the
 * per-content editor. Empty groups are hidden to keep the navigator quiet,
 * especially while searching.
 */
const TemplatedContentGroup = ({
  group,
  searchTerm,
}: {
  group: TemplatedEntityGroup;
  searchTerm: string;
}) => {
  const { entityType: routeEntityType, entityId } = useParams();
  const { navigateToEditor } = useEditorNavigation();
  const { items, isLoading, error, hasMore, handleLoadMore } =
    usePaginatedContentList(group.entityType, searchTerm);

  const addNewOptions = getAddNewOptions(
    group,
    getCanvasSettings()?.contentEntityCreateOperations,
    getBaseUrl(),
  );

  if (isLoading) {
    return <Skeleton height="1.2rem" width="100%" my="3" />;
  }

  // Hide groups with no matching entities (and no way to add one) so the
  // navigator does not fill up with empty bundle headings.
  if (!error && (items?.length ?? 0) === 0 && addNewOptions.length === 0) {
    return null;
  }

  const handleSelect = (item: ContentStub) => {
    navigateToEditor(group.entityType, item.id);
  };

  return (
    <Box mt="4" data-testid={`canvas-templated-content-${group.entityType}`}>
      <Flex align="center" justify="between" mb="2">
        <Heading as="h5" size="1" color="gray">
          {group.title}
        </Heading>
        <AddNewContent options={addNewOptions} />
      </Flex>
      {error && (
        <ErrorCard
          title="An unexpected error has occurred while loading content."
          error={String(error)}
        />
      )}
      {!error && (
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
      )}
      {hasMore && <InfiniteScrollObserver onLoadMore={handleLoadMore} />}
    </Box>
  );
};

/**
 * Renders the navigator's templated-entity groups (entities of templated
 * bundles with active exposed slots), one section per entity type, beneath the
 * Canvas pages list. The single shared search term filters every group.
 */
const TemplatedContentGroups = ({
  groups,
  searchTerm,
}: {
  groups: TemplatedEntityGroup[];
  searchTerm: string;
}) => {
  if (groups.length === 0) {
    return null;
  }
  return (
    <>
      {groups.map((group) => (
        <TemplatedContentGroup
          key={group.entityType}
          group={group}
          searchTerm={searchTerm}
        />
      ))}
    </>
  );
};

export default TemplatedContentGroups;
