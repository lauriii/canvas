import { useEffect, useState } from 'react';
import clsx from 'clsx';
import { useErrorBoundary } from 'react-error-boundary';
import FolderIcon from '@assets/icons/folder.svg?react';
import * as Collapsible from '@radix-ui/react-collapsible';
import { ChevronRightIcon } from '@radix-ui/react-icons';
import { Flex, Skeleton, Text } from '@radix-ui/themes';

import SidebarNode from '@/components/sidePanel/SidebarNode';
import { useGetContentTemplatesQuery } from '@/services/componentAndLayout';

import type { TemplateInBundle } from '@/services/componentAndLayout';

import styles from '@/components/list/List.module.css';

type BundleListItemProps = {
  bundle: TemplateInBundle;
};
const TemplateList = () => {
  const { showBoundary } = useErrorBoundary();

  const { data, isLoading, isFetching, error } = useGetContentTemplatesQuery();
  useEffect(() => {
    if (error) {
      showBoundary(error);
    }
  }, [error, showBoundary]);

  return (
    <Skeleton
      loading={isLoading || isFetching}
      height="1.2rem"
      width="100%"
      my="3"
    >
      {!!data?.node?.bundles &&
        Object.entries(data.node.bundles).map(([bundleKey, bundle]) => (
          <BundleListItem key={bundleKey} bundle={bundle} />
        ))}
    </Skeleton>
  );
};

const BundleListItem = ({ bundle }: BundleListItemProps) => {
  const [isOpen, setIsOpen] = useState(true);

  return (
    <Collapsible.Root open={isOpen} onOpenChange={setIsOpen}>
      <Collapsible.Trigger
        className={clsx(styles.folderTrigger)}
        data-canvas-folder-name={bundle.label}
      >
        <Flex flexGrow="1" align="center" overflow="hidden" pb="2" pt="2">
          <Flex pl="2" align="center" flexShrink="0">
            <FolderIcon className={styles.folderIcon} />
          </Flex>
          <Flex px="2" align="center" flexGrow="1" overflow="hidden">
            <Text size="1" weight="medium">
              {bundle.label}
            </Text>
          </Flex>
          <Flex pl="2" align="end" flexShrink="0">
            <ChevronRightIcon
              className={clsx(styles.chevron, {
                [styles.isOpen]: isOpen,
              })}
            />
          </Flex>
        </Flex>
      </Collapsible.Trigger>
      <Collapsible.Content>
        <Flex pl="5" direction="column">
          {Object.entries(bundle.viewModes).map(([key, viewMode]) => (
            <SidebarNode
              key={viewMode.id}
              title={`${viewMode.viewModeLabel} template`}
              variant="template"
            />
          ))}
        </Flex>
      </Collapsible.Content>
    </Collapsible.Root>
  );
};

export default TemplateList;
