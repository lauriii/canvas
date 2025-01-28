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
import { DEFAULT_REGION } from '@/features/ui/uiSlice';
import { Link, useParams } from 'react-router-dom';
import { selectLayout } from '@/features/layout/layoutModelSlice';
import Navigation from '@/components/navigation/Navigation';
import { selectEntityId } from '@/features/configuration/configurationSlice';
import { handleNonWorkingBtn } from '@/utils/function-utils';

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
  const { regionId: focusedRegion = DEFAULT_REGION } = useParams();
  const layout = useAppSelector(selectLayout);
  const focusedRegionName = layout.find(
    (region) => region.id === focusedRegion,
  )?.name;
  const entityId = useAppSelector(selectEntityId);
  const entity_form_fields = useAppSelector(selectPageData);
  // @todo stop hardcoding `title` and `status` after https://www.drupal.org/i/3501847
  const title = entity_form_fields['title[0][value]'];
  // `status comes as a numeric string from the backend but is a boolean when modified in the editor.
  const published =
    entity_form_fields['status[value]'] === '1' ||
    entity_form_fields['status[value]'] === true;

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
            {/* @todo add onNewPage handler in https://www.drupal.org/i/3502819 */}
            <Navigation
              loading={false}
              items={[
                {
                  title,
                  path: '',
                  status: published,
                  id: entityId,
                },
              ]}
              onNewPage={handleNonWorkingBtn}
              onSearch={handleNonWorkingBtn}
              onSelect={handleNonWorkingBtn}
              onRename={handleNonWorkingBtn}
              onDuplicate={handleNonWorkingBtn}
              onSetHomepage={handleNonWorkingBtn}
              onDelete={handleNonWorkingBtn}
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
