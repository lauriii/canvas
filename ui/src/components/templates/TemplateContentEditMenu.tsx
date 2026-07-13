import { ChevronDownIcon, Pencil1Icon } from '@radix-ui/react-icons';
import { Button, DropdownMenu, Flex, Tooltip } from '@radix-ui/themes';

import useEditorNavigation from '@/hooks/useEditorNavigation';

import type React from 'react';
import type { ContentItem } from '@/components/templates/ContentPreviewSelector';

interface TemplateContentEditMenuProps {
  /** The bundle's entities, keyed by id (the preview-entity suggestions). */
  items?: { [key: string]: ContentItem };
  /** The entity type whose entities these are (e.g. `node`). */
  entityType?: string;
  /**
   * Whether the template exposes at least one active slot. Per-content editing
   * only exists for such templates, so the jump is hidden otherwise.
   */
  hasActiveExposedSlots: boolean;
}

/**
 * Template-editor cross-navigation: a menu of entities that use the current
 * template, each linking to editing that entity's slot content in the
 * per-content editor (`/editor/{entityType}/{id}`).
 *
 * Reuses the preview-entity suggestion endpoint (the same entities offered as
 * preview content) rather than a new listing. Complements the per-component
 * "Edit template" jump (LockedComponentPanel), closing the navigation loop
 * between a template and the entities it applies to (exposed-slots decision 6).
 */
const TemplateContentEditMenu: React.FC<TemplateContentEditMenuProps> = ({
  items = {},
  entityType,
  hasActiveExposedSlots,
}) => {
  const { navigateToEditor } = useEditorNavigation();
  const itemsArray = Object.values(items);

  // Nothing to jump to unless the template exposes slots and has entities.
  if (!hasActiveExposedSlots || !entityType || itemsArray.length === 0) {
    return null;
  }

  return (
    <DropdownMenu.Root>
      <Tooltip content="Edit this template's content" side="bottom">
        <DropdownMenu.Trigger>
          <Button
            variant="soft"
            size="1"
            color="gray"
            data-testid="template-edit-content-menu"
          >
            <Flex gap="2" align="center">
              <Pencil1Icon />
              <span>Edit content</span>
              <ChevronDownIcon />
            </Flex>
          </Button>
        </DropdownMenu.Trigger>
      </Tooltip>
      <DropdownMenu.Content>
        {itemsArray.map((item) => (
          <DropdownMenu.Item
            key={item.id}
            onSelect={() => navigateToEditor(entityType, item.id)}
            data-testid={`template-edit-content-item-${item.id}`}
          >
            {item.label}
          </DropdownMenu.Item>
        ))}
      </DropdownMenu.Content>
    </DropdownMenu.Root>
  );
};

export default TemplateContentEditMenu;
