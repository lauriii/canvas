import { Flex, Text } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import Dialog from '@/components/Dialog';
import { deleteComponentAndExposedSlots } from '@/features/layout/layoutModelSlice';
import {
  selectDialogOpen,
  setDialogWithDataClosed,
} from '@/features/ui/dialogSlice';
import { clearSelection, unsetHoveredComponent } from '@/features/ui/uiSlice';

const DeleteComponentWithExposedSlotsDialog = () => {
  const dispatch = useAppDispatch();
  const { deleteComponentWithExposedSlots } = useAppSelector(selectDialogOpen);
  const { open, data } = deleteComponentWithExposedSlots;

  const close = () => {
    dispatch(setDialogWithDataClosed('deleteComponentWithExposedSlots'));
  };

  const handleDelete = () => {
    if (!open) {
      return;
    }
    dispatch(
      deleteComponentAndExposedSlots({
        uuid: data.componentUuid,
        fieldNames: data.fieldNames,
      }),
    );
    dispatch(clearSelection());
    dispatch(unsetHoveredComponent());
    close();
  };

  const slotNames = (data.labels ?? []).map((label) => `“${label}”`).join(', ');
  const isPlural = (data.labels ?? []).length > 1;

  return (
    <Dialog
      open={open}
      onOpenChange={(isOpen) => {
        if (!isOpen) {
          close();
        }
      }}
      title="Delete component with editable slots"
      footer={{
        cancelText: 'Cancel',
        confirmText: 'Delete',
        onConfirm: handleDelete,
        isDanger: true,
      }}
    >
      <Flex direction="column" gap="3">
        <Text size="2">
          &ldquo;{data.componentName}&rdquo; hosts editable{' '}
          {isPlural ? 'slots' : 'slot'} {slotNames}. Deleting this component
          disables editing on {isPlural ? 'those slots' : 'that slot'}.
        </Text>
        <Text size="2">
          Content already entered by editors is preserved in{' '}
          {isPlural ? 'their fields' : 'its field'} and reappears if you enable
          editing on {isPlural ? 'these slots' : 'this slot'} again.
        </Text>
      </Flex>
    </Dialog>
  );
};

export default DeleteComponentWithExposedSlotsDialog;
