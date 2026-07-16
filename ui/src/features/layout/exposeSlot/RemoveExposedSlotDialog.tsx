import { Flex, Text } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import Dialog from '@/components/Dialog';
import { removeExposedSlot } from '@/features/layout/layoutModelSlice';
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

  const handleDetach = () => {
    dispatch(removeExposedSlot(data.alias));
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
      title="Detach exposed slot"
      footer={{
        cancelText: 'Cancel',
        confirmText: 'Detach',
        onConfirm: handleDetach,
      }}
    >
      <Flex direction="column" gap="3">
        <Text size="2">
          Detaching &ldquo;{data.label}&rdquo; removes it from this template.
          Content already entered by editors is preserved and reappears if you
          expose this slot again.
        </Text>
        <Text size="2">
          To permanently delete the slot and its content, delete its field from
          the content type&rsquo;s field settings.
        </Text>
      </Flex>
    </Dialog>
  );
};

export default RemoveExposedSlotDialog;
