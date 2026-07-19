import { useEffect, useMemo, useState } from 'react';
import { useErrorBoundary } from 'react-error-boundary';
import { NavLink, useLocation, useParams } from 'react-router-dom';
import TemplateIcon from '@assets/icons/template.svg?react';
import {
  ChevronLeftIcon,
  CodeIcon,
  CubeIcon,
  FileTextIcon,
  GlobeIcon,
  HomeIcon,
  SectionIcon,
  StackIcon,
} from '@radix-ui/react-icons';
import {
  Badge,
  Button,
  ChevronDownIcon,
  Flex,
  Popover,
} from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import ErrorCard from '@/components/error/ErrorCard';
import Navigation from '@/components/navigation/Navigation';
import PageStatus from '@/components/pageStatus/PageStatus';
import Panel from '@/components/Panel';
import { selectCodeComponentProperty } from '@/features/code-editor/codeEditorSlice';
import {
  extractHomepagePathFromStagedConfig,
  selectHomepagePath,
  selectHomepageStagedConfigExists,
  setHomepagePath,
} from '@/features/configuration/configurationSlice';
import { selectLayout } from '@/features/layout/layoutModelSlice';
import {
  getContentNavigationTypeOptions,
  getTemplatedEntityGroups,
  PAGE_ENTITY_TYPE,
  resolveContentNavigationType,
} from '@/features/navigator/templatedContent';
import { DEFAULT_REGION, selectPreviouslyEdited } from '@/features/ui/uiSlice';
import useEditorNavigation from '@/hooks/useEditorNavigation';
import { useEntityTitle } from '@/hooks/useEntityTitle';
import { usePaginatedContentList } from '@/hooks/usePaginatedContentList';
import { useSmartRedirect } from '@/hooks/useSmartRedirect';
import { useTemplateCaption } from '@/hooks/useTemplateCaption';
import { useTemplateRef } from '@/hooks/useTemplateRef';
import {
  componentAndLayoutApi,
  useGetContentTemplatesQuery,
} from '@/services/componentAndLayout';
import {
  useCreateContentMutation,
  useDeleteContentMutation,
  useGetStagedConfigQuery,
  useSetStagedConfigMutation,
  useUpdateContentMutation,
} from '@/services/content';
import { pageDataFormApi } from '@/services/pageDataForm';
import { getCanvasSettings } from '@/utils/drupal-globals';
import { getQueryErrorMessage } from '@/utils/error-handling';
import {
  removeComponentFromPathname,
  removeRegionFromPathname,
} from '@/utils/route-utils';

import type { ReactElement } from 'react';
import type { ContentStub } from '@/types/Content';

interface PageType {
  [key: string]: ReactElement;
}

const iconMap: PageType = {
  Page: <FileTextIcon />,
  ContentType: <StackIcon />,
  ComponentName: <CodeIcon />,
  GlobalPatternName: <SectionIcon />,
  Homepage: <HomeIcon />,
  Template: <TemplateIcon />,
};

const canvasSettings = getCanvasSettings();

export const HOMEPAGE_CONFIG_ID = 'canvas_set_homepage';

