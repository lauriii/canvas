import { useEffect, useState } from 'react';
import parse from 'html-react-parser';
import FolderIcon from '@assets/icons/folder.svg?react';
import { Flex, Text, TextField } from '@radix-ui/themes';

import { extractErrorMessageFromApiResponse } from '@/features/error-handling/error-handling';
import { validateFolderNameClientSide } from '@/features/validation/validation';
import { useCreateFolderMutation } from '@/services/componentAndLayout';

import type { ReactNode } from 'react';
import type { FolderType } from '@/types/Component';

interface FolderNameInputProps {
  type: FolderType;
  onSuccess: () => void;
  onCancel: () => void;
  /** Optional content rendered to the right of the text field in the same row. */
  inputRowEnd?: ReactNode;
}

const FolderNameInput = ({
  type,
  onSuccess,
  onCancel,
  inputRowEnd,
}: FolderNameInputProps) => {
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
      onSuccess();
    }
  }, [isSuccess, reset, onSuccess]);

  useEffect(() => {
    if (isError) {
      console.error('Failed to add folder:', error);
    }
  }, [isError, error]);

  const cancel = () => {
    reset();
    onCancel();
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
    void handleCreate();
  };

  const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      void handleCreate();
    } else if (e.key === 'Escape') {
      cancel();
    }
  };

  return (
    <Flex
      align="center"
      gap="2"
      p="2"
      data-testid="canvas-manage-library-add-folder-content"
      style={{
        marginBottom: 'var(--space-2)',
      }}
    >
      <FolderIcon width="16" height="16" />
      <Flex direction="column" gap="1" style={{ flex: 1 }}>
        <Flex align="center" gap="2" style={{ width: '100%' }}>
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
            disabled={isCreating}
            style={{
              color: 'var(--accent-9)',
              border: 'none',
              background: 'transparent',
              width: '100%',
              flex: 1,
            }}
          />
          {inputRowEnd}
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
  );
};

export default FolderNameInput;
