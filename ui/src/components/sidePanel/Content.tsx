import { useMemo, useState } from 'react';
import NewTabIcon from '@assets/icons/new-tab.svg?react';
import {
  ChevronDownIcon,
  FileTextIcon,
  MagnifyingGlassIcon,
  PlusIcon,
} from '@radix-ui/react-icons';
import {
  Box,
  Button,
  DropdownMenu,
  Flex,
  Skeleton,
  TextField,
} from '@radix-ui/themes';

import EmptyStateCallout from '@/components/EmptyStateCallout';
import ErrorCard from '@/components/error/ErrorCard';
import TemplatedContentGroups from '@/components/sidePanel/TemplatedContentGroups';
import {
  getAllAddNewOptions,
  getTemplatedEntityGroups,
} from '@/features/navigator/templatedContent';
import { useGetContentTemplatesQuery } from '@/services/componentAndLayout';
import { getBaseUrl, getCanvasSettings } from '@/utils/drupal-globals';
import { getQueryErrorMessage } from '@/utils/error-handling';

import type { AddNewOption } from '@/features/navigator/templatedContent';

/**
 * The Content panel's "New" dropdown: content types editable in Canvas that the
 * user can create. Items open Drupal's creation form in a new tab.
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
 * The Content panel: lists entities of bundles with exposed slots, grouped by
 * content type and searchable. Selecting a row opens the entity in the editor.
 */
const Content = () => {
  const [searchTerm, setSearchTerm] = useState<string>('');
  const {
    data: contentTemplates,
    isLoading,
    error,
  } = useGetContentTemplatesQuery();
  const groups = useMemo(
    () => getTemplatedEntityGroups(contentTemplates),
    [contentTemplates],
  );
  // Derived from drupalSettings, which do not change during the session.
  const addNewOptions = useMemo(
    () =>
      getAllAddNewOptions(
        getCanvasSettings()?.contentEntityCreateOperations,
        getBaseUrl(),
      ),
    [],
  );

  // Loading and failure are distinct from a genuinely empty site: only the
  // latter gets the "No content found" state.
  let body: React.ReactNode;
  if (isLoading) {
    body = <Skeleton height="1.2rem" width="100%" my="3" />;
  } else if (error) {
    body = (
      <ErrorCard
        title="An unexpected error has occurred while loading content."
        error={getQueryErrorMessage(error)}
      />
    );
  } else if (groups.length === 0) {
    body = <EmptyStateCallout title="No content found" variant="surface" />;
  } else {
    body = <TemplatedContentGroups groups={groups} searchTerm={searchTerm} />;
  }

  return (
    <div data-testid="canvas-content-panel">
      <Flex direction="row" gap="2" mb="4" align="center">
        <Box flexGrow="1">
          <TextField.Root
            autoComplete="off"
            id="canvas-content-search"
            placeholder="Search content"
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
      {body}
    </div>
  );
};

export default Content;
