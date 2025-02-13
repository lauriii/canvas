import {
  ChevronLeftIcon,
  Component1Icon,
  CubeIcon,
  FileIcon,
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
import Navigation from '@/components/navigation/Navigation';
import { handleNonWorkingBtn } from '@/utils/function-utils';
import { useGetContentListQuery } from '@/services/content';
import { useCreateContentMutation } from '@/services/contentCreate';
import { useDeleteContentMutation } from '@/services/content';
import { useNavigationUtils } from '@/hooks/useNavigationUtils';
import { useErrorBoundary } from 'react-error-boundary';
import type { ContentStub } from '@/types/Content';

interface PageType {
  [key: string]: ReactElement;
}

const iconMap: PageType = {
  Page: <FileIcon />,
  ContentType: <StackIcon />,
  ComponentName: <Component1Icon />,
  GlobalSectionName: <Component1Icon />,
};

const PageInfo = () => {
  const { showBoundary } = useErrorBoundary();
  const { setEditorEntity } = useNavigationUtils();
  const { regionId: focusedRegion = DEFAULT_REGION } = useXbParams();
  const layout = useAppSelector(selectLayout);
  const focusedRegionName = layout.find(
    (region) => region.id === focusedRegion,
  )?.name;
  const entity_form_fields = useAppSelector(selectPageData);
  // @todo stop hardcoding `title` and `status` after https://www.drupal.org/i/3501847
  const title = entity_form_fields['title[0][value]'];
  // `status comes as a numeric string from the backend but is a boolean when modified in the editor.
  const published =
    entity_form_fields['status[value]'] === '1' ||
    entity_form_fields['status[value]'] === true;

  const {
    data: pageItems,
    isLoading: isPageItemsLoading,
    error: pageItemsError,
  } = useGetContentListQuery('xb_page');

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
      entityType: 'xb_page',
    });
  }

  const [deleteContent, { error: deleteContentError }] =
    useDeleteContentMutation();

  function handleDeletePage(item: ContentStub) {
    deleteContent({
      entityType: 'xb_page',
      entityId: String(item.id),
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
      {focusedRegion === DEFAULT_REGION ? (
        <Popover.Root>
          <Popover.Trigger>
            <Button
              color="gray"
              variant="soft"
              data-testid="xb-navigation-button"
            >
              <Flex gap="2" align="center">
                {iconMap['Page']}
                {title}
                <ChevronDownIcon />
              </Flex>
            </Button>
          </Popover.Trigger>
          <Popover.Content size="2" maxWidth="400px">
            {/* @todo load data in https://www.drupal.org/i/3502820 */}
            <Navigation
              loading={isPageItemsLoading}
              items={pageItems || []}
              onNewPage={handleNewPage}
              onSearch={handleNonWorkingBtn}
              onSelect={handleOnSelect}
              onRename={handleNonWorkingBtn}
              onDuplicate={handleNonWorkingBtn}
              onSetHomepage={handleNonWorkingBtn}
              onDelete={handleDeletePage}
            />
          </Popover.Content>
        </Popover.Root>
      ) : (
        <Link
          to={{
            pathname: '/editor',
          }}
          aria-label="Back to Content region"
        >
          <Badge color="grass" size="2">
            <ChevronLeftIcon /> <CubeIcon />
            {focusedRegionName}
          </Badge>
        </Link>
      )}

      <Badge size="1" color={published ? 'lime' : 'yellow'} variant="solid">
        {published ? 'Published' : 'Draft'}
      </Badge>
    </Flex>
  );
};

export default PageInfo;
