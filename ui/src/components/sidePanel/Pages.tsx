import { useEffect, useMemo, useRef, useState } from 'react';
import { useErrorBoundary } from 'react-error-boundary';
import { useParams } from 'react-router';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import PageList from '@/components/pageInfo/PageList';
import {
  extractHomepagePathFromStagedConfig,
  selectHomepagePath,
  selectHomepageStagedConfigExists,
  setHomepagePath,
} from '@/features/configuration/configurationSlice';
import { useDropOnFolderHandler } from '@/hooks/useDropOnFolderHandler';
import useEditorNavigation from '@/hooks/useEditorNavigation';
import { usePaginatedContentList } from '@/hooks/usePaginatedContentList';
import { useSmartRedirect } from '@/hooks/useSmartRedirect';
import {
  componentAndLayoutApi,
  useGetFoldersQuery,
} from '@/services/componentAndLayout';
import {
  contentApi,
  useCreateContentMutation,
  useDeleteContentMutation,
  useGetStagedConfigQuery,
  useSetStagedConfigMutation,
  useUpdateContentMutation,
} from '@/services/content';
import { notificationsApi } from '@/services/notificationsApi';
import { pendingChangesApi } from '@/services/pendingChangesApi';
import { getCanvasSettings } from '@/utils/drupal-globals';

import type { FolderData } from '@/components/list/FolderList';
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

  const {
    data: folders,
    isLoading: isFoldersLoading,
    fulfilledTimeStamp: foldersLoadedAt,
  } = useGetFoldersQuery();
  const { moveItemToFolder } = useDropOnFolderHandler();
  // A change to a folder can change the alias of its pages on the server, as
  // an unpublished change of each page, with a notification.
  const foldersFirstLoadedAt = useRef(foldersLoadedAt);
  useEffect(() => {
    if (!foldersLoadedAt) {
      return;
    }
    if (!foldersFirstLoadedAt.current) {
      foldersFirstLoadedAt.current = foldersLoadedAt;
    } else if (foldersFirstLoadedAt.current !== foldersLoadedAt) {
      dispatch(
        contentApi.util.invalidateTags([{ type: 'Content', id: 'LIST' }]),
      );
      dispatch(
        pendingChangesApi.util.invalidateTags([
          { type: 'PendingChanges', id: 'LIST' },
        ]),
      );
      dispatch(
        notificationsApi.util.invalidateTags([
          { type: 'Notifications', id: 'LIST' },
        ]),
      );
    }
  }, [dispatch, foldersLoadedAt]);
  const pagesInFolders = useMemo(
    () =>
      Object.values((folders as FolderData | undefined)?.folders ?? {})
        .filter(({ type }) => type === 'canvas_page')
        .flatMap(({ items }) => (items ?? []) as string[]),
    [folders],
  );

  // A folder can hold pages beyond the batch loaded so far.
  // ponytail: this loads the whole list while any folder item is missing. Add
  // a UUID filter to the content list endpoint if sites with many pages feel
  // it.
  useEffect(() => {
    if (!hasMore || isPageItemsLoading || searchTerm) {
      return;
    }
    const loaded = new Set(pageItems?.map(({ uuid }) => uuid));
    if (pagesInFolders.some((uuid) => !loaded.has(uuid))) {
      handleLoadMore();
    }
  }, [
    hasMore,
    isPageItemsLoading,
    searchTerm,
    pageItems,
    pagesInFolders,
    handleLoadMore,
  ]);

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

  // The folder that the page being created goes into, if any.
  const newPageFolderId = useRef<string | undefined>(undefined);
  function handleNewPage(folderId?: string) {
    newPageFolderId.current = folderId;
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
    // The server takes a deleted page out of its folder.
    dispatch(
      componentAndLayoutApi.util.invalidateTags([
        { type: 'Folders', id: 'LIST' },
      ]),
    );

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
      const folderId = newPageFolderId.current;
      newPageFolderId.current = undefined;
      // Move first: the page form then opens with the URL path of the folder.
      (folderId && createContentData.uuid
        ? moveItemToFolder(createContentData.uuid, folderId)
        : Promise.resolve()
      ).finally(() =>
        navigateToEditor(
          createContentData.entity_type,
          createContentData.entity_id,
        ),
      );
    }
    // moveItemToFolder is a new function on every render.
    // eslint-disable-next-line react-hooks/exhaustive-deps
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
      isPageItemsLoading={isPageItemsLoading || isFoldersLoading}
      pageItemsError={pageItemsError ? String(pageItemsError) : null}
      homepagePath={homepagePath}
      selectedPageId={selectedPageId}
      canCreatePages={canCreatePages}
      // A search shows a flat list of the matching pages.
      folders={searchTerm ? undefined : (folders as FolderData | undefined)}
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
