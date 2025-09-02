import { useCallback } from 'react';
import { PlusIcon } from '@radix-ui/react-icons';
import { Button } from '@radix-ui/themes';

import { useAppDispatch } from '@/app/hooks';
import { openAddDialog } from '@/features/ui/codeComponentDialogSlice';
import {
  LayoutItemType,
  setOpenLayoutItem,
} from '@/features/ui/primaryPanelSlice';

const AddCodeComponentButton = () => {
  const dispatch = useAppDispatch();

  const handleClick = useCallback(() => {
    dispatch(openAddDialog());
    dispatch(setOpenLayoutItem(LayoutItemType.CODE));
  }, [dispatch]);

  return (
    <Button onClick={handleClick} variant="soft" size="1">
      <PlusIcon />
      Add new
    </Button>
  );
};

export default AddCodeComponentButton;
