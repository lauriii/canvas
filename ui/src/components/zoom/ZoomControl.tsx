import type React from 'react';
import styles from '@/components/zoom/ZoomControl.module.css';
import { Card, Flex, IconButton } from '@radix-ui/themes';
import { MinusIcon, PlusIcon, ZoomInIcon } from '@radix-ui/react-icons';
import { Select } from '@radix-ui/themes';
import {
  scaleValues,
  selectCanvasViewPort,
  setCanvasViewPort,
} from '@/features/ui/uiSlice';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import clsx from 'clsx';

const ZoomControl = () => {
  const dispatch = useAppDispatch();
  const canvasViewPort = useAppSelector(selectCanvasViewPort);

  const handleSliderChange = (event: React.ChangeEvent<HTMLInputElement>) => {
    const value = parseInt(event.target.value, 10);
    dispatch(setCanvasViewPort({ scale: value / 100 }));
  };

  const handleIncrement = () => {
    const currentScaleIndex = scaleValues.findIndex(
      (sv) => sv.scale === canvasViewPort.scale,
    );
    const nextScale =
      scaleValues[currentScaleIndex + 1]?.scale || scaleValues.at(-1)!.scale;

    dispatch(setCanvasViewPort({ scale: nextScale }));
  };

  const handleDecrement = () => {
    const currentScaleIndex = scaleValues.findIndex(
      (sv) => sv.scale === canvasViewPort.scale,
    );
    const prevScale =
      scaleValues[currentScaleIndex - 1]?.scale || scaleValues.at(0)!.scale;

    dispatch(setCanvasViewPort({ scale: prevScale }));
  };

  return (
    <div className={styles.canvasControls}>
      <Card size="1" className={styles.canvasCard}>
        <Flex align="center" gap="3">
          <Flex as="span" align="center" gap="3">
            <IconButton
              size="1"
              aria-label="Zoom out"
              onClick={handleDecrement}
            >
              <MinusIcon />
            </IconButton>

            <input
              type="range"
              min={scaleValues[0].scale * 100}
              max={scaleValues.at(-1)!.scale * 100}
              value={canvasViewPort.scale * 100}
              onChange={handleSliderChange}
              className={styles.rangeSlider}
              aria-label="Canvas zoom level"
            />
            <IconButton size="1" aria-label="Zoom in" onClick={handleIncrement}>
              <PlusIcon />
            </IconButton>
            <Flex mr="2">
              <Select.Root
                defaultValue="100%"
                // @ts-ignore - setting value to null when scale doesn't match unsets the selected value
                value={
                  scaleValues.find((sv) => sv.scale === canvasViewPort.scale)
                    ?.percent || null
                }
                onValueChange={(value) =>
                  dispatch(
                    setCanvasViewPort({
                      scale: scaleValues.find((sv) => value === sv.percent)
                        ?.scale,
                    }),
                  )
                }
              >
                <Select.Trigger variant="ghost" aria-label="Select zoom level">
                  <Flex
                    as="span"
                    align="center"
                    gap="2"
                    className={styles.zoomControlSelect}
                  >
                    <ZoomInIcon />
                    {Math.round(canvasViewPort.scale * 100)}%
                  </Flex>
                </Select.Trigger>
                <Select.Content
                  position="popper"
                  data-testid="zoom-select-menu"
                >
                  {scaleValues.map((sv) => (
                    <Select.Item
                      key={sv.scale}
                      value={sv.percent}
                      className={clsx({
                        [styles.oneHundred]: sv.scale === 1,
                      })}
                    >
                      {sv.percent}
                    </Select.Item>
                  ))}
                </Select.Content>
              </Select.Root>
            </Flex>
          </Flex>
        </Flex>
      </Card>
    </div>
  );
};

export default ZoomControl;
