import { useState } from 'react';
import clsx from 'clsx';
import FolderIcon from '@assets/icons/folder.svg?react';
import { useDroppable } from '@dnd-kit/core';
import * as Collapsible from '@radix-ui/react-collapsible';
import { ChevronRightIcon, DotsHorizontalIcon } from '@radix-ui/react-icons';
import { ContextMenu, DropdownMenu, Flex, Text } from '@radix-ui/themes';

import { useAppSelector } from '@/app/hooks';
import UnifiedMenu from '@/components/UnifiedMenu';
import { selectDragging } from '@/features/ui/uiSlice';
import { usePermissionCheck } from '@/hooks/usePermissionCheck';

import type React from 'react';

import detailsStyles from '@/components/form/components/AccordionAndDetails.module.css';
import listStyles from '@/components/list/List.module.css';
import nodeStyles from '@/components/sidePanel/SidebarNode.module.css';

interface SidebarFolderProps {
  name: string;
  // Optional custom rendering for the name (e.g., TextField for inline editing)
  nameSlot?: React.ReactNode;
  // Optional slot for error messages if any
  errorSlot?: React.ReactNode;
  count?: number;
  // If menuItems are provided, wrap in ContextMenu and DropdownMenu
  menuItems?: React.ReactNode;
  className?: string;
  isOpen?: boolean;
  onOpenChange?: (open: boolean) => void;
  onNameDoubleClick?: () => void;
  children?: React.ReactNode;
  contextualMenuType?: 'dropdown' | 'context' | 'both';
  id: string;
}

const SidebarFolder: React.FC<SidebarFolderProps> = ({
  name,
  nameSlot,
  errorSlot,
  count,
  menuItems,
  className,
  isOpen: isOpenProp,
  onOpenChange,
  onNameDoubleClick,
  children,
  contextualMenuType = 'both',
  id,
}) => {
  const [isOpen, setIsOpen] = useState(isOpenProp ?? true);
  const { previewDragging } = useAppSelector(selectDragging);
  const administerFolders = usePermissionCheck({
    hasPermission: 'folders',
  });

  const { setNodeRef: setDropRef, isOver } = useDroppable({
    id,
    data: {
      destination: 'folder',
      accepts: ['library', 'code'],
    },
    // previewDragging is true when user drags from the editor frame - we disable dropping into folders in that case.
    disabled: !administerFolders || previewDragging,
  });
  const handleOpenChange = (open: boolean) => {
    setIsOpen(open);
    onOpenChange?.(open);
  };
  const folderRow = (
    <Flex
      data-canvas-folder-name={name}
      className={clsx(listStyles.folderTrigger, {
        [nodeStyles.contextualAccordionVariant]: menuItems,
      })}
      flexGrow="1"
      align={nameSlot ? 'start' : 'center'}
      overflow={nameSlot ? 'visible' : 'hidden'}
      pb="2"
      pt="2"
    >
      <Flex pl="2" align="center" flexShrink="0">
        <FolderIcon className={listStyles.folderIcon} />
      </Flex>

      <Flex
        px="2"
        align={nameSlot ? 'start' : 'center'}
        flexGrow="1"
        overflow="visible"
        role="button"
        onDoubleClick={onNameDoubleClick}
        style={{ minWidth: 0 }}
      >
        {nameSlot ? (
          nameSlot
        ) : (
          <Text size="1" weight="medium">
            {name}
          </Text>
        )}
      </Flex>

      {menuItems && contextualMenuType !== 'context' && (
        <Flex px="2" align="center" flexShrink="0">
          <DropdownMenu.Root>
            <DropdownMenu.Trigger>
              <button
                aria-label="Open contextual menu"
                className={nodeStyles.contextualTrigger}
              >
                <span className={nodeStyles.dots}>
                  <DotsHorizontalIcon />
                </span>
              </button>
            </DropdownMenu.Trigger>
            <UnifiedMenu.Content menuType="dropdown">
              {menuItems}
            </UnifiedMenu.Content>
          </DropdownMenu.Root>
        </Flex>
      )}
      {typeof count === 'number' && (
        <Flex
          align="end"
          flexShrink="0"
          px="1"
          justify="center"
          className={listStyles.folderCount}
        >
          <Text size="1" weight="medium">
            {String(count)}
          </Text>
        </Flex>
      )}
      <Collapsible.Trigger asChild>
        <Flex
          pl="2"
          align="end"
          flexShrink="0"
          role="button"
          aria-label={`${isOpen ? 'Collapse' : 'Expand'} ${name} folder`}
        >
          <ChevronRightIcon
            className={clsx(listStyles.chevron, {
              [listStyles.isOpen]: isOpen,
            })}
          />
        </Flex>
      </Collapsible.Trigger>
    </Flex>
  );

  let rowWithContextMenu = folderRow;
  if (menuItems && contextualMenuType !== 'dropdown') {
    rowWithContextMenu = (
      <ContextMenu.Root>
        <ContextMenu.Trigger>{folderRow}</ContextMenu.Trigger>
        <UnifiedMenu.Content menuType="context" align="start" side="right">
          {menuItems}
        </UnifiedMenu.Content>
      </ContextMenu.Root>
    );
  }

  const collapsibleFolder = (
    <Collapsible.Root open={isOpen} onOpenChange={handleOpenChange}>
      {rowWithContextMenu}
      <Collapsible.Content
        className={clsx(detailsStyles.content, detailsStyles.detailsContent)}
      >
        <Flex direction="column">{children}</Flex>
      </Collapsible.Content>
    </Collapsible.Root>
  );

  return (
    <div ref={setDropRef} className={clsx({ [listStyles.isOver]: isOver })}>
      {collapsibleFolder}
      {errorSlot}
    </div>
  );
};

export default SidebarFolder;
