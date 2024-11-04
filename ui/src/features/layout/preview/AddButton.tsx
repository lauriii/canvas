import { PlusIcon } from '@radix-ui/react-icons';
import { Button } from '@radix-ui/themes';
import {
  enableClickToInsert,
  LayoutItemType,
  setActivePanel,
  setOpenLayoutItem,
  selectActivePanel,
} from '@/features/ui/primaryPanelSlice';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { isChildNode } from '@/features/layout/layoutUtils';
import { selectLayout } from '@/features/layout/layoutModelSlice';

const AddButton = ({ elementId }: { elementId: string }) => {
  const dispatch = useAppDispatch();
  const layout = useAppSelector(selectLayout);
  const layoutItemType = isChildNode(layout, elementId)
    ? LayoutItemType.COMPONENT
    : LayoutItemType.SECTION;
  const selectActiveMenu = useAppSelector(selectActivePanel);

  const onClickHandler = () => {
    if (selectActiveMenu !== 'library') {
      dispatch(setActivePanel('library'));
    }
    if (layoutItemType === LayoutItemType.SECTION) {
      dispatch(setOpenLayoutItem(LayoutItemType.SECTION));
    } else dispatch(setOpenLayoutItem(LayoutItemType.COMPONENT));
    dispatch(
      enableClickToInsert({
        isEnabled: true,
        originUUID: elementId,
        originLayoutItemType: layoutItemType,
      }),
    );
  };

  return (
    <Button onClick={onClickHandler} aria-label={`Add ${layoutItemType}`}>
      <PlusIcon /> Add {layoutItemType}
    </Button>
  );
};

export default AddButton;
