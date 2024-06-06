import { useAppSelector } from '../../../app/hooks';
import { selectLayout, selectModel } from '../layoutModelSlice';
import { selectDragging, selectSelectedComponent } from '../../ui/uiSlice';
import { useEffect } from 'react';
import { Text } from '@radix-ui/themes';

const TreeDebug = () => {
  const model = useAppSelector(selectModel);
  const layout = useAppSelector(selectLayout);
  const draggingStatus = useAppSelector(selectDragging);
  const selectedComponent = useAppSelector(selectSelectedComponent);

  useEffect(() => {
    console.log('Layout updated', layout);
  }, [layout]);
  useEffect(() => {
    console.log('Model updated', model);
  }, [model]);

  return (
    <Text asChild={true} size="1">
      <div style={{ maxWidth: '260px', maxHeight: '400px', overflow: 'auto' }}>
        <pre>{selectedComponent}</pre>
        <pre>{JSON.stringify(draggingStatus, null, 2)}</pre>
        <pre>{JSON.stringify(model, null, 2)}</pre>
        <pre>{JSON.stringify(layout, null, 2)}</pre>
      </div>
    </Text>
  );
};

export default TreeDebug;
