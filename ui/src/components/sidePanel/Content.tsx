import { useMemo, useState } from 'react';
import NewTabIcon from '@assets/icons/new-tab.svg?react';
import {
  ChevronDownIcon,
  FileTextIcon,
  MagnifyingGlassIcon,
  PlusIcon,
} from '@radix-ui/react-icons';
import { Box, Button, DropdownMenu, Flex, TextField } from '@radix-ui/themes';

import EmptyStateCallout from '@/components/EmptyStateCallout';
import TemplatedContentGroups from '@/components/sidePanel/TemplatedContentGroups';
import {
  getAllAddNewOptions,
  getTemplatedEntityGroups,
} from '@/features/navigator/templatedContent';
import { useGetContentTemplatesQuery } from '@/services/componentAndLayout';
import { getBaseUrl, getCanvasSettings } from '@/utils/drupal-globals';

import type { AddNewOption } from '@/features/navigator/templatedContent';

/**
 * The Content panel's "New" control, shown next to the search field and modeled
 * on the Pages panel's New button: a dropdown listing every content type
 * editable in Canvas that the user can create (not just bundles with an active
 * exposed slot). Each item links out to Drupal's own creation form (new tab)
 * for v1. Renders nothing when nothing is creatable.
 */
const AddNewContent = ({ options }: { options: AddNewOption[] }) => {
  if (options.length === 0) {
    return null;
  }

  return (
    <DropdownMenu.Root>
      <DropdownMenu.Trigger>
        <Button variant="soft" size="1" data-testid="canvas-content-new-button">
          <PlusIcon />
          New
          <ChevronDownIcon />
        </Button>
      </DropdownMenu.Trigger>
      <DropdownMenu.Content>
        {options.map((option) => (
          <DropdownMenu.Item
            key={option.url}
            onClick={() =>
              window.open(option.url, '_blank', 'noopener,noreferrer')
            }
            data-testid={`canvas-content-new-${option.bundle}`}
          >
            <FileTextIcon />
            {option.label}
            <Flex ml="auto" align="center">
              <NewTabIcon />
            </Flex>
          </DropdownMenu.Item>
        ))}
      </DropdownMenu.Content>
    </DropdownMenu.Root>
  );
};

/**
 * The "Content" panel (the CMS panel): browses entities of templated bundles
 * with active exposed slots, grouped by content type and searchable, so editors
 * move between them without leaving Canvas (exposed-slots decision 6). A sibling
 * of the Pages navigator, not folded into it. Selecting a row opens the entity
 * in the per-content editor. The Figma's "Manage" and per-group "View all"
 * affordances (an expanded management view) are out of scope for v1.
 */
const Content = () => {
  const [searchTerm, setSearchTerm] = useState<string>('');
  const { data: contentTemplates } = useGetContentTemplatesQuery();
  const groups = useMemo(
    () => getTemplatedEntityGroups(contentTemplates),
    [contentTemplates],
  );
  const addNewOptions = getAllAddNewOptions(
    getCanvasSettings()?.contentEntityCreateOperations,
    getBaseUrl(),
  );

  return (
    <div data-testid="canvas-content-panel">
      <Flex direction="row" gap="2" mb="4" align="center">
        <Box flexGrow="1">
          <TextField.Root
            autoComplete="off"
            id="canvas-content-search"
            placeholder="Search Content"
            radius="medium"
            aria-label="Search content"
            size="1"
            value={searchTerm}
            onChange={(event) => setSearchTerm(event.target.value)}
          >
            <TextField.Slot>
              <MagnifyingGlassIcon height="16" width="16" />
            </TextField.Slot>
          </TextField.Root>
        </Box>
        <AddNewContent options={addNewOptions} />
      </Flex>
      {groups.length === 0 ? (
        <EmptyStateCallout title="No content found" variant="surface" />
      ) : (
        <TemplatedContentGroups groups={groups} searchTerm={searchTerm} />
      )}
    </div>
  );
};

export default Content;
