import { useState } from 'react';
import { useParams } from 'react-router';
import {
  ChevronDownIcon,
  DotsVerticalIcon,
  ExternalLinkIcon,
  EyeOpenIcon,
} from '@radix-ui/react-icons';
import {
  Box,
  Button,
  DropdownMenu,
  Flex,
  IconButton,
  Popover,
  Text,
  Tooltip,
} from '@radix-ui/themes';

import { buildContentEditActions } from '@/features/navigator/templatedContent';
import useEditorNavigation from '@/hooks/useEditorNavigation';

import type React from 'react';

import styles from './ContentPreviewSelector.module.css';

interface ContentItem {
  id: string;
  label: string;
  editUrl?: string | null;
  manageFieldsUrl?: string | null;
}

interface ContentPreviewSelectorProps {
  items?: { [key: string]: ContentItem };
  selectedItemId?: string;
  onSelectionChange?: (entityId: string) => void;
}

const ContentPreviewSelector: React.FC<ContentPreviewSelectorProps> = ({
  items = {},
  selectedItemId,
  onSelectionChange,
}) => {
  const itemsArray = Object.values(items);
  const itemsCount = itemsArray.length;
  const [open, setOpen] = useState(false);

  // Default to first item if no selection and items are available
  const effectiveSelectedId =
    selectedItemId || (itemsCount > 0 ? itemsArray[0].id : undefined);
  const selectedItem = itemsArray.find(
    (item) => item.id === effectiveSelectedId,
  );

  // Cross-nav per entity: edit its exposed slots in Canvas, its content in the
  // CMS, or the bundle's fields. Each action is permission-gated by the server
  // (it omits URLs the user cannot use).
  const { entityType } = useParams();
  const { navigateToEditor } = useEditorNavigation();

  const handleItemSelect = (itemId: string) => {
    onSelectionChange?.(itemId);
    setOpen(false);
  };

  const triggerContent = (
    <Flex gap="2" align="center">
      <EyeOpenIcon />
      <span className={styles.triggerLabel}>
        {itemsCount === 0
          ? 'No content available'
          : (selectedItem?.label ?? 'Select content to preview')}
      </span>
      {itemsCount > 0 && <ChevronDownIcon />}
    </Flex>
  );

  if (itemsCount === 0) {
    return (
      <Tooltip content="Preview content" side="bottom">
        <Button variant="soft" size="1" disabled color="blue">
          {triggerContent}
        </Button>
      </Tooltip>
    );
  }

  // Mirrors the page navigator (PageInfo): a popover listing entities, each row
  // opening the entity in preview on click, with a "..." contextual menu for
  // the edit actions rather than a submenu.
  return (
    <Popover.Root open={open} onOpenChange={setOpen}>
      <Tooltip content="Preview content" side="bottom">
        <Popover.Trigger>
          <Button
            variant="soft"
            size="1"
            color="blue"
            data-testid="select-content-preview-item"
          >
            {triggerContent}
          </Button>
        </Popover.Trigger>
      </Tooltip>
      <Popover.Content size="1" width="360px" align="center">
        {/* Plain overflow Box, not Radix ScrollArea: the ScrollArea viewport
            wraps its children in a display:table element that shrink-wraps to
            content width, so a long label widens the row past the popover and
            clips each row's "..." menu off the right edge. A block Box lets the
            labels truncate (min-width:0 below) and keeps the "..." reachable. */}
        <Box className={styles.list}>
          <Flex direction="column" gap="1" width="100%" style={{ minWidth: 0 }}>
            {itemsArray.map((item) => {
              const editActions = buildContentEditActions(
                navigateToEditor,
                entityType,
                item,
              );
              return (
                <Flex key={item.id} align="center" className={styles.item}>
                  <button
                    type="button"
                    className={styles.itemLabel}
                    onClick={() => handleItemSelect(item.id)}
                    data-testid={`preview-item-${item.id}`}
                    data-selected={
                      item.id === effectiveSelectedId ? true : undefined
                    }
                    aria-current={
                      item.id === effectiveSelectedId ? true : undefined
                    }
                  >
                    <Text size="1" truncate>
                      {item.label}
                    </Text>
                  </button>
                  {editActions.length > 0 && (
                    <DropdownMenu.Root>
                      <DropdownMenu.Trigger>
                        <IconButton
                          variant="ghost"
                          color="gray"
                          size="1"
                          aria-label={`Options for ${item.label}`}
                          data-testid={`preview-item-options-${item.id}`}
                        >
                          <DotsVerticalIcon />
                        </IconButton>
                      </DropdownMenu.Trigger>
                      <DropdownMenu.Content>
                        {editActions.map((action) => (
                          <DropdownMenu.Item
                            key={action.key}
                            onSelect={() => {
                              // Close the selector before the action runs, so
                              // the popover is not left open after navigating
                              // or launching an external link.
                              setOpen(false);
                              action.run();
                            }}
                            data-testid={`preview-${action.key}-${item.id}`}
                          >
                            <Flex gap="2" align="center">
                              {action.label}
                              {action.external && <ExternalLinkIcon />}
                            </Flex>
                          </DropdownMenu.Item>
                        ))}
                      </DropdownMenu.Content>
                    </DropdownMenu.Root>
                  )}
                </Flex>
              );
            })}
          </Flex>
        </Box>
      </Popover.Content>
    </Popover.Root>
  );
};

export default ContentPreviewSelector;
export type { ContentItem, ContentPreviewSelectorProps };
