import type React from 'react';
import type { RefObject } from 'react';
import { useLayoutEffect } from 'react';
import { Button, DropdownMenu, Flex, Tooltip } from '@radix-ui/themes';
import ScaleToFitIcon from '@assets/icons/justify-stretch.svg?react';
import styles from './ViewportToolbar.module.css';
import ZoomControl from '@/components/zoom/ZoomControl';
import clsx from 'clsx';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import type { ScaleValue } from '@/features/ui/uiSlice';
import {
  scaleValues,
  selectViewportWidth,
  setCanvasViewPort,
  setViewportMinHeight,
  setViewportWidth,
} from '@/features/ui/uiSlice';
import { getHalfwayScrollPosition } from '@/utils/function-utils';
import BreakpointIcon from '@/components/BreakpointIcon';
import type { viewportSize } from '@/types/Preview';
import { viewportSizes } from '@/types/Preview';

interface ViewportToolbarProps {
  canvasPaneRef: RefObject<HTMLElement>;
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
  const { canvasPaneRef, scalingContainerRef } = props;
  const dispatch = useAppDispatch();
  const currentWidth = useAppSelector(selectViewportWidth);
  const handleWidthClick = (viewportSize: viewportSize) => {
    dispatch(setViewportWidth(viewportSize.width));
    dispatch(setViewportMinHeight(viewportSize.height));
  };

  const getViewportByWidth = (width: number) => {
    return viewportSizes.find((vw) => vw.width === width);
  };

  const getViewportByName = (name: string) => {
    return viewportSizes.find((vw) => vw.name === name);
  };

  const handleScaleToFit = () => {
    if (canvasPaneRef.current) {
      const canvasContainerWidth =
        canvasPaneRef.current.getBoundingClientRect().width;
      const scaleToFit = canvasContainerWidth / currentWidth;
      const closestScale = findClosestScaleValue(scaleToFit);
      dispatch(
        setCanvasViewPort({
          scale: closestScale.scale < 1 ? closestScale.scale : 1,
        }),
      );

      requestAnimationFrame(() => {
        if (canvasPaneRef.current && scalingContainerRef.current) {
          // Calculate the height of the preview (getBoundingClientRect takes into account scaling).
          const previewHeight =
            scalingContainerRef.current.getBoundingClientRect().height;

          // Calculate the center offset inside the canvas.
          const canvasHeight = canvasPaneRef.current.scrollHeight;
          const centerOffset = (canvasHeight - previewHeight) / 2;

          const y = centerOffset - 50;
          dispatch(
            setCanvasViewPort({
              x: getHalfwayScrollPosition(canvasPaneRef.current),
              y,
            }),
          );
        }
      });
    }
  };

  useLayoutEffect(() => {
    const defaultVs = getViewportByName('Tablet') as viewportSize;
    dispatch(setViewportWidth(defaultVs.width));
    dispatch(setViewportMinHeight(defaultVs.height));
  }, [dispatch]);

  return (
    <Flex className={styles.toolbar} gap="2" data-testid="xb-canvas-controls">
      <DropdownMenu.Root>
        <DropdownMenu.Trigger>
          <Button
            variant="surface"
            size="1"
            color="gray"
            className={clsx(styles.toolbarButton, styles.viewportSelect)}
          >
            <BreakpointIcon width={currentWidth} />
            {getViewportByWidth(currentWidth)?.name}
            <DropdownMenu.TriggerIcon />
          </Button>
        </DropdownMenu.Trigger>
        <DropdownMenu.Content size="1">
          {viewportSizes.map((vs) => (
            <DropdownMenu.Item
              key={vs.name}
              onClick={() => handleWidthClick(vs)}
              color={vs.width === currentWidth ? 'blue' : undefined}
            >
              <BreakpointIcon width={vs.width} />
              {vs.name} ({vs.width}px)
            </DropdownMenu.Item>
          ))}
        </DropdownMenu.Content>
      </DropdownMenu.Root>
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
