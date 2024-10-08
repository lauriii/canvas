import type React from 'react';
import { Slot } from '@radix-ui/react-slot';
import { Box } from '@radix-ui/themes';
import clsx from 'clsx';

import styles from './Panel.module.css';

const Panel: React.FC<React.ComponentProps<typeof Box>> = (props) => {
  const { asChild, className } = props;
  const Comp = asChild ? Slot : Box;
  return <Comp {...props} className={clsx(styles.panel, className)} />;
};

export default Panel;
