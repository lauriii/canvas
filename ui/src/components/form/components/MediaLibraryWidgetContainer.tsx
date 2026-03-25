import { memo } from 'react';
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

interface MediaLibraryWidgetContainerInnerProps {
  attributes: {
    class?: string;
    [key: string]: any;
  };
  renderChildren: React.ReactNode;
}

/**
 * Bespoke memoization for the media library widget container.
 *
 * Problem: This component is rendered by the hyperscriptify system, which
 * converts server-rendered Twig HTML into React elements, causing unnecessary
 * re-renders that can reset the widget's DOM state (tabledrag, scroll
 * position, focus) and produce visual flicker.
 *
 * Solution — inner/outer split with reference-equality comparator:
 * - The outer `MediaLibraryWidgetContainer` is the public API. It accepts
 *   `children` and passes it as `renderChildren` to the inner component.
 * - The inner `MediaLibraryWidgetContainerInner` is memoized.
 *
 *   When hyperscriptify produces a stable element reference (i.e. the
 *   underlying HTML has not changed), the inner skips its render entirely.
 * - The prop rename (`children` -> `renderChildren`) makes the memoization
 *   intent explicit and avoids React's special handling of the `children`
 *   prop name in comparisons.
 */
const MediaLibraryWidgetContainerInner = memo(
  ({ attributes, renderChildren }: MediaLibraryWidgetContainerInnerProps) => {
    const classes = clsx(attributes.class, styles.container);

    return (
      <div
        {...a2p(attributes, {}, { skipAttributes: ['class'] })}
        className={classes}
      >
        {renderChildren}
      </div>
    );
  },
  (
    prevProps: MediaLibraryWidgetContainerInnerProps,
    nextProps: MediaLibraryWidgetContainerInnerProps,
  ) => {
    return (
      prevProps.renderChildren === nextProps.renderChildren &&
      prevProps.attributes === nextProps.attributes
    );
  },
);

const MediaLibraryWidgetContainer = ({
  attributes,
  children,
}: MediaLibraryWidgetContainerProps) => {
  return (
    <MediaLibraryWidgetContainerInner
      attributes={attributes}
      renderChildren={children}
    />
  );
};

MediaLibraryWidgetContainer.displayName = 'MediaLibraryWidgetContainer';

export default MediaLibraryWidgetContainer;
