import clsx from 'clsx';
import ScaleToFitIcon from '@assets/icons/justify-stretch.svg?react';
import { Button, Flex, Tooltip } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import ZoomControl from '@/components/zoom/ZoomControl';
import ViewportSelector from '@/features/layout/preview/ViewportSelector';
import {
  scaleValues,
  selectViewportWidth,
  setEditorFrameViewPort,
} from '@/features/ui/uiSlice';
import { getHalfwayScrollPosition } from '@/utils/function-utils';

import type React from 'react';
import type { RefObject } from 'react';
import type { ScaleValue } from '@/features/ui/uiSlice';

import styles from './ViewportToolbar.module.css';

interface ViewportToolbarProps {
  editorPaneRef: RefObject<HTMLElement>;
  scalingContainerRef: RefObject<HTMLElement>;
}

const findClosestScaleValue = (desiredScale: number): ScaleValue => {
  // Filter the list to find all scales less than or equal to desiredScale. Remove an extra 0.01 from the scale to bias
  // towards dropping down a scale in case of an almost exact fit in the viewport (it looks nicer to have a bit of gap).
  const filteredScales = scaleValues.filter(
    (value) => value.scale <= desiredScale - 0.01,
  );

  // If there's any valid scale in the filtered list, return largest one.
  if (filteredScales.length > 0) {
    return filteredScales.reduce((prev, curr) =>
      curr.scale > prev.scale ? curr : prev,
    );
  }

  // If no scales are less than or equal to desiredScale, return the smallest available scale.
  return scaleValues[0];
};

const ViewportToolbar: React.FC<ViewportToolbarProps> = (props) => {
  const { editorPaneRef, scalingContainerRef } = props;
  const dispatch = useAppDispatch();
  const currentWidth = useAppSelector(selectViewportWidth);

  const handleScaleToFit = () => {
    if (editorPaneRef.current) {
      const editorFrameContainerWidth =
        editorPaneRef.current.getBoundingClientRect().width;
      const scaleToFit = editorFrameContainerWidth / currentWidth;
      const closestScale = findClosestScaleValue(scaleToFit);
      dispatch(
        setEditorFrameViewPort({
          scale: closestScale.scale < 1 ? closestScale.scale : 1,
        }),
      );

      requestAnimationFrame(() => {
        if (editorPaneRef.current && scalingContainerRef.current) {
          // Calculate the height of the preview (getBoundingClientRect takes into account scaling).
          const previewHeight =
            scalingContainerRef.current.getBoundingClientRect().height;

          // Calculate the center offset inside the editor frame.
          const editorFrameHeight = editorPaneRef.current.scrollHeight;
          const centerOffset = (editorFrameHeight - previewHeight) / 2;

          const y = centerOffset - 50;
          dispatch(
            setEditorFrameViewPort({
              x: getHalfwayScrollPosition(editorPaneRef.current),
              y,
            }),
          );
        }
      });
    }
  };

  return (
    <Flex
      className={styles.toolbar}
      gap="2"
      data-testid="canvas-editor-frame-controls"
    >
      <ViewportSelector
        buttonClassName={clsx(styles.toolbarButton, styles.viewportSelect)}
      />
      <Tooltip side="bottom" content={'Scale to fit'}>
        <Button
          size="1"
          onClick={handleScaleToFit}
          color="gray"
          variant="surface"
          highContrast
          className={styles.toolbarButton}
          data-testid="scale-to-fit"
        >
          <ScaleToFitIcon />
        </Button>
      </Tooltip>
      <ZoomControl buttonClass={styles.toolbarButton} />
    </Flex>
  );
};

export default ViewportToolbar;
