import { PlusIcon } from '@radix-ui/react-icons';
import { Button } from '@radix-ui/themes';
import {
  enableClickToInsert,
  NodeType,
  setActiveSecondLevelMenu,
  ADD_MENU_ITEMS,
} from '@/features/ui/addMenuSlice';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { isChildNode } from '@/features/layout/layoutUtils';
import { selectLayout } from '@/features/layout/layoutModelSlice';

const AddButton = ({ elementId }: { elementId: string }) => {
  const dispatch = useAppDispatch();
  const layout = useAppSelector(selectLayout);
  const nodeType = isChildNode(layout, elementId)
    ? NodeType.COMPONENT
    : NodeType.SECTION;

  const onClickHandler = () => {
    if (nodeType === NodeType.SECTION) {
      dispatch(setActiveSecondLevelMenu(ADD_MENU_ITEMS.SECTION_ID));
    } else
      dispatch(setActiveSecondLevelMenu(ADD_MENU_ITEMS.DEFAULT_COMPONENTS_ID));
    dispatch(
      enableClickToInsert({
        isEnabled: true,
        originUUID: elementId,
        originNodeType: nodeType,
      }),
    );
  };

  return (
    <Button onClick={onClickHandler} aria-label={`Add ${nodeType}`}>
      <PlusIcon /> Add {nodeType}
    </Button>
  );
};

export default AddButton;
