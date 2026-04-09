import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import {
  ChevronDownIcon,
  ClockIcon,
  DotsVerticalIcon,
  FileTextIcon,
  HomeIcon,
  MagnifyingGlassIcon,
  PlusIcon,
} from '@radix-ui/react-icons';
import {
  AlertDialog,
  Badge,
  Box,
  Button,
  DropdownMenu,
  Flex,
  Heading,
  IconButton,
  ScrollArea,
  Text,
  TextField,
  Tooltip,
} from '@radix-ui/themes';

import { useAppSelector } from '@/app/hooks';
import EmptyStateCallout from '@/components/EmptyStateCallout';
import InfiniteScrollObserver from '@/components/InfiniteScrollObserver';
import { selectHomepagePath } from '@/features/configuration/configurationSlice';
import useEditorNavigation from '@/hooks/useEditorNavigation';

import type { FormEvent } from 'react';
import type { ContentStub } from '@/types/Content';

import styles from './Navigation.module.css';

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

const shouldShowSeparator = (
  item: ContentStub,
  permissions: Array<
    'edit' | 'duplicate' | 'homepage' | 'unpublish' | 'publish'
  >,
) => permissions.some((permission) => hasPermission(permission, item));

// Helper functions to return JSX or null based on item links/permissions
const renderDuplicateButton = (
  item: ContentStub,
  onDuplicate?: (page: ContentStub) => void,
) =>
  hasPermission('duplicate', item) ? (
    <DropdownMenu.Item
      onClick={(event) => event.stopPropagation()}
      onSelect={onDuplicate ? () => onDuplicate(item) : undefined}
    >
      Duplicate page
    </DropdownMenu.Item>
  ) : null;

const renderSetAsHomepageButton = (
  item: ContentStub,
  onSetHomepage?: (page: ContentStub) => void,
  homepagePath?: string,
) =>
  hasPermission('homepage', item) && item.internalPath !== homepagePath ? (
    <>
      <DropdownMenu.Separator />
      <DropdownMenu.Item
        onClick={(event) => event.stopPropagation()}
        onSelect={onSetHomepage ? () => onSetHomepage(item) : undefined}
      >
        Set as homepage
      </DropdownMenu.Item>
    </>
  ) : null;

const renderUnpublishButton = (
  item: ContentStub,
  onUnpublish?: (page: ContentStub) => void,
) =>
  hasPermission('unpublish', item) ? (
    <>
      {shouldShowSeparator(item, ['edit', 'homepage', 'duplicate']) && (
        <DropdownMenu.Separator />
      )}
      <DropdownMenu.Item
        onClick={(event) => event.stopPropagation()}
        onSelect={onUnpublish ? () => onUnpublish(item) : undefined}
      >
        Unpublish page
      </DropdownMenu.Item>
    </>
  ) : null;

const renderPublishButton = (
  item: ContentStub,
  onPublish?: (page: ContentStub) => void,
) =>
  hasPermission('publish', item) ? (
    <>
      {shouldShowSeparator(item, [
        'edit',
        'homepage',
        'duplicate',
        'unpublish',
      ]) && <DropdownMenu.Separator />}
      <DropdownMenu.Item
        onClick={(event) => event.stopPropagation()}
        onSelect={onPublish ? () => onPublish(item) : undefined}
      >
        Publish page
      </DropdownMenu.Item>
    </>
  ) : null;

const renderDeleteButton = (
  item: ContentStub,
  onDelete?: (page: ContentStub) => void,
) =>
  hasPermission('delete', item) ? (
    <>
      {shouldShowSeparator(item, [
        'edit',
        'homepage',
        'duplicate',
        'unpublish',
      ]) && <DropdownMenu.Separator />}
      <AlertDialog.Root>
        <AlertDialog.Trigger>
          <DropdownMenu.Item
            onClick={(event) => event.stopPropagation()}
            onSelect={(event) => event.preventDefault()}
            color="red"
          >
            Delete page
          </DropdownMenu.Item>
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
              <DropdownMenu.Item
                onClick={(event) => event.stopPropagation()}
                onSelect={() => (onDelete ? onDelete(item) : undefined)}
              >
                <Button variant="solid" color="red">
                  Delete page
                </Button>
              </DropdownMenu.Item>
            </AlertDialog.Action>
          </Flex>
        </AlertDialog.Content>
      </AlertDialog.Root>
    </>
  ) : null;

