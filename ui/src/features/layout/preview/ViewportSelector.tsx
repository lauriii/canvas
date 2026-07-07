import { useCallback, useLayoutEffect, useMemo } from 'react';
import { Button, DropdownMenu } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import BreakpointIcon from '@/components/BreakpointIcon';
import {
  selectViewportWidth,
  setViewportMinHeight,
  setViewportWidth,
} from '@/features/ui/uiSlice';
import { getViewportSizes } from '@/utils/viewports';

import type React from 'react';
import type { viewportSize } from '@/types/Preview';

const STORED_VIEWPORT_KEY = 'Canvas.editorFrame.viewportSize';

interface ViewportSelectorProps {
  buttonClassName?: string;
}

const ViewportSelector: React.FC<ViewportSelectorProps> = ({
  buttonClassName,
}) => {
  const dispatch = useAppDispatch();
  const currentWidth = useAppSelector(selectViewportWidth);
  const viewportSizes = useMemo(() => getViewportSizes(), []);

  const getViewportByWidth = useCallback(
    (width: number): viewportSize => {
      const viewportSize = viewportSizes.find((vw) => vw.width === width);
      if (!viewportSize) {
        throw new Error(`No viewport found with width: ${width}`);
      }
      return viewportSize;
    },
    [viewportSizes],
  );

  const getViewportById = useCallback(
    (id: string): viewportSize => {
      const viewportSize = viewportSizes.find((vw) => vw.id === id);
      if (!viewportSize) {
        throw new Error(`No viewport found with id: ${id}`);
      }
      return viewportSize;
    },
    [viewportSizes],
  );

  const handleWidthClick = (viewportSize: viewportSize) => {
    dispatch(setViewportWidth(viewportSize.width));
    dispatch(setViewportMinHeight(viewportSize.height));
    localStorage.setItem(STORED_VIEWPORT_KEY, viewportSize.id);
  };

  useLayoutEffect(() => {
    const storedViewportId = localStorage.getItem(STORED_VIEWPORT_KEY);
    const viewportSize = currentWidth
      ? getViewportByWidth(currentWidth)
      : getViewportById(storedViewportId || 'tablet');

    dispatch(setViewportWidth(viewportSize.width));
    dispatch(setViewportMinHeight(viewportSize.height));
  }, [currentWidth, dispatch, getViewportById, getViewportByWidth]);

  return (
    <DropdownMenu.Root>
      <DropdownMenu.Trigger>
        <Button
          variant="surface"
          size="1"
          color="gray"
          className={buttonClassName}
        >
          <BreakpointIcon width={currentWidth} />
          {currentWidth
            ? getViewportByWidth(currentWidth).name
            : 'Select viewport'}
          <DropdownMenu.TriggerIcon />
        </Button>
      </DropdownMenu.Trigger>
      <DropdownMenu.Content size="1">
        {viewportSizes.map((viewportSize) => (
          <DropdownMenu.Item
            key={viewportSize.id}
            onClick={() => handleWidthClick(viewportSize)}
            color={viewportSize.width === currentWidth ? 'blue' : undefined}
          >
            <BreakpointIcon width={viewportSize.width} />
            {viewportSize.name} ({viewportSize.width}px)
          </DropdownMenu.Item>
        ))}
      </DropdownMenu.Content>
    </DropdownMenu.Root>
  );
};

export default ViewportSelector;
