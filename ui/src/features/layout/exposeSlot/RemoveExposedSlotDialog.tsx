import { Button, Flex, Text } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import Dialog from '@/components/Dialog';
import {
  removeExposedSlot,
  setExposedSlotDisabled,
} from '@/features/layout/layoutModelSlice';
import {
  selectDialogOpen,
  setDialogWithDataClosed,
} from '@/features/ui/dialogSlice';

import type { RemoveExposedSlotDialogData } from '@/features/ui/dialogSlice';

const RemoveExposedSlotDialog = () => {
  const dispatch = useAppDispatch();
  const { removeExposedSlotConfirm } = useAppSelector(selectDialogOpen);
  const { open } = removeExposedSlotConfirm;
  const data = removeExposedSlotConfirm.data as RemoveExposedSlotDialogData;

  const close = () => {
    dispatch(setDialogWithDataClosed('removeExposedSlotConfirm'));
  };

  const handleRemove = () => {
    dispatch(removeExposedSlot(data.alias));
    close();
  };

  const handleDisableInstead = () => {
    dispatch(setExposedSlotDisabled({ alias: data.alias, disabled: true }));
    close();
  };

  return (
    <Dialog
      open={open}
      onOpenChange={(isOpen) => {
        if (!isOpen) {
          close();
        }
      }}
      title="Remove exposed slot"
      footer={{
        cancelText: 'Cancel',
        confirmText: 'Remove',
        onConfirm: handleRemove,
        isDanger: true,
      }}
    >
      <Flex direction="column" gap="3">
        <Text size="2">
          Removing exposed slot &ldquo;{data.label}&rdquo; deletes its
          definition. Any content editors placed in this slot will be
          permanently purged the next time affected content is saved.
        </Text>
        <Text size="2">
          To keep the slot and its content but hide it from content editors,
          disable it instead.
        </Text>
        <Flex justify="start">
          <Button variant="soft" size="1" onClick={handleDisableInstead}>
            Disable instead
          </Button>
        </Flex>
      </Flex>
    </Dialog>
  );
};

export default RemoveExposedSlotDialog;
