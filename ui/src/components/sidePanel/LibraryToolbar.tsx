import FolderIcon from '@assets/icons/folder.svg?react';
import {
  ChevronDownIcon,
  MagnifyingGlassIcon,
  PlusIcon,
} from '@radix-ui/react-icons';
import { Button, DropdownMenu, Flex, TextField } from '@radix-ui/themes';

import { useNewFolder } from '@/components/list/useNewFolder';
import PermissionCheck from '@/components/PermissionCheck';
import AddCodeComponentButton from '@/features/code-editor/AddCodeComponentButton';

import type { FormEvent } from 'react';
import type { FolderType } from '@/types/Component';

interface ManageLibraryToolbarProps {
  type: FolderType;
  searchTerm: string;
  onSearch: (term: string) => void;
  showNewMenu?: boolean;
}

const LibraryToolbar = ({
  type,
  searchTerm,
  onSearch,
  showNewMenu,
}: ManageLibraryToolbarProps) => {
  const newFolder = useNewFolder(type);

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
              <DropdownMenu.Content
                onCloseAutoFocus={newFolder.onCloseAutoFocus}
              >
                <PermissionCheck hasPermission="codeComponents">
                  <AddCodeComponentButton />
                </PermissionCheck>
                <PermissionCheck hasPermission="folders">
                  <DropdownMenu.Item
                    onClick={newFolder.start}
                    data-testid="canvas-library-new-folder-button"
                  >
                    <FolderIcon />
                    Folder
                  </DropdownMenu.Item>
                </PermissionCheck>
              </DropdownMenu.Content>
            </DropdownMenu.Root>
          </PermissionCheck>
        )}
      </Flex>
      {newFolder.input}
    </>
  );
};

export default LibraryToolbar;
