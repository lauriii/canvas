import clsx from 'clsx';
import * as Tooltip from '@radix-ui/react-tooltip';
import type { ReactElement } from 'react';
import styles from './Tooltip.module.css';

const TooltipComponent = ({
  children,
  content,
}: {
  children: ReactElement;
  content: string;
}) => {
  return (
    <Tooltip.Provider>
      <Tooltip.Root delayDuration={0}>
        <Tooltip.Trigger>{children}</Tooltip.Trigger>
        <Tooltip.Portal>
          <Tooltip.Content
            side="right"
            className={clsx('TooltipContent', styles.TooltipContent)}
          >
            {content}
            <Tooltip.Arrow
              className={clsx('TooltipArrow', styles.TooltipArrow)}
            />
          </Tooltip.Content>
        </Tooltip.Portal>
      </Tooltip.Root>
    </Tooltip.Provider>
  );
};

export default TooltipComponent;
