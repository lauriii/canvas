import clsx from 'clsx';
import styles from '@/components/sidebar/PrimaryMenu.module.css';
import { SegmentedControl, Separator } from '@radix-ui/themes';
import { useState } from 'react';
import { useAppSelector } from '@/app/hooks';
import Panel from '../Panel';
import { selectLayout } from '@/features/layout/layoutModelSlice';
import SortableContainer from '@/features/layout/tree/SortableContainer';

export const PrimaryMenu = () => {
  const [activeItem, setActiveItem] = useState('layers');
  const layout = useAppSelector(selectLayout);
  const [dragging, setDragging] = useState(false);

  return (
    <Panel
      className={clsx('MenuRoot', styles.MenuRoot)}
      data-testid="xb-menu-root"
    >
      <SegmentedControl.Root
        defaultValue="layers"
        onValueChange={setActiveItem}
        className={clsx(styles.segmentedControlRoot)}
      >
        <SegmentedControl.Item value="layers">Layers</SegmentedControl.Item>
        <SegmentedControl.Item value="assets">Assets</SegmentedControl.Item>
      </SegmentedControl.Root>
      <Separator orientation="horizontal" size="4" />
      <div
        className={clsx('primaryMenuContent', styles.menuContent)}
        data-xb-is-dragging={dragging}
      >
        {activeItem === 'layers' && (
          <SortableContainer setDragging={setDragging} node={layout} />
        )}
        {activeItem === 'assets' && <h3>Assets placeholder</h3>}
      </div>
    </Panel>
  );
};

export default PrimaryMenu;
