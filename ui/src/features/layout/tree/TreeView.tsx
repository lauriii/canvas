import TreeParent from './TreeParent';
import { useAppSelector } from '@/app/hooks';
import { selectLayout } from '@/features/layout/layoutModelSlice';
import { selectDragging } from '@/features/ui/uiSlice';
import TreeDebug from './TreeDebug';
import treeParentStyles from './TreeParent.module.css';

const TreeView = () => {
  const layout = useAppSelector(selectLayout);
  const { isDragging } = useAppSelector(selectDragging);

  return (
    <div className={isDragging ? treeParentStyles.listDragging : ''}>
      <TreeParent node={layout} />

      <TreeDebug />
    </div>
  );
};

export default TreeView;
