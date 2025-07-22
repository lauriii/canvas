import {
  ChevronLeftIcon,
  CodeIcon,
  CubeIcon,
  FileIcon,
  SectionIcon,
  StackIcon,
  HomeIcon,
} from '@radix-ui/react-icons';
import {
  Badge,
  Button,
  ChevronDownIcon,
  Flex,
  Popover,
} from '@radix-ui/themes';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { selectPageData } from '@/features/pageData/pageDataSlice';
import type { ReactElement } from 'react';
import { useEffect } from 'react';
import useDebounce from '@/hooks/useDebounce';
import { DEFAULT_REGION } from '@/features/ui/uiSlice';
import { NavLink, useParams } from 'react-router-dom';
import { selectLayout } from '@/features/layout/layoutModelSlice';
import { selectCodeComponentProperty } from '@/features/code-editor/codeEditorSlice';
import Navigation from '@/components/navigation/Navigation';
import {
  useDeleteContentMutation,
  useGetContentListQuery,
  useCreateContentMutation,
  useSetStagedConfigMutation,
  useGetStagedConfigQuery,
} from '@/services/content';
import useEditorNavigation from '@/hooks/useEditorNavigation';
import { useErrorBoundary } from 'react-error-boundary';
import { useState } from 'react';
import type { ContentStub } from '@/types/Content';
import ErrorCard from '@/components/error/ErrorCard';
import PageStatus from '@/components/pageStatus/PageStatus';
import Panel from '@/components/Panel';
import {
  selectEntityId,
  selectEntityType,
  selectHomepagePath,
  setHomepagePath,
} from '@/features/configuration/configurationSlice';
import { getBaseUrl, getXbSettings } from '@/utils/drupal-globals';
import { getQueryErrorMessage } from '@/utils/error-handling';
import { pageDataFormApi } from '@/services/pageDataForm';
import { useGetAllPendingChangesQuery } from '@/services/pendingChangesApi';

interface PageType {
  [key: string]: ReactElement;
}

const iconMap: PageType = {
  Page: <FileIcon />,
  ContentType: <StackIcon />,
  ComponentName: <CodeIcon />,
  GlobalPatternName: <SectionIcon />,
  Homepage: <HomeIcon />,
};

const xbSettings = getXbSettings();

export const HOMEPAGE_CONFIG_ID = 'xb_set_homepage';

