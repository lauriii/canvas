import { useCallback, useMemo, useRef, useState } from 'react';
import parse from 'html-react-parser';
import FolderIcon from '@assets/icons/folder.svg?react';
import {
  ChevronDownIcon,
  ColorWheelIcon,
  MagnifyingGlassIcon,
  PlusIcon,
} from '@radix-ui/react-icons';
import {
  Button,
  DropdownMenu,
  Flex,
  Spinner,
  Text,
  TextField,
} from '@radix-ui/themes';

import EmptyStateCallout from '@/components/EmptyStateCallout';
import FolderList, {
  folderfyComponents,
  sortFolderList,
} from '@/components/list/FolderList';
import UncategorizedDropZone from '@/components/list/UncategorizedDropZone';
import UnifiedMenu from '@/components/UnifiedMenu';
import ColorFormPopover from '@/features/brandKit/components/ColorFormPopover';
import ColorRow from '@/features/brandKit/components/ColorRow';
import FolderNameInput from '@/features/brandKit/components/FolderNameInput';
import {
  DELETE_COLOR_CACHE_KEY,
  UPDATE_COLOR_CACHE_KEY,
} from '@/features/brandKit/constants';
import { useBrandKitColors } from '@/features/brandKit/hooks/useBrandKitColors';
import { extractErrorMessageFromApiResponse } from '@/features/error-handling/error-handling';
import {
  useDeleteColorMutation,
  useUpdateColorMutation,
} from '@/services/brandKit';
import { useGetFoldersQuery } from '@/services/componentAndLayout';

import type { FormEvent } from 'react';
import type { Measurable } from '@radix-ui/rect';
import type { BrandKitColor } from '@/types/CodeComponent';
import type { FolderInList } from '@/types/Component';

