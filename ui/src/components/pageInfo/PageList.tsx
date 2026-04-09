import { useEffect, useState } from 'react';
import {
  ChevronDownIcon,
  ClockIcon,
  FileTextIcon,
  MagnifyingGlassIcon,
  PlusIcon,
} from '@radix-ui/react-icons';
import {
  AlertDialog,
  Badge,
  Box,
  Button,
  ContextMenu,
  DropdownMenu,
  Flex,
  Skeleton,
  TextField,
  Tooltip,
} from '@radix-ui/themes';

import EmptyStateCallout from '@/components/EmptyStateCallout';
import ErrorCard from '@/components/error/ErrorCard';
import InfiniteScrollObserver from '@/components/InfiniteScrollObserver';
import SidebarNode from '@/components/sidePanel/SidebarNode';
import UnifiedMenu from '@/components/UnifiedMenu';

import type { FormEvent } from 'react';
import type { ContentStub } from '@/types/Content';

const hasPermission = (
  permission:
    | 'edit'
    | 'duplicate'
    | 'homepage'
    | 'delete'
    | 'unpublish'
    | 'publish',
  item: ContentStub,
) => {
  const links = item.links || {};
  switch (permission) {
    case 'edit':
      return !!links['edit-form'];
    case 'duplicate':
      return !!links['https://drupal.org/project/canvas#link-rel-duplicate'];
    case 'homepage':
      return !!links[
        'https://drupal.org/project/canvas#link-rel-set-as-homepage'
      ];
    case 'delete':
      return !!links['delete-form'];
    case 'unpublish':
      return !!links['disable'];
    case 'publish':
      return !!links['enable'];
    default:
      return false;
  }
};

// Helper function to create dropdown menu content for a page item
const createPageMenuContent = (
  item: ContentStub,
  onDuplicate?: (page: ContentStub) => void,
  onSetHomepage?: (page: ContentStub) => void,
  onUnpublish?: (page: ContentStub) => void,
  onPublish?: (page: ContentStub) => void,
  onDelete?: (page: ContentStub) => void,
  homepagePath?: string,
) => {
  const hasDuplicate = hasPermission('duplicate', item);
  const hasHomepage =
    hasPermission('homepage', item) && item.internalPath !== homepagePath;
  const hasUnpublish = hasPermission('unpublish', item);
  const hasPublish = hasPermission('publish', item);
  const hasDelete = hasPermission('delete', item);

  // If no permissions, don't render dropdown
  if (
    !hasDuplicate &&
    !hasHomepage &&
    !hasUnpublish &&
    !hasPublish &&
    !hasDelete
  ) {
    return null;
  }

  return (
    <>
      <UnifiedMenu.Label>{item.autoSaveLabel || item.title}</UnifiedMenu.Label>
      <UnifiedMenu.Separator />
      {hasDuplicate && (
        <UnifiedMenu.Item
          onClick={(event) => event.stopPropagation()}
          onSelect={onDuplicate ? () => onDuplicate(item) : undefined}
        >
          Duplicate page
        </UnifiedMenu.Item>
      )}
      {hasHomepage && (
        <>
          {hasDuplicate && <UnifiedMenu.Separator />}
          <UnifiedMenu.Item
            onClick={(event) => event.stopPropagation()}
            onSelect={onSetHomepage ? () => onSetHomepage(item) : undefined}
          >
            Set as homepage
          </UnifiedMenu.Item>
        </>
      )}
      {hasUnpublish && (
        <>
          {(hasDuplicate || hasHomepage) && <UnifiedMenu.Separator />}
          <UnifiedMenu.Item
            onClick={(event) => event.stopPropagation()}
            onSelect={onUnpublish ? () => onUnpublish(item) : undefined}
          >
            Unpublish page
          </UnifiedMenu.Item>
        </>
      )}
      {hasPublish && (
        <>
          {(hasDuplicate || hasHomepage || hasUnpublish) && (
            <UnifiedMenu.Separator />
          )}
          <UnifiedMenu.Item
            onClick={(event) => event.stopPropagation()}
            onSelect={onPublish ? () => onPublish(item) : undefined}
          >
            Publish page
          </UnifiedMenu.Item>
        </>
      )}
      {hasDelete && (
        <>
          {(hasDuplicate || hasHomepage || hasUnpublish || hasPublish) && (
            <UnifiedMenu.Separator />
          )}
          <AlertDialog.Root>
            <AlertDialog.Trigger>
              <UnifiedMenu.Item
                onClick={(event) => event.stopPropagation()}
                onSelect={(event) => event.preventDefault()}
                color="red"
              >
                Delete page
              </UnifiedMenu.Item>
            </AlertDialog.Trigger>
            <AlertDialog.Content>
              <AlertDialog.Title>Delete {item.title} page</AlertDialog.Title>
              <AlertDialog.Description size="2">
                This action will permanently delete the page and all of its
                contents. This action cannot be undone.
              </AlertDialog.Description>
              <Flex gap="3" mt="4" justify="end">
                <AlertDialog.Cancel>
                  <Button variant="soft" color="gray">
                    Cancel
                  </Button>
                </AlertDialog.Cancel>
                <AlertDialog.Action>
                  <Button
                    variant="solid"
                    color="red"
                    onClick={() => onDelete?.(item)}
                  >
                    Delete page
                  </Button>
                </AlertDialog.Action>
              </Flex>
            </AlertDialog.Content>
          </AlertDialog.Root>
        </>
      )}
    </>
  );
};