const PageInfo = () => {
  const { showBoundary } = useErrorBoundary();
  const { setEditorEntity } = useEditorNavigation();
  const { regionId: focusedRegion = DEFAULT_REGION } = useParams();
  const codeComponentName = useAppSelector(selectCodeComponentProperty('name'));
  const isCodeEditor = codeComponentName !== '';
  const layout = useAppSelector(selectLayout);
  const dispatch = useAppDispatch();
  const focusedRegionName = layout.find(
    (region) => region.id === focusedRegion,
  )?.name;
  const entity_form_fields = useAppSelector(selectPageData);
  const title =
    entity_form_fields[`${xbSettings.entityTypeKeys.label}[0][value]`];
  const [searchTerm, setSearchTerm] = useState<string>('');
  const debouncedSearchTerm = useDebounce(searchTerm, 300);
  // @todo: https://www.drupal.org/i/3513566 this needs to be generalized to check all content entity types.
  const canCreatePages =
    !!xbSettings.contentEntityCreateOperations?.xb_page?.xb_page;
  const {
    data: pageItems,
    isLoading: isPageItemsLoading,
    error: pageItemsError,
    isSuccess: isGetPageItemsSuccess,
  } = useGetContentListQuery({
    // @todo Generalize in https://www.drupal.org/i/3498525
    entityType: 'xb_page',
    search: debouncedSearchTerm,
  });
  const entityId = useAppSelector(selectEntityId);
  const entityType = useAppSelector(selectEntityType);
  const baseUrl = getBaseUrl();
  const [
    createContent,
    {
      data: createContentData,
      error: createContentError,
      isSuccess: isCreateContentSuccess,
    },
  ] = useCreateContentMutation();
  const homepagePath = useAppSelector(selectHomepagePath);
  const [homepageStagedUpdateExists, setHomepageStagedUpdateExists] =
    useState<boolean>(false);
  const { data: changesData, isSuccess: getChangesSuccess } =
    useGetAllPendingChangesQuery();
  const { data: homepageConfig, isSuccess: isGetStagedUpdateSuccess } =
    useGetStagedConfigQuery(HOMEPAGE_CONFIG_ID, {
      // Only fetch the homepage staged config if it exists to avoid
      // unnecessary API calls that return 404s.
      skip: !homepageStagedUpdateExists,
    });
  const [isCurrentPageHomepage, setIsCurrentPageHomepage] =
    useState<boolean>(false);

  useEffect(() => {
    if (isGetPageItemsSuccess) {
      // Check if the current page is the homepage.
      const homepage = pageItems.find(
        (page) => page.internalPath === homepagePath,
      );
      setIsCurrentPageHomepage(
        entityType === 'xb_page' && entityId === String(homepage?.id),
      );
    }
  }, [entityId, entityType, homepagePath, isGetPageItemsSuccess, pageItems]);

  // Check if the homepage staged update exists in the current auto-save.
  useEffect(() => {
    if (getChangesSuccess) {
      const containsHomepageConfig = Object.prototype.hasOwnProperty.call(
        changesData,
        `staged_config_update:${HOMEPAGE_CONFIG_ID}`,
      );
      setHomepageStagedUpdateExists(containsHomepageConfig);
    }
  }, [changesData, getChangesSuccess]);

  useEffect(() => {
    if (isGetStagedUpdateSuccess) {
      dispatch(
        setHomepagePath(homepageConfig.data.actions[0].input['page.front']),
      );
    }
  }, [dispatch, homepageConfig?.data, isGetStagedUpdateSuccess]);

  function handleNewPage() {
    createContent({
      entity_type: 'xb_page',
    });
  }

  const [deleteContent, { error: deleteContentError }] =
    useDeleteContentMutation();
  const [setHomepage, { error: setHomepageError }] =
    useSetStagedConfigMutation();

  async function handleDeletePage(item: ContentStub) {
    // Find another page to redirect to (filtering out the page being deleted)
    const remainingPages =
      pageItems?.filter((page) => page.id !== item.id) || [];
    const pageToDeleteId = String(item.id);
    await deleteContent({
      entityType: 'xb_page',
      entityId: pageToDeleteId,
    });
    const homepage = pageItems?.find(
      (page) => page.internalPath === homepagePath,
    );
    // If the current page is the one being deleted, redirect to the homepage.
    if (entityType === 'xb_page' && entityId === pageToDeleteId) {
      if (homepage) {
        setEditorEntity('xb_page', String(homepage.id));
      } else if (remainingPages.length > 0) {
        // It's possible there is no homepage set yet right now, so we redirect to the first remaining page.
        setEditorEntity('xb_page', String(remainingPages[0].id));
      } else {
        // If there are no more pages, redirect out of XB.
        // @todo: Remove this in https://www.drupal.org/i/3506434
        //   since deleting the homepage in XB should be disallowed in that issue so remaining pages should never be 0.
        setTimeout(() => {
          window.location.href = baseUrl;
        }, 100);
      }
    }
    // Keep local storage tidy and clear out the array of collapsed layers for the deleted item.
    window.localStorage.removeItem(
      `XB.collapsedLayers.xb_page.${pageToDeleteId}`,
    );
  }

  function handleDuplication(item: ContentStub) {
    createContent({
      entity_type: 'xb_page',
      entity_id: String(item.id),
    });
  }

  // @todo https://www.drupal.org/i/3498525 should generalize this to all eligible content entity types.
  function handleOnSelect(item: ContentStub) {
    setEditorEntity('xb_page', String(item.id));
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
      setEditorEntity(
        createContentData.entity_type,
        createContentData.entity_id,
      );
    }
  }, [isCreateContentSuccess, createContentData, setEditorEntity]);

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

  return (
    <Flex gap="2" align="center">
      {focusedRegion === DEFAULT_REGION && !isCodeEditor ? (
        <Popover.Root>
          <Popover.Trigger>
            <Button
              color="gray"
              variant="soft"
              size="1"
              data-testid="xb-navigation-button"
            >
              <Flex gap="2" align="center">
                {isCurrentPageHomepage ? iconMap['Homepage'] : iconMap['Page']}
                {title}
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
            <Panel className="xb-app" mt="4">
              {!pageItemsError && (
                <Navigation
                  loading={isPageItemsLoading}
                  items={pageItems || []}
                  showNew={canCreatePages}
                  onNewPage={handleNewPage}
                  onSearch={setSearchTerm}
                  onSelect={handleOnSelect}
                  onDuplicate={handleDuplication}
                  onSetHomepage={handleSetHomepage}
                  onDelete={handleDeletePage}
                />
              )}
              {pageItemsError && (
                <ErrorCard
                  title="An unexpected error has occurred while loading pages."
                  error={getQueryErrorMessage(pageItemsError)}
                />
              )}
            </Panel>
          </Popover.Content>
        </Popover.Root>
      ) : (
        <NavLink
          to={{
            pathname: '/editor',
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
          <Badge color={isCodeEditor ? 'sky' : 'grass'} size="2">
            <ChevronLeftIcon />
            {isCodeEditor ? <CodeIcon /> : <CubeIcon />}
            {isCodeEditor ? codeComponentName : focusedRegionName}
          </Badge>
        </NavLink>
      )}

      <PageStatus />
    </Flex>
  );
};

export default PageInfo;
