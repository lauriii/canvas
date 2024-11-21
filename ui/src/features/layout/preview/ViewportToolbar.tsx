import type React from 'react';
import { DesktopIcon, MobileIcon, PlusIcon } from '@radix-ui/react-icons';
import { Button, Flex } from '@radix-ui/themes';
import styles from './ViewportToolbar.module.css';
import type { ViewportProps } from '@/features/layout/preview/Viewport';
import { handleNonWorkingBtn } from '@/utils/function-utils';

type ViewportToolbarProps = Pick<
  ViewportProps,
  'size' | 'name' | 'width' | 'height'
>;

const iconMapping = {
  lg: DesktopIcon,
  sm: MobileIcon,
};

const ViewportToolbar: React.FC<ViewportToolbarProps> = (props) => {
  const { size, name, height, width } = props;
  const IconComponent = iconMapping[size];

  return (
    <Flex className={styles.toolbar} mb="2" p="3">
      <Flex className={styles.iconContainer} justify="center" align="center">
        <IconComponent color="#fff" height="16" width="16" />
      </Flex>
      &nbsp;
      <span title={`${width}px x ${height}px`}>{name}</span>
      <Button ml="auto" onClick={handleNonWorkingBtn}>
        <PlusIcon />
      </Button>
    </Flex>
  );
};

export default ViewportToolbar;