// Component for individual page item to manage menu state
const PageListItem = ({
  item,
  isSelected,
  isHomepage,
  homepagePath,
  onSelect,
  onDuplicate,
  onSetHomepage,
  onUnpublish,
  onPublish,
  onDelete,
}: {
  item: ContentStub;
  isSelected: boolean;
  isHomepage: boolean;
  homepagePath?: string;
  onSelect?: (value: ContentStub) => void;
  onDuplicate?: (page: ContentStub) => void;
  onSetHomepage?: (page: ContentStub) => void;
  onUnpublish?: (page: ContentStub) => void;
  onPublish?: (page: ContentStub) => void;
  onDelete?: (page: ContentStub) => void;
}) => {
  const [isMenuOpen, setIsMenuOpen] = useState(false);
  const title = `${item.autoSaveLabel || item.title} ${item.autoSavePath || item.path}`;
  const dropdownMenuContent = createPageMenuContent(
    item,
    onDuplicate,
    onSetHomepage,
    onUnpublish,
    onPublish,
    onDelete,
    homepagePath,
  );

  // Determine unpublished status:
  // - Show "Clock icon + Unpublish" if there's an unsaved status change to unpublished
  // - Show "Unpublished" if page is unpublished (but not a new draft) and no unsaved changes
  // - Draft pages (never published) should not show any unpublished badge
  const isUnpublished =
    !item.status && !item.isNew && !item.hasUnsavedStatusChange;
  const willBeUnpublished =
    !item.status && !item.isNew && item.hasUnsavedStatusChange;

  return (
    <ContextMenu.Root>
      <ContextMenu.Trigger>
        <div>
          <SidebarNode
            title={title}
            variant={isHomepage ? 'homepage' : 'page'}
            selected={isSelected}
            trailingContent={
              !isMenuOpen && (isUnpublished || willBeUnpublished) ? (
                willBeUnpublished ? (
                  <Tooltip content="Applies on next publish">
                    <Badge
                      size="1"
                      variant="solid"
                      color={isSelected ? 'blue' : 'gray'}
                    >
                      <Flex align="center" gap="1">
                        <ClockIcon width="11" height="11" />
                        Unpublish
                      </Flex>
                    </Badge>
                  </Tooltip>
                ) : (
                  <Badge
                    size="1"
                    variant="solid"
                    color={isSelected ? 'blue' : 'gray'}
                  >
                    Unpublished
                  </Badge>
                )
              ) : undefined
            }
            dropdownMenuContent={
              dropdownMenuContent ? (
                <UnifiedMenu.Content menuType="dropdown">
                  {dropdownMenuContent}
                </UnifiedMenu.Content>
              ) : null
            }
            onMenuOpenChange={setIsMenuOpen}
            onClick={onSelect ? () => onSelect(item) : undefined}
            data-canvas-page-id={item.id}
          />
        </div>
      </ContextMenu.Trigger>
      <UnifiedMenu.Content menuType="context" align="start" side="right">
        {dropdownMenuContent}
      </UnifiedMenu.Content>
    </ContextMenu.Root>
  );
};

const ContentGroup = ({
  items,
  homepagePath,
  selectedPageId,
  onSelect,
  onDuplicate,
  onSetHomepage,
  onUnpublish,
  onPublish,
  onDelete,
}: {
  items: ContentStub[];
  homepagePath?: string;
  selectedPageId?: string | number;
  onSelect?: (value: ContentStub) => void;
  onDuplicate?: (page: ContentStub) => void;
  onSetHomepage?: (page: ContentStub) => void;
  onUnpublish?: (page: ContentStub) => void;
  onPublish?: (page: ContentStub) => void;
  onDelete?: (page: ContentStub) => void;
}) => {
  if (items.length === 0) {
    return (
      <EmptyStateCallout
        data-testid="canvas-page-list"
        title="No pages found"
        variant="surface"
      />
    );
  }

  return (
    <Flex data-testid="canvas-page-list" direction="column" gap="1">
      {items.map((item) => {
        const isSelected =
          selectedPageId !== undefined &&
          String(selectedPageId) === String(item.id);
        const isHomepage = item.internalPath === homepagePath;

        return (
          <PageListItem
            key={`${item.id}-${item.status}`}
            item={item}
            isSelected={isSelected}
            isHomepage={isHomepage}
            homepagePath={homepagePath}
            onSelect={onSelect}
            onDuplicate={onDuplicate}
            onSetHomepage={onSetHomepage}
            onUnpublish={onUnpublish}
            onPublish={onPublish}
            onDelete={onDelete}
          />
        );
      })}
    </Flex>
  );
};

