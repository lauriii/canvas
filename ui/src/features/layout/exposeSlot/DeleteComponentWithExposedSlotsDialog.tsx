import { Flex, Text } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import Dialog from '@/components/Dialog';
import { deleteComponentAndExposedSlots } from '@/features/layout/layoutModelSlice';
import {
  selectDialogOpen,
  setDialogWithDataClosed,
} from '@/features/ui/dialogSlice';
import { clearSelection, unsetHoveredComponent } from '@/features/ui/uiSlice';

import type { DeleteComponentWithExposedSlotsDialogData } from '@/features/ui/dialogSlice';

const DeleteComponentWithExposedSlotsDialog = () => {
  const dispatch = useAppDispatch();
  const { deleteComponentWithExposedSlots } = useAppSelector(selectDialogOpen);
  const { open } = deleteComponentWithExposedSlots;
  const data =
    deleteComponentWithExposedSlots.data as DeleteComponentWithExposedSlotsDialogData;

  const close = () => {
    dispatch(setDialogWithDataClosed('deleteComponentWithExposedSlots'));
  };

  const handleDelete = () => {
    dispatch(
      deleteComponentAndExposedSlots({
        uuid: data.componentUuid,
        aliases: data.aliases,
      }),
    );
    dispatch(clearSelection());
    dispatch(unsetHoveredComponent());
    close();
  };

  const slotNames = (data.labels ?? []).map((label) => `"${label}"`).join(', ');
  const isPlural = (data.labels ?? []).length > 1;

  return (
    <Dialog
      open={open}
      onOpenChange={(isOpen) => {
        if (!isOpen) {
          close();
        }
      }}
      title="Delete component with exposed slots"
      footer={{
        cancelText: 'Cancel',
        confirmText: 'Delete',
        onConfirm: handleDelete,
        isDanger: true,
      }}
    >
      <Flex direction="column" gap="3">
        <Text size="2">
          &ldquo;{data.componentName}&rdquo; hosts exposed{' '}
          {isPlural ? 'slots' : 'slot'} {slotNames}. Deleting this component
          detaches {isPlural ? 'those exposed slots' : 'that exposed slot'}.
        </Text>
        <Text size="2">
          Content already entered by editors is preserved in{' '}
          {isPlural ? 'their fields' : 'its field'} and reappears if{' '}
          {isPlural ? 'these slots are' : 'this slot is'} exposed again.
        </Text>
      </Flex>
    </Dialog>
  );
};

export default DeleteComponentWithExposedSlotsDialog;