// Component for individual navigation item to manage menu state
const NavigationItem = ({
  item,
  homepagePath,
  onSelect,
  onDuplicate,
  onSetHomepage,
  onUnpublish,
  onPublish,
  onDelete,
}: {
  item: ContentStub;
  homepagePath?: string;
  onSelect?: (value: ContentStub) => void;
  onDuplicate?: (page: ContentStub) => void;
  onSetHomepage?: (page: ContentStub) => void;
  onUnpublish?: (page: ContentStub) => void;
  onPublish?: (page: ContentStub) => void;
  onDelete?: (page: ContentStub) => void;
}) => {
  const [isMenuOpen, setIsMenuOpen] = useState(false);
  const { urlForEditor } = useEditorNavigation();

  // Determine unpublished status:
  // - Show "Clock icon + Unpublish" if there's an unsaved status change to unpublished
  // - Show "Unpublished" if page is unpublished (but not a new draft) and no unsaved changes
  // - Draft pages (never published) should not show any unpublished badge
  const isUnpublished =
    !item.status && !item.isNew && !item.hasUnsavedStatusChange;
  const willBeUnpublished =
    !item.status && !item.isNew && item.hasUnsavedStatusChange;

  return (
    <Flex
      direction={'row'}
      align={'center'}
      role={'list'}
      mr="4"
      p="1"
      pr="2"
      className={styles.item}
      data-canvas-page-id={item.id}
    >
      <Link
        to={urlForEditor('canvas_page', item.id)}
        role={'listitem'}
        className={styles.pageLink}
        onClick={onSelect ? () => onSelect(item) : undefined}
      >
        <Box px="3" pt="1">
          {item.internalPath === homepagePath ? <HomeIcon /> : <FileTextIcon />}
        </Box>
        <Flex flexGrow="1" align="center">
          <Text as="span" size="1">
            {item.autoSaveLabel || item.title}{' '}
            <span className={styles.path}>
              {item.autoSavePath || item.path}
            </span>
          </Text>
        </Flex>
      </Link>
      {!isMenuOpen && (isUnpublished || willBeUnpublished) && (
        <Flex align="center" mr="2">
          {willBeUnpublished ? (
            <Tooltip content="Applies on next publish">
              <Badge size="1" variant="solid" color="gray">
                <Flex align="center" gap="1">
                  <ClockIcon width="11" height="11" />
                  Unpublish
                </Flex>
              </Badge>
            </Tooltip>
          ) : (
            <Badge size="1" variant="solid" color="gray">
              Unpublished
            </Badge>
          )}
        </Flex>
      )}
      {Object.keys(item.links).length && (
        <DropdownMenu.Root onOpenChange={setIsMenuOpen}>
          <DropdownMenu.Trigger>
            <IconButton
              variant="ghost"
              color="gray"
              className={styles.optionsButton}
              aria-label={`Page options for ${item.title}`}
            >
              <DotsVerticalIcon />
            </IconButton>
          </DropdownMenu.Trigger>
          <DropdownMenu.Content>
            {renderDuplicateButton(item, onDuplicate)}
            {renderSetAsHomepageButton(item, onSetHomepage, homepagePath)}
            {renderUnpublishButton(item, onUnpublish)}
            {renderPublishButton(item, onPublish)}
            {renderDeleteButton(item, onDelete)}
          </DropdownMenu.Content>
        </DropdownMenu.Root>
      )}
    </Flex>
  );
};

const ContentGroup = ({
  title,
  items,
  onSelect,
  onDuplicate,
  onSetHomepage,
  onUnpublish,
  onPublish,
  onDelete,
}: {
  title: string;
  items: ContentStub[];
  onSelect?: (value: ContentStub) => void;
  onDuplicate?: (page: ContentStub) => void;
  onSetHomepage?: (page: ContentStub) => void;
  onUnpublish?: (page: ContentStub) => void;
  onPublish?: (page: ContentStub) => void;
  onDelete?: (page: ContentStub) => void;
}) => {
  const homepagePath = useAppSelector(selectHomepagePath);
  if (items.length === 0) {
    return (
      <EmptyStateCallout
        data-testid="canvas-navigation-results"
        title="No pages found"
        variant="surface"
      />
    );
  }

  return (
    <div>
      <Heading as="h5" size="1" color="gray">
        {title}
      </Heading>
      <Flex
        data-testid="canvas-navigation-results"
        direction="column"
        gap="2"
        mt="2"
      >
        {items.map((item) => (
          <NavigationItem
            key={`${item.id}-${item.status}`}
            item={item}
            homepagePath={homepagePath}
            onSelect={onSelect}
            onDuplicate={onDuplicate}
            onSetHomepage={onSetHomepage}
            onUnpublish={onUnpublish}
            onPublish={onPublish}
            onDelete={onDelete}
          />
        ))}
      </Flex>
    </div>
  );
};

const Navigation = ({
  loading = false,
  showNew,
  items = [],
  onNewPage,
  onSearch,
  onSelect,
  onDuplicate,
  onSetHomepage,
  onUnpublish,
  onPublish,
  onDelete,
  hasMore = false,
  onLoadMore,
}: {
  loading: boolean;
  showNew: boolean;
  items: ContentStub[];
  onNewPage?: () => void;
  onSearch?: (value: string) => void;
  onSelect?: (value: ContentStub) => void;
  onDuplicate?: (page: ContentStub) => void;
  onSetHomepage?: (page: ContentStub) => void;
  onUnpublish?: (page: ContentStub) => void;
  onPublish?: (page: ContentStub) => void;
  onDelete?: (page: ContentStub) => void;
  hasMore?: boolean;
  onLoadMore?: () => void;
}) => {
  // Reset search when the component unmounts
  useEffect(() => {
    return () => {
      if (onSearch) {
        onSearch('');
      }
    };
  }, [onSearch]);

  return (
    <div data-testid="canvas-navigation-content">
      <Flex direction="row" gap="2" mb="4">
        <form
          className={styles.search}
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
        {showNew && (
          <DropdownMenu.Root>
            <DropdownMenu.Trigger>
              <Button
                variant="soft"
                data-testid="canvas-navigation-new-button"
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
                data-testid="canvas-navigation-new-page-button"
              >
                <FileTextIcon />
                New page
              </DropdownMenu.Item>
            </DropdownMenu.Content>
          </DropdownMenu.Root>
        )}
      </Flex>
      <ScrollArea scrollbars="vertical" style={{ height: 175 }}>
        {loading && <p>Loading...</p>}
        {!loading && (
          <>
            <ContentGroup
              title="Pages"
              items={items}
              onSelect={onSelect}
              onDuplicate={onDuplicate}
              onSetHomepage={onSetHomepage}
              onUnpublish={onUnpublish}
              onPublish={onPublish}
              onDelete={onDelete}
            />
            {hasMore && <InfiniteScrollObserver onLoadMore={onLoadMore} />}
          </>
        )}
      </ScrollArea>
    </div>
  );
};
export default Navigation;
