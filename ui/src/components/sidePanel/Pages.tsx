import { useEffect, useState } from 'react';
import { useErrorBoundary } from 'react-error-boundary';
import { useParams } from 'react-router-dom';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import PageList from '@/components/pageInfo/PageList';
import {
  extractHomepagePathFromStagedConfig,
  selectHomepagePath,
  selectHomepageStagedConfigExists,
  setHomepagePath,
} from '@/features/configuration/configurationSlice';
import useEditorNavigation from '@/hooks/useEditorNavigation';
import { usePaginatedContentList } from '@/hooks/usePaginatedContentList';
import { useSmartRedirect } from '@/hooks/useSmartRedirect';
import { componentAndLayoutApi } from '@/services/componentAndLayout';
import {
  useCreateContentMutation,
  useDeleteContentMutation,
  useGetStagedConfigQuery,
  useSetStagedConfigMutation,
  useUpdateContentMutation,
} from '@/services/content';
import { getCanvasSettings } from '@/utils/drupal-globals';

import type { ContentStub } from '@/types/Content';

const canvasSettings = getCanvasSettings();
export const HOMEPAGE_CONFIG_ID = 'canvas_set_homepage';

const Pages = () => {
  const { showBoundary } = useErrorBoundary();
  const { navigateToEditor } = useEditorNavigation();
  const { redirectToNextBestPage } = useSmartRedirect();
  const dispatch = useAppDispatch();
  const { entityType, entityId } = useParams();
  const [searchTerm, setSearchTerm] = useState<string>('');

  const canCreatePages =
    !!canvasSettings.contentEntityCreateOperations?.canvas_page?.canvas_page;

  const {
    items: pageItems,
    isLoading: isPageItemsLoading,
    error: pageItemsError,
    hasMore,
    handleLoadMore,
  } = usePaginatedContentList('canvas_page', searchTerm);

  const [
    createContent,
    {
      data: createContentData,
      error: createContentError,
      isSuccess: isCreateContentSuccess,
    },
  ] = useCreateContentMutation();
  const homepagePath = useAppSelector(selectHomepagePath);
  const homepageStagedConfigExists = useAppSelector(
    selectHomepageStagedConfigExists,
  );
  const { data: homepageConfig, isSuccess: isGetStagedUpdateSuccess } =
    useGetStagedConfigQuery(HOMEPAGE_CONFIG_ID, {
      // Only fetch the homepage staged config if it exists to avoid
      // unnecessary API calls that return 404s.
      skip: !homepageStagedConfigExists,
    });

  const [deleteContent, { error: deleteContentError }] =
    useDeleteContentMutation();
  const [updateContent, { error: updateContentError }] =
    useUpdateContentMutation();
  const [setHomepage, { error: setHomepageError }] =
    useSetStagedConfigMutation();

  function handleNewPage() {
    createContent({
      entity_type: 'canvas_page',
    });
  }

  async function handleDeletePage(item: ContentStub) {
    const pageToDeleteId = String(item.id);
    await deleteContent({
      entityType: 'canvas_page',
      entityId: pageToDeleteId,
    });

    // If the current page is the one being deleted, redirect using smart logic
    if (entityType === 'canvas_page' && entityId === pageToDeleteId) {
      redirectToNextBestPage(pageToDeleteId);
    }

    // Keep local storage tidy and clear out the array of collapsed layers for the deleted item.
    window.localStorage.removeItem(
      `Canvas.collapsedLayers.canvas_page.${pageToDeleteId}`,
    );
  }

  function handleDuplication(item: ContentStub) {
    createContent({
      entity_type: 'canvas_page',
      entity_id: String(item.id),
    });
  }

  function handleOnSelect(item: ContentStub) {
    navigateToEditor('canvas_page', item.id);
  }

  async function handleUnpublishPage(item: ContentStub) {
    const pageToUnpublishId = String(item.id);
    await updateContent({
      entityType: 'canvas_page',
      entityId: pageToUnpublishId,
      status: false,
    });

    // If the current page is being unpublished, invalidate the layout cache to refetch with updated hasUnsavedStatusChange
    if (entityType === 'canvas_page' && entityId === pageToUnpublishId) {
      dispatch(componentAndLayoutApi.util.invalidateTags([{ type: 'Layout' }]));
    }
  }

  async function handlePublishPage(item: ContentStub) {
    const pageToPublishId = String(item.id);
    await updateContent({
      entityType: 'canvas_page',
      entityId: pageToPublishId,
      status: true,
    });

    // If the current page is being published, invalidate the layout cache to refetch with updated hasUnsavedStatusChange
    if (entityType === 'canvas_page' && entityId === pageToPublishId) {
      dispatch(componentAndLayoutApi.util.invalidateTags([{ type: 'Layout' }]));
    }
  }

  function handleSetHomepage(item: ContentStub) {
    const { internalPath } = item;
    dispatch(setHomepagePath(internalPath));
    setHomepage({
      data: {
        id: HOMEPAGE_CONFIG_ID,
        label: 'Update homepage',
        target: 'system.site',
        actions: [
          {
            name: 'simpleConfigUpdate',
            input: {
              'page.front': internalPath,
            },
          },
        ],
      },
      autoSaves: '',
    });
  }

  useEffect(() => {
    if (isGetStagedUpdateSuccess) {
      dispatch(
        setHomepagePath(extractHomepagePathFromStagedConfig(homepageConfig)),
      );
    }
  }, [dispatch, homepageConfig, isGetStagedUpdateSuccess]);

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

  useEffect(() => {
    if (deleteContentError) {
      showBoundary(deleteContentError);
    }
  }, [deleteContentError, showBoundary]);

  useEffect(() => {
    if (setHomepageError) {
      showBoundary(setHomepageError);
    }
  }, [setHomepageError, showBoundary]);

  useEffect(() => {
    if (updateContentError) {
      showBoundary(updateContentError);
    }
  }, [updateContentError, showBoundary]);

  // Determine the currently selected page
  const selectedPageId = entityType === 'canvas_page' ? entityId : undefined;

  return (
    <PageList
      pageItems={pageItems || []}
      isPageItemsLoading={isPageItemsLoading}
      pageItemsError={pageItemsError ? String(pageItemsError) : null}
      homepagePath={homepagePath}
      selectedPageId={selectedPageId}
      canCreatePages={canCreatePages}
      onNewPage={handleNewPage}
      onDeletePage={handleDeletePage}
      onDuplicatePage={handleDuplication}
      onSelectPage={handleOnSelect}
      onSetHomepage={handleSetHomepage}
      onUnpublishPage={handleUnpublishPage}
      onPublishPage={handlePublishPage}
      onSearch={setSearchTerm}
      hasMore={hasMore}
      onLoadMore={handleLoadMore}
    />
  );
};

export default Pages;
