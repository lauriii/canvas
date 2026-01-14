import { useEffect, useRef, useState } from 'react';
import parse from 'html-react-parser';
import FolderIcon from '@assets/icons/folder.svg?react';
import {
  ChevronDownIcon,
  MagnifyingGlassIcon,
  PlusIcon,
} from '@radix-ui/react-icons';
import { Button, DropdownMenu, Flex, Text, TextField } from '@radix-ui/themes';

import PermissionCheck from '@/components/PermissionCheck';
import AddCodeComponentButton from '@/features/code-editor/AddCodeComponentButton';
import { extractErrorMessageFromApiResponse } from '@/features/error-handling/error-handling';
import { validateFolderNameClientSide } from '@/features/validation/validation';
import { useCreateFolderMutation } from '@/services/componentAndLayout';
import { getCanvasSettings } from '@/utils/drupal-globals';

import type { FormEvent } from 'react';

type FolderType = 'component' | 'pattern' | 'js_component';

interface ManageLibraryToolbarProps {
  type: FolderType;
  searchTerm: string;
  onSearch: (term: string) => void;
  showNewMenu?: boolean;
  onFolderCreating?: (isCreating: boolean) => void;
}

const LibraryToolbar = ({
  type,
  searchTerm,
  onSearch,
  showNewMenu,
  onFolderCreating,
}: ManageLibraryToolbarProps) => {
  const [isCreatingFolder, setIsCreatingFolder] = useState(false);
  const [folderName, setFolderName] = useState('New folder');
  const [validationError, setValidationError] = useState('');
  const [createFolder, { reset, isSuccess, isError, error, isLoading }] =
    useCreateFolderMutation();
  const canvasSettings = getCanvasSettings();
  const textFieldRef = useRef<HTMLDivElement>(null);
  const isSubmittingRef = useRef(false);
  const shouldFocusInputRef = useRef(false);

  useEffect(() => {
    if (isCreatingFolder) {
      // Use setTimeout to select the text after the component has rendered
      // and autoFocus has taken effect.
      const timeoutId = setTimeout(() => {
        const inputElement = textFieldRef.current?.querySelector('input');
        if (inputElement) {
          inputElement.focus();
          inputElement.select();
        }
      }, 0);
      return () => clearTimeout(timeoutId);
    }
  }, [isCreatingFolder]);

  useEffect(() => {
    if (isSuccess) {
      setFolderName('New folder');
      setIsCreatingFolder(false);
      setValidationError('');
      isSubmittingRef.current = false;
      onFolderCreating?.(false);
      reset();
    }
  }, [isSuccess, reset, onFolderCreating]);

  useEffect(() => {
    if (isError) {
      console.error('Failed to add folder:', error);
      isSubmittingRef.current = false;
    }
  }, [isError, error]);

  const cancelFolderCreation = () => {
    setIsCreatingFolder(false);
    setFolderName('New folder');
    setValidationError('');
    reset();
    isSubmittingRef.current = false;
    onFolderCreating?.(false);
  };

  const handleCreateFolder = async () => {
    if (isSubmittingRef.current || isLoading) {
      return;
    }

    const trimmedName = folderName.trim();

    if (!trimmedName || trimmedName === 'New folder' || validationError) {
      cancelFolderCreation();
      return;
    }

    isSubmittingRef.current = true;

    await createFolder({
      name: trimmedName,
      type: type,
    });
  };

  const handleOnChange = (newName: string) => {
    setFolderName(newName);
    reset();
    setValidationError(
      newName.trim() && newName.trim() !== 'New folder'
        ? validateFolderNameClientSide(newName)
        : '',
    );
  };

  const handleBlur = () => {
    handleCreateFolder();
  };

  const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      handleCreateFolder();
    } else if (e.key === 'Escape') {
      cancelFolderCreation();
    }
  };

  const handleAddFolderClick = () => {
    shouldFocusInputRef.current = true;
    setIsCreatingFolder(true);
    onFolderCreating?.(true);
  };

  return (
    <>
      <Flex direction="row" gap="2" mb="4">
        <form
          style={{
            flexGrow: '1',
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
            value={searchTerm}
            onChange={(e) => onSearch(e.target.value)}
          >
            <TextField.Slot>
              <MagnifyingGlassIcon height="16" width="16" />
            </TextField.Slot>
          </TextField.Root>
        </form>
        {showNewMenu && (
          <PermissionCheck hasPermissions={['codeComponents', 'folders']}>
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
              <DropdownMenu.Content
                onCloseAutoFocus={(e) => {
                  // Prevent the dropdown from returning focus to the trigger
                  // when we're creating a folder, so our input can receive focus.
                  if (shouldFocusInputRef.current) {
                    e.preventDefault();
                    shouldFocusInputRef.current = false;
                  }
                }}
              >
                <PermissionCheck hasPermission="codeComponents">
                  <AddCodeComponentButton />
                </PermissionCheck>
                {canvasSettings.devMode && (
                  <PermissionCheck hasPermission="folders">
                    <DropdownMenu.Item
                      onClick={handleAddFolderClick}
                      data-testid="canvas-library-new-folder-button"
                    >
                      <FolderIcon />
                      Add folder
                    </DropdownMenu.Item>
                  </PermissionCheck>
                )}
              </DropdownMenu.Content>
            </DropdownMenu.Root>
          </PermissionCheck>
        )}
      </Flex>
      {isCreatingFolder && canvasSettings.devMode && (
        <Flex
          align="center"
          gap="2"
          p="2"
          data-testid="xb-manage-library-add-folder-content"
          style={{
            marginBottom: 'var(--space-2)',
          }}
        >
          <FolderIcon width="16" height="16" />
          <Flex direction="column" gap="1" style={{ flex: 1 }}>
            <Flex align="center" gap="2" style={{ width: '100%' }}>
              <span ref={textFieldRef} style={{ flex: 1 }}>
                <TextField.Root
                  autoFocus
                  data-testid="canvas-manage-library-new-folder-name"
                  id="folder-name"
                  placeholder="New folder"
                  variant="soft"
                  onChange={(e) => handleOnChange(e.target.value)}
                  onBlur={handleBlur}
                  onKeyDown={handleKeyDown}
                  value={folderName}
                  size="1"
                  disabled={isLoading}
                  style={{
                    color: 'var(--accent-9)',
                    border: 'none',
                    background: 'transparent',
                    width: '100%',
                  }}
                />
              </span>
              <Flex align="center" gap="1">
                <Text size="1" color="gray">
                  0
                </Text>
                <ChevronDownIcon width="12" height="12" color="gray" />
              </Flex>
            </Flex>
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
        </Flex>
      )}
    </>
  );
};

export default LibraryToolbar;
