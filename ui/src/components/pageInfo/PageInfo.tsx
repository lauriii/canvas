import {
  ChevronLeftIcon,
  CodeIcon,
  CubeIcon,
  FileIcon,
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
import { useAppSelector } from '@/app/hooks';
import { selectPageData } from '@/features/pageData/pageDataSlice';
import type { ReactElement } from 'react';
import { useEffect } from 'react';
import { DEFAULT_REGION } from '@/features/ui/uiSlice';
import { Link } from 'react-router-dom';
import useXbParams from '@/hooks/useXbParams';
import { selectLayout } from '@/features/layout/layoutModelSlice';
import { selectCodeComponentProperty } from '@/features/code-editor/codeEditorSlice';
import Navigation from '@/components/navigation/Navigation';
import { handleNonWorkingBtn } from '@/utils/function-utils';
import {
  useDeleteContentMutation,
  useGetContentListQuery,
  useCreateContentMutation,
} from '@/services/content';
import useEditorNavigation from '@/hooks/useEditorNavigation';
import { useErrorBoundary } from 'react-error-boundary';
import type { ContentStub } from '@/types/Content';
import PageStatus from '@/components/pageStatus/PageStatus';
import clsx from 'clsx';
import styles from '@/components/topbar/menu/TopbarPopover.module.css';
import Panel from '@/components/Panel';
import {
  selectEntityId,
  selectEntityType,
} from '@/features/configuration/configurationSlice';
import { getBaseUrl, getXbSettings } from '@/utils/drupal-globals';

interface PageType {
  [key: string]: ReactElement;
}

const iconMap: PageType = {
  Page: <FileIcon />,
  ContentType: <StackIcon />,
  ComponentName: <CodeIcon />,
  GlobalSectionName: <SectionIcon />,
};

const xbSettings = getXbSettings();

const PageInfo = () => {
  const { showBoundary } = useErrorBoundary();
  const { setEditorEntity } = useEditorNavigation();
  const { regionId: focusedRegion = DEFAULT_REGION } = useXbParams();
  const codeComponentName = useAppSelector(selectCodeComponentProperty('name'));

  const isCodeEditor = codeComponentName !== '';
  const layout = useAppSelector(selectLayout);
  const focusedRegionName = layout.find(
    (region) => region.id === focusedRegion,
  )?.name;
  const entity_form_fields = useAppSelector(selectPageData);
  const title =
    entity_form_fields[`${xbSettings.entityTypeKeys.label}[0][value]`];

  const {
    data: pageItems,
    isLoading: isPageItemsLoading,
    error: pageItemsError,
  } = useGetContentListQuery('xb_page');
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

  function handleNewPage() {
    createContent({
      entity_type: 'xb_page',
    });
  }

  const [deleteContent, { error: deleteContentError }] =
    useDeleteContentMutation();

  async function handleDeletePage(item: ContentStub) {
    // Find another page to redirect to (filtering out the page being deleted)
    const remainingPages =
      pageItems?.filter((page) => page.id !== item.id) || [];
    const pageToDeleteId = String(item.id);
    await deleteContent({
      entityType: 'xb_page',
      entityId: pageToDeleteId,
    });
    // If the current page is the one being deleted, redirect to first available page.
    // @todo: Change this to redirect to the homepage in XB in https://www.drupal.org/i/3503412.
    if (entityType === 'xb_page' && entityId === pageToDeleteId) {
      if (remainingPages.length > 0) {
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

  useEffect(() => {
    if (isCreateContentSuccess) {
      setEditorEntity(
        createContentData.entity_type,
        createContentData.entity_id,
      );
    }
  }, [isCreateContentSuccess, createContentData, setEditorEntity]);

  useEffect(() => {
    if (pageItemsError) {
      showBoundary(pageItemsError);
    }
  }, [pageItemsError, showBoundary]);

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
                {iconMap['Page']}
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
            <Panel className={clsx(styles.content, 'xb-app')}>
              {/* @todo load data in https://www.drupal.org/i/3502820 */}
              <Navigation
                loading={isPageItemsLoading}
                items={pageItems || []}
                onNewPage={handleNewPage}
                onSearch={handleNonWorkingBtn}
                onSelect={handleOnSelect}
                onRename={handleNonWorkingBtn}
                onDuplicate={handleDuplication}
                onSetHomepage={handleNonWorkingBtn}
                onDelete={handleDeletePage}
              />
            </Panel>
          </Popover.Content>
        </Popover.Root>
      ) : (
        <Link
          to={{
            pathname: '/editor',
          }}
          aria-label="Back to Content region"
        >
          <Badge color={isCodeEditor ? 'sky' : 'grass'} size="2">
            <ChevronLeftIcon />
            {isCodeEditor ? <CodeIcon /> : <CubeIcon />}
            {isCodeEditor ? codeComponentName : focusedRegionName}
          </Badge>
        </Link>
      )}

      <PageStatus />
    </Flex>
  );
};

export default PageInfo;