interface PageListProps {
  // Data
  pageItems?: ContentStub[];
  isPageItemsLoading?: boolean;
  pageItemsError?: string | null;
  homepagePath?: string;
  selectedPageId?: string | number;
  // Permissions
  canCreatePages?: boolean;
  // Pagination
  hasMore?: boolean;
  onLoadMore?: () => void;
  // Event handlers
  onNewPage?: () => void;
  onDeletePage?: (item: ContentStub) => void;
  onDuplicatePage?: (item: ContentStub) => void;
  onSelectPage?: (item: ContentStub) => void;
  onSetHomepage?: (item: ContentStub) => void;
  onUnpublishPage?: (item: ContentStub) => void;
  onPublishPage?: (item: ContentStub) => void;
  onSearch?: (value: string) => void;
}

const PageList = ({
  pageItems = [],
  isPageItemsLoading = false,
  pageItemsError = null,
  homepagePath,
  selectedPageId,
  canCreatePages = false,
  hasMore = false,
  onLoadMore,
  onNewPage,
  onDeletePage,
  onDuplicatePage,
  onSelectPage,
  onSetHomepage,
  onUnpublishPage,
  onPublishPage,
  onSearch,
}: PageListProps) => {
  // Reset search when the component unmounts
  useEffect(() => {
    return () => {
      if (onSearch) {
        onSearch('');
      }
    };
  }, [onSearch]);

  return (
    <div data-testid="canvas-page-list-panel">
      <Flex direction="row" gap="2" mb="4">
        <form
          style={{ flexGrow: 1 }}
          onChange={(event: FormEvent<HTMLFormElement>) => {
            event.preventDefault();
            const form = event.currentTarget;
            const formElements = form.elements as typeof form.elements & {
              'canvas-navigation-search': HTMLInputElement;
            };
            onSearch?.(formElements['canvas-navigation-search'].value);
          }}
          onSubmit={(event: FormEvent<HTMLFormElement>) => {
            event.preventDefault();
          }}
        >
          <TextField.Root
            autoComplete="off"
            id="canvas-navigation-search"
            placeholder="Search…"
            radius="medium"
            aria-label="Search content"
            size="1"
          >
            <TextField.Slot>
              <MagnifyingGlassIcon height="16" width="16" />
            </TextField.Slot>
          </TextField.Root>
        </form>
        {canCreatePages && (
          <DropdownMenu.Root>
            <DropdownMenu.Trigger>
              <Button
                variant="soft"
                data-testid="canvas-page-list-new-button"
                size="1"
              >
                <PlusIcon />
                New
                <ChevronDownIcon />
              </Button>
            </DropdownMenu.Trigger>
            <DropdownMenu.Content>
              <DropdownMenu.Item
                onClick={onNewPage}
                data-testid="canvas-page-list-new-page-button"
              >
                <FileTextIcon />
                New page
              </DropdownMenu.Item>
            </DropdownMenu.Content>
          </DropdownMenu.Root>
        )}
      </Flex>
      <Skeleton
        height="1.2rem"
        loading={isPageItemsLoading}
        width="100%"
        my="3"
      >
        <Box>
          {!pageItemsError && (
            <ContentGroup
              items={pageItems}
              homepagePath={homepagePath}
              selectedPageId={selectedPageId}
              onSelect={onSelectPage}
              onDuplicate={onDuplicatePage}
              onSetHomepage={onSetHomepage}
              onUnpublish={onUnpublishPage}
              onPublish={onPublishPage}
              onDelete={onDeletePage}
            />
          )}
          {pageItemsError && (
            <ErrorCard
              title="An unexpected error has occurred while loading pages."
              error={pageItemsError}
            />
          )}
          {hasMore && <InfiniteScrollObserver onLoadMore={onLoadMore} />}
        </Box>
      </Skeleton>
      <Skeleton
        loading={isPageItemsLoading}
        height="1.2rem"
        width="100%"
        my="3"
      />
      <Skeleton
        loading={isPageItemsLoading}
        height="1.2rem"
        width="100%"
        my="3"
      />
    </div>
  );
};

export default PageList;
