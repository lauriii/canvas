import { useCallback, useEffect, useRef, useState } from 'react';
import parse from 'html-react-parser';
import { Flex, Text, TextField } from '@radix-ui/themes';

import SidebarFolder from '@/components/sidePanel/SidebarFolder';
import { extractErrorMessageFromApiResponse } from '@/features/error-handling/error-handling';
import { validateFolderNameClientSide } from '@/features/validation/validation';
import { useUpdateFolderMutation } from '@/services/componentAndLayout';

import UnifiedMenu from '../UnifiedMenu';

import type { ReactNode } from 'react';
import type { CodeComponentSerialized } from '@/types/CodeComponent';
import type {
  ComponentsList,
  FolderInList,
  FoldersInList,
} from '@/types/Component';
import type { PatternsList } from '@/types/Pattern';

interface FolderData {
  componentIndexedFolders: Record<string, string>;
  folders: Record<
    string,
    { name?: string; weight?: number; [key: string]: any }
  >;
}

// Displays a list of components or patterns in a folder structure.
const FolderList = ({
  folder,
  children,
}: {
  folder: FolderInList;
  children: ReactNode;
}) => {
  const [isRenaming, setIsRenaming] = useState(false);
  const [folderName, setFolderName] = useState(folder.name);
  const [validationError, setValidationError] = useState('');
  const [isFolderOpen, setIsFolderOpen] = useState(true);
  const [updateFolder, { isLoading, isError, error, reset, isSuccess }] =
    useUpdateFolderMutation();
  const inputRef = useRef<HTMLInputElement>(null);
  const isSubmittingRef = useRef(false);

  useEffect(() => {
    if (isRenaming && inputRef.current) {
      inputRef.current.focus();
      inputRef.current.select();
    }
  }, [isRenaming]);

  // Sync local folderName state when the folder.name prop changes (e.g., after successful rename)
  useEffect(() => {
    setFolderName(folder.name);
  }, [folder.name]);

  useEffect(() => {
    if (isSuccess) {
      setIsRenaming(false);
      setValidationError('');
      isSubmittingRef.current = false;
      reset();
    }
  }, [isSuccess, reset, isRenaming]);

  useEffect(() => {
    if (isError) {
      console.error('Failed to rename folder:', error);
      isSubmittingRef.current = false;
      // Refocus the input so the user can correct the error or cancel via blur
      if (inputRef.current) {
        inputRef.current.focus();
      }
    }
  }, [isError, error]);

  // Determine the length of items in the folder, be it object or array.
  const getItemsLength = useCallback(() => {
    if (Array.isArray(folder.items)) {
      return folder.items.length;
    }
    return Object.keys(folder.items).length;
  }, [folder.items]);

  const cancelRename = useCallback(() => {
    setIsRenaming(false);
    setFolderName(folder.name);
    setValidationError('');
    isSubmittingRef.current = false;
    reset();
  }, [folder.name, reset]);

  const handleRename = useCallback(async () => {
    if (isSubmittingRef.current || isLoading) {
      return;
    }

    const trimmedName = folderName.trim();

    if (!trimmedName || trimmedName === folder.name || validationError) {
      cancelRename();
      return;
    }

    isSubmittingRef.current = true;

    const items = Array.isArray(folder.items)
      ? folder.items
      : Object.keys(folder.items);

    try {
      await updateFolder({
        id: folder.id,
        changes: { name: trimmedName, weight: folder.weight, items },
      }).unwrap();
    } catch {
      isSubmittingRef.current = false;
    }
  }, [
    isLoading,
    folderName,
    folder.name,
    folder.items,
    folder.id,
    folder.weight,
    validationError,
    cancelRename,
    updateFolder,
  ]);

  const handleOnChange = useCallback(
    (newName: string) => {
      setFolderName(newName);
      setValidationError(
        newName.trim() && newName.trim() !== folder.name
          ? validateFolderNameClientSide(newName)
          : '',
      );
    },
    [folder.name],
  );

  const handleBlur = useCallback(() => {
    if (isSubmittingRef.current || isLoading) {
      return;
    }
    cancelRename();
  }, [isLoading, cancelRename]);

  const handleKeyDown = useCallback(
    (e: React.KeyboardEvent<HTMLInputElement>) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        handleRename();
      } else if (e.key === 'Escape') {
        cancelRename();
      }
    },
    [handleRename, cancelRename],
  );

  const handleNameDoubleClick = useCallback(() => {
    // Only enable rename on double-click if not already renaming
    if (!isRenaming) {
      setIsRenaming(true);
    }
  }, [isRenaming]);

  const menuItems = (
    <UnifiedMenu.Item onClick={() => setIsRenaming(true)}>
      Rename
    </UnifiedMenu.Item>
  );

  // Create the nameSlot for inline editing when renaming
  const nameSlot = isRenaming ? (
    <TextField.Root
      ref={inputRef}
      value={folderName}
      onChange={(e) => handleOnChange(e.target.value)}
      onBlur={handleBlur}
      onKeyDown={handleKeyDown}
      disabled={isLoading}
      aria-invalid={!!(validationError || isError)}
      size="1"
      variant="soft"
      style={{
        width: '100%',
        maxWidth: '200px',
        minHeight: 0,
        height: 'auto',
        padding: 0,
        lineHeight: 'var(--line-height-1)',
      }}
      data-testid="canvas-folder-rename-input"
    />
  ) : undefined;

  const errorSlot =
    isRenaming && (validationError || isError) ? (
      <Flex direction="column" gap="1" px="2" pb="2">
        {validationError && (
          <Text size="1" color="red" weight="medium">
            {validationError}
          </Text>
        )}
        {isError && (
          <Text size="1" color="red" weight="medium">
            {parse(extractErrorMessageFromApiResponse(error))}
          </Text>
        )}
      </Flex>
    ) : undefined;

  return (
    <SidebarFolder
      id={folder.id}
      name={folderName}
      nameSlot={nameSlot}
      errorSlot={errorSlot}
      count={getItemsLength()}
      menuItems={isRenaming ? undefined : menuItems}
      isOpen={isFolderOpen}
      onOpenChange={setIsFolderOpen}
      onNameDoubleClick={handleNameDoubleClick}
    >
      {children}
    </SidebarFolder>
  );
};

