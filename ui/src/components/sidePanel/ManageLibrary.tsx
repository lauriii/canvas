import { useEffect, useState } from 'react';
import parse from 'html-react-parser';
import { Form } from 'radix-ui';
import { PlusIcon } from '@radix-ui/react-icons';
import { Box, Button, Flex, Tabs, Text, TextField } from '@radix-ui/themes';

import Dialog from '@/components/Dialog';
import ComponentList from '@/components/list/ComponentList';
import PatternList from '@/components/list/PatternList';
import PermissionCheck from '@/components/PermissionCheck';
import { DisplayContext } from '@/components/sidePanel/DisplayContext';
import CodeComponentList from '@/features/code-editor/CodeComponentList';
import { extractErrorMessageFromApiResponse } from '@/features/error-handling/error-handling';
import { validateFolderNameClientSide } from '@/features/validation/validation';
import { useCreateFolderMutation } from '@/services/componentAndLayout';

import styles from '@/components/sidePanel/ManageLibrary.module.css';

const ManageLibrary = () => {
  return (
    <DisplayContext.Provider value="manage-library">
      <div className="flex flex-col h-full">
        <Tabs.Root defaultValue="components">
          <Tabs.List justify="start" mt="-2" size="1">
            <Tabs.Trigger
              value="components"
              data-testid="canvas-manage-library-components-tab-select"
            >
              Components
            </Tabs.Trigger>
            <Tabs.Trigger
              value="patterns"
              data-testid="canvas-manage-library-patterns-tab-select"
            >
              Patterns
            </Tabs.Trigger>
            <PermissionCheck hasPermission="codeComponents">
              <Tabs.Trigger
                value="code"
                data-testid="canvas-manage-library-code-tab-select"
              >
                Code
              </Tabs.Trigger>
            </PermissionCheck>
          </Tabs.List>
          <Flex py="2" className={styles.tabWrapper}>
            <Tabs.Content
              value={'components'}
              className={styles.tabContent}
              data-testid="canvas-manage-library-components-tab-content"
            >
              <AddFolderButton type="component" />
              <ComponentList />
            </Tabs.Content>
            <Tabs.Content
              value={'patterns'}
              className={styles.tabContent}
              data-testid="canvas-manage-library-patterns-tab-content"
            >
              <AddFolderButton type="pattern" />
              <PatternList />
            </Tabs.Content>
            <PermissionCheck hasPermission="codeComponents">
              <Tabs.Content
                value={'code'}
                className={styles.tabContent}
                data-testid="canvas-manage-library-code-tab-content"
              >
                <AddFolderButton type="js_component" />
                <CodeComponentList />
              </Tabs.Content>
            </PermissionCheck>
          </Flex>
        </Tabs.Root>
      </div>
    </DisplayContext.Provider>
  );
};

type FolderType = 'component' | 'pattern' | 'js_component';

const AddFolderButton = ({ type }: { type: FolderType }) => {
  const [isOpen, setIsOpen] = useState(false);
  const [folderName, setFolderName] = useState('');
  const [validationError, setValidationError] = useState('');
  const [createFolder, { reset, isSuccess, isError, error, isLoading }] =
    useCreateFolderMutation();

  const handleCreateFolder = async () => {
    await createFolder({
      name: folderName,
      type: type,
    });
  };

  useEffect(() => {
    if (isError) {
      console.error('Failed to add folder:', error);
    }
  }, [isError, error]);

  useEffect(() => {
    if (isSuccess) {
      setFolderName('');
      setIsOpen(false);
      reset();
    }
  }, [isSuccess, reset]);

  const handleOnChange = (newName: string) => {
    setFolderName(newName);
    setValidationError(
      newName.trim() ? validateFolderNameClientSide(newName) : '',
    );
  };

  return (
    <Flex className={styles.tabContent}>
      <Button
        data-testid="add-new-folder-button"
        className={styles.addFolderButton}
        my="2"
        variant="soft"
        size="1"
        disabled={isOpen}
        onClick={() => setIsOpen(true)}
      >
        <PlusIcon />
        Add new folder
      </Button>
      {isOpen && (
        <Dialog
          open={isOpen}
          title="Add new folder"
          onOpenChange={(open) => setIsOpen(open)}
          error={
            isError
              ? {
                  title: 'Failed to add new folder',
                  message: parse(extractErrorMessageFromApiResponse(error)),
                  resetButtonText: 'Try again',
                  onReset: handleCreateFolder,
                }
              : undefined
          }
          footer={{
            cancelText: 'Cancel',
            confirmText: 'Add',
            onConfirm: handleCreateFolder,
            isConfirmDisabled: !folderName.trim() || !!validationError,
            isConfirmLoading: isLoading,
          }}
        >
          <Box pb="3" m="0" data-testid="xb-manage-library-add-folder-content">
            {isOpen && (
              <Form.Root
                onSubmit={(e) => {
                  e.preventDefault();
                  if (folderName.trim() && !validationError) {
                    handleCreateFolder();
                  }
                }}
                id="add-new-folder-in-tab-form"
              >
                <Form.Field name="folder-name">
                  <Form.Label htmlFor="folder-name">
                    <Text weight="medium" size="1">
                      Folder name
                    </Text>
                  </Form.Label>
                  <TextField.Root
                    data-testid="canvas-manage-library-new-folder-name"
                    id="folder-name"
                    variant="surface"
                    onChange={(e) => handleOnChange(e.target.value)}
                    value={folderName}
                    size="1"
                  />
                  {validationError && (
                    <Text size="1" color="red" weight="medium">
                      {validationError}
                    </Text>
                  )}
                </Form.Field>
              </Form.Root>
            )}
          </Box>
        </Dialog>
      )}
    </Flex>
  );
};

export default ManageLibrary;
