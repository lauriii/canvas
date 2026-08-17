import { useRef, useState } from 'react';
import FolderIcon from '@assets/icons/folder.svg?react';
import {
  ChevronDownIcon,
  MagnifyingGlassIcon,
  PlusIcon,
} from '@radix-ui/react-icons';
import { Button, DropdownMenu, Flex, Text, TextField } from '@radix-ui/themes';

import PermissionCheck from '@/components/PermissionCheck';
import FolderNameInput from '@/features/brandKit/components/FolderNameInput';
import AddCodeComponentButton from '@/features/code-editor/AddCodeComponentButton';

import type { FormEvent } from 'react';
import type { FolderType } from '@/types/Component';

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

  // Suppresses the DropdownMenu's onCloseAutoFocus focus-return so the
  // folder name input can receive focus instead.
  const suppressAutoFocusRef = useRef(false);

  const handleAddFolderClick = () => {
    suppressAutoFocusRef.current = true;
    setIsCreatingFolder(true);
    onFolderCreating?.(true);
  };

  const handleFolderDone = () => {
    setIsCreatingFolder(false);
    onFolderCreating?.(false);
  };

  return (
    <>
      <Flex direction="row" gap="2" mb="4">
        <form
          style={{ flexGrow: '1' }}
          onSubmit={(event: FormEvent<HTMLFormElement>) => {
            event.preventDefault();
          }}
        >
          <TextField.Root
            autoComplete="off"
            id="canvas-navigation-search"
            placeholder={Drupal.t('Search…')}
            radius="medium"
            aria-label={Drupal.t('Search content')}
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
                  {Drupal.t('New', {}, { context: 'Canvas library toolbar' })}
                  <ChevronDownIcon />
                </Button>
              </DropdownMenu.Trigger>
              <DropdownMenu.Content
                onCloseAutoFocus={(e) => {
                  // Prevent the dropdown from returning focus to the trigger
                  // when we're creating a folder, so our input can receive focus.
                  if (suppressAutoFocusRef.current) {
                    e.preventDefault();
                    suppressAutoFocusRef.current = false;
                  }
                }}
              >
                <PermissionCheck hasPermission="codeComponents">
                  <AddCodeComponentButton />
                </PermissionCheck>
                <PermissionCheck hasPermission="folders">
                  <DropdownMenu.Item
                    onClick={handleAddFolderClick}
                    data-testid="canvas-library-new-folder-button"
                  >
                    <FolderIcon />
                    {Drupal.t(
                      'Folder',
                      {},
                      { context: 'Canvas library toolbar' },
                    )}
                  </DropdownMenu.Item>
                </PermissionCheck>
              </DropdownMenu.Content>
            </DropdownMenu.Root>
          </PermissionCheck>
        )}
      </Flex>
      {isCreatingFolder && (
        <FolderNameInput
          type={type}
          onSuccess={handleFolderDone}
          onCancel={handleFolderDone}
          inputRowEnd={
            <Flex align="center" gap="1">
              <Text size="1" color="gray">
                0
              </Text>
              <ChevronDownIcon width="12" height="12" color="gray" />
            </Flex>
          }
        />
      )}
    </>
  );
};

export default LibraryToolbar;