export interface FolderComponentsResult {
  folderComponents: Record<string, FolderInList>;
  topLevelComponents: Record<string, any>;
}

// Take a list of components a list of all folders, both in the formats returned
// by componentAndLayoutApi, and return an object with folderComponents
// (structure of folders with the components inside them) and topLevelComponents
export const folderfyComponents = (
  components:
    | ComponentsList
    | PatternsList
    | Record<string, CodeComponentSerialized>
    | undefined,
  folders: FolderData | undefined,
  isLoading: boolean,
  foldersLoading: boolean,
  type: string,
): FolderComponentsResult => {
  if (isLoading || foldersLoading || (!folders && !components)) {
    return { folderComponents: {}, topLevelComponents: {} };
  }

  const folderComponents: Record<string, FolderInList> = {};
  const topLevelComponents: Record<string, any> = {};

  Object.entries(components || {}).forEach(([id, component]) => {
    if (folders && folders.componentIndexedFolders[id]) {
      const folderId = folders.componentIndexedFolders[id];
      if (!folderComponents[folderId]) {
        folderComponents[folderId] = {
          id: folderId,
          name: folders.folders[folderId]?.name || 'Unknown folder',
          items: {},
          weight: folders.folders[folderId]?.weight || 0,
        };
      }
      folderComponents[folderId].items[id] = component;
    } else {
      topLevelComponents[id] = component;
    }
  });
  Object.entries(folders?.folders || []).forEach(([id, folder]) => {
    if (folder.items.length === 0 && folder.type === type) {
      folderComponents[id] = {
        id,
        name: folder.name || '',
        items: {} as ComponentsList,
        weight: folder.weight || 0,
      };
    }
  });
  return { folderComponents, topLevelComponents };
};

export const sortFolderList = (
  folderComponents: Record<string, FolderInList>,
): FoldersInList => {
  // Sorts the folders first by weight, then by name within the weights.
  return folderComponents
    ? (Object.values(folderComponents).sort(
        (a: FolderInList, b: FolderInList) => {
          const aWeight = a?.weight || 0;
          const bWeight = b?.weight || 0;
          if (aWeight !== bWeight) {
            return aWeight - bWeight;
          }
          const aName = a?.name || '';
          const bName = b?.name || '';
          return aName.localeCompare(bName);
        },
      ) as FoldersInList)
    : [];
};

export type { FolderData };

export default FolderList;
