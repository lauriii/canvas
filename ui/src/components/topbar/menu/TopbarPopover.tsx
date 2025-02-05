import clsx from 'clsx';
import { Popover, Tooltip } from '@radix-ui/themes';
import Panel from '@/components/Panel';
import styles from './TopbarPopover.module.css';
import type React from 'react';
import type { ReactNode } from 'react';

interface TopbarPopoverProps {
  trigger: ReactNode;
  children: ReactNode;
  tooltip: string;
}

const TopbarPopover: React.FC<TopbarPopoverProps> = ({
  trigger,
  children,
  tooltip,
}) => {
  return (
    <Popover.Root>
      <Tooltip content={tooltip}>
        <Popover.Trigger>{trigger}</Popover.Trigger>
      </Tooltip>
      <Popover.Content asChild>
        <Panel className={clsx(styles.content, 'xb-app')}>{children}</Panel>
      </Popover.Content>
    </Popover.Root>
  );
};

export default TopbarPopover;
