import { useEffect, useRef, useState } from 'react';
import parse from 'html-react-parser';
import { Flex, Text, TextField } from '@radix-ui/themes';

import { folderNameInputStyle } from '@/components/list/FolderList';
import SidebarFolder from '@/components/sidePanel/SidebarFolder';
import { extractErrorMessageFromApiResponse } from '@/features/error-handling/error-handling';
import { validateFolderNameClientSide } from '@/features/validation/validation';
import { useCreateFolderMutation } from '@/services/componentAndLayout';

import type { RefObject } from 'react';
import type { FolderType } from '@/types/Component';

interface FolderNameInputProps {
  type: FolderType;
  inputRef: RefObject<HTMLInputElement>;
  /** Called with whether the keyboard ended the input. */
  onDone: (byKeyboard: boolean) => void;
}

const FolderNameInput = ({ type, inputRef, onDone }: FolderNameInputProps) => {
  // Enter and Escape end the input from the keyboard. A blur does not.
  const byKeyboardRef = useRef(false);
  const [folderName, setFolderName] = useState('New folder');
  const [validationError, setValidationError] = useState('');
  const [
    createFolder,
    { reset, isSuccess, isError, error, isLoading: isCreating },
  ] = useCreateFolderMutation();

  // Call onSuccess after the mutation state settles, then reset
  useEffect(() => {
    if (isSuccess) {
      reset();
      onDone(byKeyboardRef.current);
    }
  }, [isSuccess, reset, onDone]);

  useEffect(() => {
    if (isError) {
      console.error('Failed to add folder:', error);
      // The input lost focus while it was disabled. Give it back, unless the
      // user has moved on.
      if (byKeyboardRef.current) {
        inputRef.current?.focus();
      }
    }
  }, [isError, error, inputRef]);

  const cancel = () => {
    reset();
    onDone(byKeyboardRef.current);
  };

  const handleCreate = async () => {
    if (isCreating || isSuccess) return;

    const trimmedName = folderName.trim();
    if (!trimmedName || trimmedName === 'New folder' || validationError) {
      cancel();
      return;
    }

    try {
      await createFolder({ name: trimmedName, type }).unwrap();
    } catch {
      // Error UI uses `isError` / `error` from the mutation hook.
    }
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
    if (isCreating || isSuccess) return;
    byKeyboardRef.current = false;
    void handleCreate();
  };

  const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      byKeyboardRef.current = true;
      void handleCreate();
    } else if (e.key === 'Escape') {
      byKeyboardRef.current = true;
      cancel();
    }
  };

  // The row of a saved folder, with the name as an input, so that the new
  // folder does not move when it is saved.
  return (
    <div data-testid="canvas-manage-library-add-folder-content">
      <SidebarFolder
        id="new-folder"
        name="New folder"
        count={0}
        isDraggable={false}
        nameSlot={
          <TextField.Root
            ref={inputRef}
            data-testid="canvas-manage-library-new-folder-name"
            id="folder-name"
            placeholder="New folder"
            variant="soft"
            onChange={(e) => handleOnChange(e.target.value)}
            onBlur={handleBlur}
            onKeyDown={handleKeyDown}
            value={folderName}
            size="1"
            disabled={isCreating}
            aria-invalid={!!(validationError || isError)}
            style={folderNameInputStyle}
          />
        }
        errorSlot={
          (validationError || isError) && (
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
          )
        }
      />
    </div>
  );
};

/**
 * The "new folder" flow of every panel that lists folders.
 *
 * The panel puts `triggerRef` on the button of its "New" menu and
 * `onCloseAutoFocus` on the content of that menu, calls `start` from the
 * "Folder" item of that menu, and renders `input` below its toolbar.
 */
export const useNewFolder = (type: FolderType) => {
  const [isCreating, setIsCreating] = useState(false);
  const inputRef = useRef<HTMLInputElement>(null);
  const triggerRef = useRef<HTMLButtonElement>(null);
  // True from the "Folder" item until the menu has closed.
  const isStartingRef = useRef(false);

  return {
    triggerRef,
    start: () => {
      isStartingRef.current = true;
      setIsCreating(true);
    },
    // An open menu takes focus back from anything outside it, so the name is
    // focused when the menu has closed, in place of the menu's button.
    onCloseAutoFocus: (event: Event) => {
      if (isStartingRef.current) {
        isStartingRef.current = false;
        event.preventDefault();
        inputRef.current?.focus();
        inputRef.current?.select();
      }
    },
    input: isCreating ? (
      <FolderNameInput
        type={type}
        inputRef={inputRef}
        onDone={(byKeyboard) => {
          setIsCreating(false);
          // The input that had focus is gone. A user who clicked elsewhere
          // has focus there already.
          if (byKeyboard) {
            triggerRef.current?.focus();
          }
        }}
      />
    ) : null,
  };
};