const BrandKitColorsSection = () => {
  const [searchTerm, setSearchTerm] = useState('');
  const {
    colors,
    errorMessage,
    isLoading: isLoadingColors,
  } = useBrandKitColors();
  const {
    data: folders,
    error: foldersError,
    isLoading: isLoadingFolders,
  } = useGetFoldersQuery();
  // Editing and deleting a color both apply optimistically and close or unmount
  // the popover that started them, so neither has a form left to report a
  // rejection in. Subscribing to the same fixed cache keys keeps those failures
  // reportable here, which outlives both.
  const [, { error: deleteColorError }] = useDeleteColorMutation({
    fixedCacheKey: DELETE_COLOR_CACHE_KEY,
  });
  const [, { error: updateColorError }] = useUpdateColorMutation({
    fixedCacheKey: UPDATE_COLOR_CACHE_KEY,
  });

  // Folder creation state
  const [isCreatingFolder, setIsCreatingFolder] = useState(false);

  // Suppresses the DropdownMenu's onCloseAutoFocus focus-return in two cases:
  //   1. A popover is being opened (focus-return would fire interact-outside and close it)
  //   2. The folder name input is being shown (it should receive focus instead)
  const suppressAutoFocusRef = useRef(false);

  // Add color popover state
  const [isAddColorPopoverOpen, setIsAddColorPopoverOpen] = useState(false);
  const [addColorFolderId, setAddColorFolderId] = useState<string | undefined>(
    undefined,
  );

  // Anchor ref for the add-color popover — attached to the "New" button
  const newButtonRef = useRef<Measurable>(null);

  // Convert colors array to map for folderfyComponents
  const colorsById = Object.fromEntries(colors.map((c) => [c.id, c]));

  // Build folder structure
  const { topLevelComponents, folderComponents } = folderfyComponents(
    colorsById,
    folders,
    isLoadingColors || isLoadingFolders,
    false,
    'color',
  );

  const folderEntries = sortFolderList(folderComponents);

  const filterBySearch = useCallback(
    (item: BrandKitColor) => {
      if (!searchTerm) return true;
      return item.name?.toLowerCase().includes(searchTerm.toLowerCase());
    },
    [searchTerm],
  );

  // Filter folder contents by search
  const filteredFolderEntries = useMemo(
    () =>
      folderEntries
        .map((folder: FolderInList) => {
          const filteredArray = Object.values(
            folder.items as unknown as Record<string, BrandKitColor>,
          )
            .filter(filterBySearch)
            .sort((a, b) => a.name.localeCompare(b.name));
          return {
            ...folder,
            items: Object.fromEntries(
              filteredArray.map((item) => [item.id, item]),
            ) as unknown as FolderInList['items'],
          };
        })
        .filter((folder: FolderInList) =>
          searchTerm ? Object.keys(folder.items).length > 0 : true,
        ),
    [folderEntries, filterBySearch, searchTerm],
  );

  // Filter top-level colors
  const filteredTopLevel = useMemo(() => {
    const topLevelArray = Object.values(
      topLevelComponents || {},
    ) as BrandKitColor[];
    return topLevelArray
      .filter(filterBySearch)
      .sort((a, b) => a.name.localeCompare(b.name));
  }, [topLevelComponents, filterBySearch]);

  const handleAddFolderClick = () => {
    suppressAutoFocusRef.current = true;
    setIsCreatingFolder(true);
  };

  const isLoading = isLoadingColors || isLoadingFolders;
  const hasFolders = filteredFolderEntries.length > 0;
  const hasTopLevel = filteredTopLevel.length > 0;
  const showCallout = !isLoading && !hasFolders && !hasTopLevel;

  if (isLoading) {
    return (
      <Flex width="100%" justify="center" py="6">
        <Spinner size="3" loading={true} />
      </Flex>
    );
  }

  return (
    <Flex direction="column" gap="2">
      <Flex direction="row" gap="2" mb="2">
        <form
          style={{ flexGrow: '1' }}
          onSubmit={(event: FormEvent<HTMLFormElement>) => {
            event.preventDefault();
          }}
        >
          <TextField.Root
            autoComplete="off"
            placeholder="Search…"
            radius="medium"
            aria-label="Search colors"
            size="1"
            value={searchTerm}
            onChange={(e) => {
              setSearchTerm(e.target.value);
            }}
          >
            <TextField.Slot>
              <MagnifyingGlassIcon height="16" width="16" />
            </TextField.Slot>
          </TextField.Root>
        </form>

        <DropdownMenu.Root>
          <DropdownMenu.Trigger>
            <Button
              ref={newButtonRef as React.RefObject<HTMLButtonElement>}
              variant="soft"
              size="1"
              data-testid="canvas-brand-kit-colors-new-button"
            >
              <PlusIcon />
              New
              <ChevronDownIcon />
            </Button>
          </DropdownMenu.Trigger>
          <DropdownMenu.Content
            onCloseAutoFocus={(e) => {
              // Prevent the dropdown from returning focus to the trigger
              // when we're creating a folder or opening a popover, so our
              // input/popover can receive focus.
              if (suppressAutoFocusRef.current) {
                e.preventDefault();
                suppressAutoFocusRef.current = false;
              }
            }}
          >
            <DropdownMenu.Item
              onClick={() => {
                suppressAutoFocusRef.current = true;
                setAddColorFolderId(undefined);
                setIsAddColorPopoverOpen(true);
              }}
              data-testid="canvas-brand-kit-colors-new-color-button"
            >
              <ColorWheelIcon />
              Color
            </DropdownMenu.Item>
            <DropdownMenu.Item
              onClick={handleAddFolderClick}
              data-testid="canvas-brand-kit-colors-new-folder-button"
            >
              <FolderIcon />
              Folder
            </DropdownMenu.Item>
          </DropdownMenu.Content>
        </DropdownMenu.Root>
      </Flex>

      {isCreatingFolder && (
        <FolderNameInput
          type="color"
          onSuccess={() => setIsCreatingFolder(false)}
          onCancel={() => setIsCreatingFolder(false)}
        />
      )}

      {(errorMessage ||
        foldersError ||
        deleteColorError ||
        updateColorError) && (
        <Text color="red" size="2">
          {errorMessage ||
            (foldersError &&
              parse(extractErrorMessageFromApiResponse(foldersError))) ||
            (deleteColorError && (
              <>
                Failed to delete color:{' '}
                {parse(extractErrorMessageFromApiResponse(deleteColorError))}
              </>
            )) ||
            (updateColorError && (
              <>
                Failed to update color:{' '}
                {parse(extractErrorMessageFromApiResponse(updateColorError))}
              </>
            ))}
        </Text>
      )}

      {showCallout && (
        <EmptyStateCallout
          my="3"
          title={
            searchTerm
              ? 'No results match your search.'
              : 'No colors added yet.'
          }
          description={
            searchTerm
              ? ''
              : 'Add colors to generate reusable CSS custom properties for the global brand kit.'
          }
        />
      )}

      {/* Render folders with filtered colors inside */}
      {hasFolders &&
        filteredFolderEntries.map((folder: FolderInList) => (
          <FolderList
            key={folder.id}
            folder={folder}
            deleteWarning="Cannot delete folder containing colors"
            extraMenuItems={
              <UnifiedMenu.Item
                onClick={() => {
                  setAddColorFolderId(folder.id);
                  setIsAddColorPopoverOpen(true);
                }}
                data-testid="canvas-brand-kit-colors-folder-add-color-button"
              >
                Add color
              </UnifiedMenu.Item>
            }
          >
            <Flex direction="column" pl="5">
              {Object.values(
                folder.items as unknown as Record<string, BrandKitColor>,
              ).map((color) => (
                <ColorRow key={color.id} color={color} />
              ))}
            </Flex>
          </FolderList>
        ))}

      {/* Render top-level colors not in folders */}
      <UncategorizedDropZone itemType="color" hasItems={hasTopLevel}>
        {filteredTopLevel.map((color) => (
          <ColorRow key={color.id} color={color} />
        ))}
      </UncategorizedDropZone>

      {/* Add color popover */}
      <ColorFormPopover
        operation="add"
        folderId={addColorFolderId}
        anchorRef={newButtonRef}
        align="start"
        open={isAddColorPopoverOpen}
        onOpenChange={setIsAddColorPopoverOpen}
      />
    </Flex>
  );
};

export default BrandKitColorsSection;
