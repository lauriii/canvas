import { useEffect, useRef, useState } from 'react';
import FolderIcon from '@assets/icons/folder.svg?react';
import { useDraggable } from '@dnd-kit/core';
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
  Text,
  TextField,
  Tooltip,
} from '@radix-ui/themes';

import EmptyStateCallout from '@/components/EmptyStateCallout';
import ErrorCard from '@/components/error/ErrorCard';
import InfiniteScrollObserver from '@/components/InfiniteScrollObserver';
import FolderList, {
  folderfyComponents,
  folderNameInputStyle,
  sortFolderList,
  useMoveToFolderMenu,
} from '@/components/list/FolderList';
import UncategorizedDropZone from '@/components/list/UncategorizedDropZone';
import { useNewFolder } from '@/components/list/useNewFolder';
import { ListIndentContext } from '@/components/sidePanel/ListIndentContext';
import SidebarNode from '@/components/sidePanel/SidebarNode';
import UnifiedMenu from '@/components/UnifiedMenu';
import { extractErrorMessageFromApiResponse } from '@/features/error-handling/error-handling';
import { usePermissionCheck } from '@/hooks/usePermissionCheck';
import { useUpdateFolderMutation } from '@/services/componentAndLayout';
import { getModuleOperations } from '@/utils/contentOperations';

import type { FormEvent, ReactNode } from 'react';
import type { FolderData } from '@/components/list/FolderList';
import type { Folder } from '@/types/Component';
import type { ContentStub } from '@/types/Content';

// The same pattern as the `path` of canvas.folder.* in canvas.schema.yml.
const FOLDER_PATH_PATTERN = /^(\/[a-z0-9_-]+)+$/;

/**
 * Edits the URL path of a folder of pages in the row of the folder, as a
 * rename does: Enter saves, Escape or leaving the input cancels.
 *
 * @see docs/adr/0027-page-folders-can-set-the-url-path-of-their-pages.md
 */
const FolderPathInput = ({
  folder,
  onDone,
}: {
  folder: Folder;
  onDone: () => void;
}) => {
  // A folder without a path starts with one made of its name, selected, so
  // that Enter accepts it and typing replaces it.
  const [path, setPath] = useState(
    folder.path ??
      `/${folder.name
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')}`,
  );
  const [updateFolder, { isLoading, error }] = useUpdateFolderMutation();
  const inputRef = useRef<HTMLInputElement>(null);
  const isInvalid = path !== '' && !FOLDER_PATH_PATTERN.test(path);

  // After the render, as the rename input does: the closing folder menu takes
  // focus back from anything focused earlier.
  useEffect(() => {
    inputRef.current?.focus();
    inputRef.current?.select();
  }, []);

  const save = async () => {
    if (isInvalid || isLoading) {
      return;
    }
    if (path === (folder.path ?? '')) {
      onDone();
      return;
    }
    try {
      await updateFolder({
        id: folder.id,
        changes: {
          name: folder.name,
          weight: folder.weight,
          items: folder.items,
          path: path || null,
        },
      }).unwrap();
      onDone();
    } catch {
      // `error` is shown below the input.
      inputRef.current?.focus();
    }
  };

  return (
    <Flex direction="column" gap="1" width="100%">
      <Flex align="center" gap="2">
        <Text size="1" weight="medium" truncate style={{ flexShrink: 1 }}>
          {folder.name}
        </Text>
        <TextField.Root
          ref={inputRef}
          aria-label={`URL path of ${folder.name}`}
          data-testid="canvas-folder-path-input"
          autoComplete="off"
          placeholder="/url-path"
          value={path}
          onChange={(event) => setPath(event.target.value)}
          onBlur={() => !isLoading && onDone()}
          onKeyDown={(event) => {
            if (event.key === 'Enter') {
              event.preventDefault();
              void save();
            } else if (event.key === 'Escape') {
              onDone();
            }
          }}
          disabled={isLoading}
          aria-invalid={isInvalid || !!error}
          size="1"
          variant="soft"
          style={{ ...folderNameInputStyle, flex: 1, minWidth: '6rem' }}
        />
      </Flex>
      {(isInvalid || error) && (
        <Text size="1" color="red" weight="medium">
          {isInvalid
            ? 'Start with a slash, and use only lowercase letters, digits, hyphens, and underscores.'
            : extractErrorMessageFromApiResponse(error)}
        </Text>
      )}
    </Flex>
  );
};

