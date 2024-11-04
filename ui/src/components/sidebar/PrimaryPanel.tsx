import clsx from 'clsx';
import styles from '@/components/sidebar/PrimaryPanel.module.css';
import {
  Box,
  Flex,
  ScrollArea,
  SegmentedControl,
  Separator,
} from '@radix-ui/themes';
import { useState } from 'react';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import Panel from '../Panel';
import { selectLayout } from '@/features/layout/layoutModelSlice';
import SortableContainer from '@/features/layout/tree/SortableContainer';
import Library from '@/components/sidebar/Library';
import {
  selectActivePanel,
  setActivePanel,
} from '@/features/ui/primaryPanelSlice';

export const PrimaryPanel = () => {
  const layout = useAppSelector(selectLayout);
  const activePanel = useAppSelector(selectActivePanel);
  const [dragging, setDragging] = useState(false);
  const dispatch = useAppDispatch();

  const onClickHandler = (clickedPanel: string) => {
    if (activePanel !== clickedPanel) {
      dispatch(setActivePanel(clickedPanel));
    }
  };

  return (
    <Panel className={clsx(styles.primaryPanel)} data-testid="xb-primary-panel">
      <Flex direction="column" height="100%">
        <SegmentedControl.Root
          onValueChange={setActivePanel}
          className={clsx(styles.segmentedControlRoot)}
          value={activePanel}
        >
          <SegmentedControl.Item
            value="layers"
            data-testid="xb-primary-panel--layers"
            onClick={() => onClickHandler('layers')}
          >
            Layers
          </SegmentedControl.Item>
          <SegmentedControl.Item
            value="library"
            data-testid="xb-primary-panel--library"
            onClick={() => onClickHandler('library')}
          >
            Library
          </SegmentedControl.Item>
        </SegmentedControl.Root>
        <Separator orientation="horizontal" size="4" />
        <ScrollArea>
          <Box
            pr="4"
            className={clsx('primaryPanelContent', styles.primaryPanelContent)}
            data-xb-is-dragging={dragging}
          >
            {activePanel === 'layers' && (
              <SortableContainer setDragging={setDragging} node={layout} />
            )}
            {activePanel === 'library' && <Library />}
          </Box>
        </ScrollArea>
      </Flex>
    </Panel>
  );
};

export default PrimaryPanel;
