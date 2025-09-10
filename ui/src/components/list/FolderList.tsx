import { useState } from 'react';
import clsx from 'clsx';
import FolderIcon from '@assets/icons/folder.svg?react';
import * as Collapsible from '@radix-ui/react-collapsible';
import { ChevronRightIcon } from '@radix-ui/react-icons';
import { Flex, Text } from '@radix-ui/themes';

import type { ReactNode } from 'react';
import type { FolderCodeComponent } from '@/features/code-editor/CodeComponentList';
import type { CodeComponentSerialized } from '@/types/CodeComponent';
import type {
  ComponentsList,
  FolderInList,
  FoldersInList,
} from '@/types/Component';
import type { PatternsList } from '@/types/Pattern';

import listStyles from '@/components/list/List.module.css';

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
  folder: FolderInList | FolderCodeComponent;
  children: ReactNode;
}) => {
  const [isOpen, setIsOpen] = useState(true);

  // Determine the length of items in the folder, be it object or array.
  const getItemsLength = () => {
    if (Array.isArray(folder.items)) {
      return folder.items.length;
    }
    return Object.keys(folder.items).length;
  };

  return (
    <Collapsible.Root open={isOpen} onOpenChange={setIsOpen}>
      <Collapsible.Trigger
        className={clsx(listStyles.folderTrigger)}
        data-canvas-folder-name={folder.name}
      >
        <Flex flexGrow="1" align="center" overflow="hidden" pb="2" pt="2">
          <Flex pl="2" align="center" flexShrink="0">
            <FolderIcon className={listStyles.folderIcon} />
          </Flex>
          <Flex px="2" align="center" flexGrow="1" overflow="hidden">
            <Text size="1" weight="medium">
              {folder.name}
            </Text>
          </Flex>
          <Flex
            align="end"
            flexShrink="0"
            px="1"
            justify="center"
            className={listStyles.folderCount}
          >
            <Text size="1" weight="medium">
              {String(getItemsLength())}
            </Text>
          </Flex>
          <Flex pl="2" align="end" flexShrink="0">
            <ChevronRightIcon
              className={clsx(listStyles.chevron, {
                [listStyles.isOpen]: isOpen,
              })}
            />
          </Flex>
        </Flex>
      </Collapsible.Trigger>
      <Collapsible.Content>
        <Flex pl="5" direction="column">
          {children}
        </Flex>
      </Collapsible.Content>
    </Collapsible.Root>
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
  if (isLoading || foldersLoading || !folders || !components) {
    return { folderComponents: {}, topLevelComponents: {} };
  }

  const folderComponents: Record<string, FolderInList> = {};
  const topLevelComponents: Record<string, any> = {};

  Object.entries(components || {}).forEach(([id, component]) => {
    if (folders.componentIndexedFolders[id]) {
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
  Object.entries(folders.folders).forEach(([id, folder]) => {
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

export default FolderList;