const hasPermission = (
  permission:
    'edit' | 'duplicate' | 'homepage' | 'delete' | 'unpublish' | 'publish',
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
  moveToFolderMenu?: ReactNode,
) => {
  const hasDuplicate = hasPermission('duplicate', item);
  const hasHomepage =
    hasPermission('homepage', item) && item.internalPath !== homepagePath;
  const hasUnpublish = hasPermission('unpublish', item);
  const hasPublish = hasPermission('publish', item);
  const hasDelete = hasPermission('delete', item);
  const moduleOperations = getModuleOperations(item);
  const hasMove = !!moveToFolderMenu;

  // If no permissions, don't render dropdown
  if (
    !hasDuplicate &&
    !hasHomepage &&
    !hasUnpublish &&
    !hasPublish &&
    !hasDelete &&
    !hasMove &&
    !moduleOperations.length
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
      {moduleOperations.length > 0 && (
        <>
          {(hasDuplicate || hasHomepage || hasUnpublish || hasPublish) && (
            <UnifiedMenu.Separator />
          )}
          {moduleOperations.map(({ rel, href, label }) => (
            <UnifiedMenu.Item
              key={rel}
              asChild
              onClick={(event) => event.stopPropagation()}
            >
              <a href={href}>{label}</a>
            </UnifiedMenu.Item>
          ))}
        </>
      )}
      {hasMove && (
        <>
          {(hasDuplicate ||
            hasHomepage ||
            hasUnpublish ||
            hasPublish ||
            moduleOperations.length > 0) && <UnifiedMenu.Separator />}
          {moveToFolderMenu}
        </>
      )}
      {hasDelete && (
        <>
          {(hasDuplicate ||
            hasHomepage ||
            hasUnpublish ||
            hasPublish ||
            hasMove ||
            moduleOperations.length > 0) && <UnifiedMenu.Separator />}
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

// Component for individual page item to manage menu state.
// Also used by the Content panel's templated-entity groups.
export const PageListItem = ({
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
  movable,
}: {
  item: ContentStub;
  isSelected: boolean;
  isHomepage: boolean;
  homepagePath?: string;
  /** Whether the row is in a list that groups pages by folder. */
  movable?: boolean;
  onSelect?: (value: ContentStub) => void;
  onDuplicate?: (page: ContentStub) => void;
  onSetHomepage?: (page: ContentStub) => void;
  onUnpublish?: (page: ContentStub) => void;
  onPublish?: (page: ContentStub) => void;
  onDelete?: (page: ContentStub) => void;
}) => {
  const [isMenuOpen, setIsMenuOpen] = useState(false);
  const title = `${item.autoSaveLabel || item.title} ${item.autoSavePath || item.path}`;
  const moveToFolderMenu = useMoveToFolderMenu(
    'canvas_page',
    movable ? item.uuid : undefined,
  );
  const canMove = !!moveToFolderMenu;
  // Only the pointer listeners are used. The page menu's "Move to folder" is
  // the way to move a page without a pointer.
  const { listeners, setNodeRef } = useDraggable({
    id: item.uuid ?? `page-${item.id}`,
    data: { origin: 'pages', name: item.autoSaveLabel || item.title },
    disabled: !canMove,
  });
  const dropdownMenuContent = createPageMenuContent(
    item,
    onDuplicate,
    onSetHomepage,
    onUnpublish,
    onPublish,
    onDelete,
    homepagePath,
    moveToFolderMenu,
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
        <div ref={setNodeRef} {...(canMove ? listeners : undefined)}>
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
  movable,
  inFolder,
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
  movable?: boolean;
  /** Rows of one folder: no empty state, and not the list's test ID. */
  inFolder?: boolean;
}) => {
  if (items.length === 0) {
    return inFolder ? null : (
      <EmptyStateCallout
        data-testid="canvas-page-list"
        title="No pages found"
        variant="surface"
      />
    );
  }

  return (
    <Flex
      data-testid={inFolder ? undefined : 'canvas-page-list'}
      direction="column"
      gap="1"
    >
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
            movable={movable}
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
  /** Set to group the pages by folder. Left out while searching. */
  folders?: FolderData;
  // Pagination
  hasMore?: boolean;
  onLoadMore?: () => void;
  // Event handlers
  /** With a folder ID, the new page is created in that folder. */
  onNewPage?: (folderId?: string) => void;
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
  folders,
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

  const newFolder = useNewFolder('canvas_page');
  const canManageFolders =
    usePermissionCheck({ hasPermission: 'folders' }) && !!folders;
  const groupProps = {
    homepagePath,
    selectedPageId,
    onSelect: onSelectPage,
    onDuplicate: onDuplicatePage,
    onSetHomepage,
    onUnpublish: onUnpublishPage,
    onPublish: onPublishPage,
    onDelete: onDeletePage,
    movable: !!folders,
  };
  // Keyed by UUID, as folders list pages. A key that is not a number keeps
  // the order of the list.
  const { folderComponents, topLevelComponents } = folderfyComponents(
    Object.fromEntries(
      pageItems.map((page) => [page.uuid ?? `id:${page.id}`, page]),
    ),
    folders,
    false,
    false,
    'canvas_page',
  );
  const folderEntries = sortFolderList(folderComponents);
  // The folder whose URL path is being set.
  const [pathFolderId, setPathFolderId] = useState<string | null>(null);
  const pagesWithoutFolder = Object.values(topLevelComponents) as ContentStub[];

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
        {(canCreatePages || canManageFolders) && (
          <DropdownMenu.Root>
            <DropdownMenu.Trigger>
              <Button
                ref={newFolder.triggerRef}
                variant="soft"
                data-testid="canvas-page-list-new-button"
                size="1"
              >
                <PlusIcon />
                New
                <ChevronDownIcon />
              </Button>
            </DropdownMenu.Trigger>
            <DropdownMenu.Content onCloseAutoFocus={newFolder.onCloseAutoFocus}>
              {canCreatePages && (
                <DropdownMenu.Item
                  onClick={() => onNewPage?.()}
                  data-testid="canvas-page-list-new-page-button"
                >
                  <FileTextIcon />
                  New page
                </DropdownMenu.Item>
              )}
              {canManageFolders && (
                <DropdownMenu.Item
                  onClick={newFolder.start}
                  data-testid="canvas-page-list-new-folder-button"
                >
                  <FolderIcon />
                  Folder
                </DropdownMenu.Item>
              )}
            </DropdownMenu.Content>
          </DropdownMenu.Root>
        )}
      </Flex>
      {newFolder.input}
      <Skeleton
        height="1.2rem"
        loading={isPageItemsLoading}
        width="100%"
        my="3"
      >
        <Box>
          {!pageItemsError && !folders && (
            <ContentGroup items={pageItems} {...groupProps} />
          )}
          {!pageItemsError && folders && (
            <>
              {folderEntries.map((folder) => (
                <FolderList
                  key={folder.id}
                  folder={folder}
                  deleteWarning="Cannot delete folder containing pages"
                  nameDetail={folders.folders[folder.id]?.path ?? undefined}
                  onNameDetailDoubleClick={
                    canManageFolders
                      ? () => setPathFolderId(folder.id)
                      : undefined
                  }
                  editSlot={
                    folder.id === pathFolderId && (
                      <FolderPathInput
                        folder={folders.folders[folder.id] as Folder}
                        onDone={() => setPathFolderId(null)}
                      />
                    )
                  }
                  extraMenuItems={
                    <>
                      {canCreatePages && (
                        <UnifiedMenu.Item
                          onClick={() => onNewPage?.(folder.id)}
                        >
                          New page
                        </UnifiedMenu.Item>
                      )}
                      {canManageFolders && (
                        <UnifiedMenu.Item
                          data-testid="canvas-folder-path-button"
                          onClick={() => setPathFolderId(folder.id)}
                        >
                          {folders.folders[folder.id]?.path
                            ? 'Edit URL path'
                            : 'Set URL path'}
                        </UnifiedMenu.Item>
                      )}
                    </>
                  }
                >
                  <ListIndentContext.Provider value={1}>
                    <ContentGroup
                      items={Object.values(folder.items) as ContentStub[]}
                      inFolder
                      {...groupProps}
                    />
                  </ListIndentContext.Provider>
                </FolderList>
              ))}
              <UncategorizedDropZone
                itemType="canvas_page"
                hasItems={pagesWithoutFolder.length > 0}
              >
                <ContentGroup
                  items={pagesWithoutFolder}
                  // "No pages found" would be wrong under folders with pages.
                  inFolder={
                    folderEntries.length > 0 && pagesWithoutFolder.length === 0
                  }
                  {...groupProps}
                />
              </UncategorizedDropZone>
            </>
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
