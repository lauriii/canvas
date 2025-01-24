import { Component1Icon, FileIcon, StackIcon } from '@radix-ui/react-icons';
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
  const entity_form_fields = useAppSelector(selectPageData);
  // @todo stop hardcoding `title` and `status` after https://www.drupal.org/i/3501847
  const title = entity_form_fields['title[0][value]'];
  // `status comes as a numeric string from the backend but is a boolean when modified in the editor.
  const published =
    entity_form_fields['status[value]'] === '1' ||
    entity_form_fields['status[value]'] === true;

  return (
    <Flex gap="2" align="center">
      <Popover.Root>
        <Popover.Trigger>
          <Button color="gray" variant="soft">
            <Flex gap="2" align="center">
              {iconMap['Page']}
              {title}
              <ChevronDownIcon />{' '}
            </Flex>
          </Button>
        </Popover.Trigger>
        <Popover.Content size="2" maxWidth="400px">
          {/* todo place navigation component from https://www.drupal.org/i/3500054 */}
        </Popover.Content>
      </Popover.Root>
      <Badge size="2" color={published ? 'lime' : 'yellow'} variant="solid">
        {published ? 'Published' : 'Draft'}
      </Badge>
    </Flex>
  );
};

export default PageInfo;
