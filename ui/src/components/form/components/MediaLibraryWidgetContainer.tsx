import clsx from 'clsx';

import { a2p } from '@/local_packages/utils';

import styles from './MediaLibraryWidgetContainer.module.css';

interface MediaLibraryWidgetContainerProps {
  attributes: {
    class?: string;
    [key: string]: any;
  };
  children: React.ReactNode;
}
const MediaLibraryWidgetContainer = ({
  attributes,
  children,
}: MediaLibraryWidgetContainerProps) => {
  const classes = clsx(attributes.class, styles.container);
  return (
    <div
      {...a2p(attributes, {}, { skipAttributes: ['class'] })}
      className={classes}
    >
      {children}
    </div>
  );
};

export default MediaLibraryWidgetContainer;