const PageInfo = () => {
  const { showBoundary } = useErrorBoundary();
  const { navigateToEditor } = useEditorNavigation();
  const { redirectToNextBestPage } = useSmartRedirect();
  const {
    regionId: focusedRegion = DEFAULT_REGION,
    entityType,
    entityId,
  } = useParams();
  const codeComponentName = useAppSelector(selectCodeComponentProperty('name'));
  const isCodeEditor = codeComponentName !== '';
  const layout = useAppSelector(selectLayout);
  const previouslyEdited = useAppSelector(selectPreviouslyEdited);
  const dispatch = useAppDispatch();
  const focusedRegionName = layout.find(
    (region) => region.id === focusedRegion,
  )?.name;
  const location = useLocation();
  const title = useEntityTitle();

  const { isTemplateContext, isTemplatePreviewRoute } = useTemplateRef();
  const isTemplateRoute = isTemplateContext || isTemplatePreviewRoute;
  const templateCaption = useTemplateCaption();

  const [searchTerm, setSearchTerm] = useState<string>('');
  // The top bar's New button creates Canvas pages only; creating templated
  // entity types lives in the Content panel and the content browser.
  const canCreatePages =
    !!canvasSettings.contentEntityCreateOperations?.canvas_page?.canvas_page;

  // The popover lists one content type at a time: Canvas pages, or any
  // templated entity type (a bundle with an enabled full-view template),
  // switchable inside the popover. Defaults to the type of the open entity so
  // the list starts where the editor is.
  const { data: contentTemplates } = useGetContentTemplatesQuery();
  const templatedGroups = useMemo(
    () => getTemplatedEntityGroups(contentTemplates),
    [contentTemplates],
  );
  const typeOptions = useMemo(
    () => getContentNavigationTypeOptions(templatedGroups),
    [templatedGroups],
  );
  const [selectedNavType, setSelectedNavType] = useState<string>(
    entityType ?? PAGE_ENTITY_TYPE,
  );
  // Guards against a not-yet-loaded templates query and stale selections by
  // falling back to pages.
  const listedEntityType = resolveContentNavigationType(
    selectedNavType,
    typeOptions,
  );
  const listedTypeLabel =
    typeOptions.find((option) => option.entityType === listedEntityType)
      ?.label ?? 'Pages';
  const {
    items: contentItems,
    isLoading: isContentItemsLoading,
    error: contentItemsError,
    isSuccess: isGetContentItemsSuccess,
    hasMore,
    handleLoadMore,
  } = usePaginatedContentList(listedEntityType, searchTerm);

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
  const [isCurrentPageHomepage, setIsCurrentPageHomepage] =
    useState<boolean>(false);
  const [popoverOpen, setPopoverOpen] = useState<boolean>(false);

  useEffect(() => {
    // Only a canvas_page listing can answer the homepage question; skip while
    // the popover lists a templated entity type so switching types does not
    // clobber the current answer.
    if (isGetContentItemsSuccess && listedEntityType === PAGE_ENTITY_TYPE) {
      // Check if the current page is the homepage.
      const homepage = contentItems?.find(
        (page) => page.internalPath === homepagePath,
      );
      setIsCurrentPageHomepage(
        entityType === 'canvas_page' && entityId === String(homepage?.id),
      );
    }
  }, [
    entityId,
    entityType,
    homepagePath,
    isGetContentItemsSuccess,
    contentItems,
    listedEntityType,
  ]);

  useEffect(() => {
    if (isGetStagedUpdateSuccess) {
      dispatch(
        setHomepagePath(extractHomepagePathFromStagedConfig(homepageConfig)),
      );
    }
  }, [dispatch, homepageConfig, isGetStagedUpdateSuccess]);

  function handleNewPage() {
    createContent({
      entity_type: 'canvas_page',
    });
    setPopoverOpen(false);
  }

  const [deleteContent, { error: deleteContentError }] =
    useDeleteContentMutation();
  const [updateContent, { error: updateContentError }] =
    useUpdateContentMutation();
  const [setHomepage, { error: setHomepageError }] =
    useSetStagedConfigMutation();

  async function handleDeletePage(item: ContentStub) {
    const pageToDeleteId = String(item.id);
    await deleteContent({
      entityType: 'canvas_page',
      entityId: pageToDeleteId,
    });

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
    setPopoverOpen(false);
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
    if (isCreateContentSuccess) {
      setPopoverOpen(false);
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

  const isHeadlessFrontends = location.pathname.startsWith('/headless');

  return (
    <Flex gap="2" align="center">
      {isCodeEditor && previouslyEdited.path ? (
        <NavLink
          to={{
            pathname: previouslyEdited.path,
          }}
          aria-label={`Back`}
          title={`${previouslyEdited.name}`}
          onClick={() => {
            // Fetch a new version of the page data form as it has been
            // unmounted and the cached versions won't reflect any AJAX updates
            // to the form.
            dispatch(
              pageDataFormApi.util.invalidateTags([
                { type: 'PageDataForm', id: 'FORM' },
              ]),
            );
          }}
        >
          <Button color="sky" variant="soft" size="1">
            <ChevronLeftIcon />
            Back
          </Button>
        </NavLink>
      ) : null}
      {focusedRegion === DEFAULT_REGION ? (
        <Popover.Root open={popoverOpen} onOpenChange={setPopoverOpen}>
          <Popover.Trigger>
            <Button
              color="gray"
              variant="soft"
              size="1"
              data-testid="canvas-navigation-button"
            >
              <Flex gap="2" align="center">
                {isHeadlessFrontends ? (
                  <>
                    <GlobeIcon />
                    Headless frontends
                  </>
                ) : isCodeEditor ? (
                  <>
                    <CodeIcon />
                    {codeComponentName}
                  </>
                ) : isTemplateRoute ? (
                  <>
                    {iconMap['Template']}
                    {templateCaption || 'Template'}
                  </>
                ) : (
                  <>
                    {isCurrentPageHomepage
                      ? iconMap['Homepage']
                      : iconMap['Page']}
                    {title !== undefined
                      ? title
                        ? title
                        : entityType && entityType !== PAGE_ENTITY_TYPE
                          ? 'Untitled'
                          : 'Untitled page'
                      : 'No page selected'}
                  </>
                )}
                <ChevronDownIcon />
              </Flex>
            </Button>
          </Popover.Trigger>
          <Popover.Content
            size="2"
            width="100vw"
            maxWidth="400px"
            asChild
            align="center"
          >
            <Panel className="canvas-app" mt="4">
              {!contentItemsError && (
                <Navigation
                  loading={isContentItemsLoading}
                  items={contentItems || []}
                  entityType={listedEntityType}
                  groupTitle={listedTypeLabel}
                  typeOptions={typeOptions}
                  onTypeChange={setSelectedNavType}
                  showNew={
                    canCreatePages && listedEntityType === PAGE_ENTITY_TYPE
                  }
                  onNewPage={handleNewPage}
                  onSearch={setSearchTerm}
                  onSelect={() => setPopoverOpen(false)}
                  onDuplicate={handleDuplication}
                  onSetHomepage={handleSetHomepage}
                  onUnpublish={handleUnpublishPage}
                  onPublish={handlePublishPage}
                  onDelete={handleDeletePage}
                  hasMore={hasMore}
                  onLoadMore={handleLoadMore}
                />
              )}
              {contentItemsError && (
                <ErrorCard
                  title="An unexpected error has occurred while loading content."
                  error={getQueryErrorMessage(contentItemsError)}
                />
              )}
            </Panel>
          </Popover.Content>
        </Popover.Root>
      ) : (
        <NavLink
          to={{
            pathname: removeComponentFromPathname(
              removeRegionFromPathname(location.pathname),
            ),
          }}
          aria-label="Back to Content region"
          onClick={() => {
            // Fetch a new version of the page data form as it has been
            // unmounted and the cached versions won't reflect any AJAX updates
            // to the form.
            dispatch(
              pageDataFormApi.util.invalidateTags([
                { type: 'PageDataForm', id: 'FORM' },
              ]),
            );
          }}
        >
          <Badge color="grass" size="2">
            <ChevronLeftIcon />
            <CubeIcon />
            {focusedRegionName}
          </Badge>
        </NavLink>
      )}

      {entityId && <PageStatus />}
    </Flex>
  );
};

export default PageInfo;
