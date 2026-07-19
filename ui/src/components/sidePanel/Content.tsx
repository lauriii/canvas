import { useEffect, useMemo, useState } from 'react';
import { useErrorBoundary } from 'react-error-boundary';
import { useNavigate } from 'react-router-dom';
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
import useEditorNavigation from '@/hooks/useEditorNavigation';
import { useGetContentTemplatesQuery } from '@/services/componentAndLayout';
import { useCreateContentMutation } from '@/services/content';
import { getCanvasSettings } from '@/utils/drupal-globals';

import type { AddNewOption } from '@/features/navigator/templatedContent';

/**
 * The Content panel's "New" control, shown next to the search field and modeled
 * on the Pages panel's New button: a dropdown listing every content type
 * editable in Canvas that the user can create. Choosing a bundle creates an
 * unpublished draft in Canvas and opens it in the editor; there is no link-out
 * to Drupal's add form. Renders nothing when nothing is creatable.
 */
const AddNewContent = ({
  options,
  onAdd,
}: {
  options: AddNewOption[];
  onAdd: (option: AddNewOption) => void;
}) => {
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
            key={`${option.entityType}:${option.bundle}`}
            onClick={() => onAdd(option)}
            data-testid={`canvas-content-new-${option.bundle}`}
          >
            <FileTextIcon />
            {option.label}
          </DropdownMenu.Item>
        ))}
      </DropdownMenu.Content>
    </DropdownMenu.Root>
  );
};

/**
 * The "Content" panel (the CMS panel): browses entities of templated bundles
 * (any bundle with an enabled full-view template, exposed slots or not),
 * grouped by content type and searchable, so editors move between them without
 * leaving Canvas. A sibling of the Pages navigator, not folded into it.
 * Selecting a row opens the entity in the per-content editor; "View all
 * content" opens the full content browser.
 */
const Content = () => {
  const [searchTerm, setSearchTerm] = useState<string>('');
  const navigate = useNavigate();
  const { showBoundary } = useErrorBoundary();
  const { navigateToEditor } = useEditorNavigation();
  const {
    data: contentTemplates,
    isLoading,
    error,
  } = useGetContentTemplatesQuery();
  const groups = useMemo(
    () => getTemplatedEntityGroups(contentTemplates),
    [contentTemplates],
  );
  const addNewOptions = getAllAddNewOptions(
    getCanvasSettings()?.contentEntityCreateOperations,
  );

  const [
    createContent,
    {
      data: createContentData,
      error: createContentError,
      isSuccess: isCreateContentSuccess,
    },
  ] = useCreateContentMutation();

  const handleAddNew = (option: AddNewOption) => {
    createContent({
      entity_type: option.entityType,
      bundle: option.bundle,
    });
  };

  useEffect(() => {
    if (isCreateContentSuccess) {
      navigateToEditor(
        createContentData.entity_type,
        createContentData.entity_id,
      );
    }
  }, [isCreateContentSuccess, createContentData, navigateToEditor]);

  useEffect(() => {
    if (createContentError) {
      showBoundary(createContentError);
    }
  }, [createContentError, showBoundary]);

  // Loading and failure are distinct from a genuinely empty site: only the
  // latter gets the "No content found" state.
  let body: React.ReactNode;
  if (isLoading) {
    body = <Skeleton height="1.2rem" width="100%" my="3" />;
  } else if (error) {
    body = (
      <ErrorCard
        title="An unexpected error has occurred while loading content."
        error={String(error)}
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
        <AddNewContent options={addNewOptions} onAdd={handleAddNew} />
      </Flex>
      {body}
      <Flex mt="4" justify="start">
        <Button
          variant="ghost"
          size="1"
          data-testid="canvas-content-panel-view-all"
          onClick={() => navigate('/content')}
        >
          View all content
        </Button>
      </Flex>
    </div>
  );
};

export default Content;
