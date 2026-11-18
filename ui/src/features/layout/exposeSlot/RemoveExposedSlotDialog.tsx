import { ExternalLinkIcon } from '@radix-ui/react-icons';
import { Flex, Link, Text } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import Dialog from '@/components/Dialog';
import { removeExposedSlot } from '@/features/layout/layoutModelSlice';
import {
  selectDialogOpen,
  setDialogWithDataClosed,
} from '@/features/ui/dialogSlice';
import useEditFieldsUrl from '@/hooks/useEditFieldsUrl';

const RemoveExposedSlotDialog = () => {
  const dispatch = useAppDispatch();
  const { removeExposedSlotConfirm } = useAppSelector(selectDialogOpen);
  const { open, data } = removeExposedSlotConfirm;
  const editFieldsUrl = useEditFieldsUrl();

  const close = () => {
    dispatch(setDialogWithDataClosed('removeExposedSlotConfirm'));
  };

  const handleDetach = () => {
    if (!open) {
      return;
    }
    dispatch(removeExposedSlot(data.fieldName));
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
      title="Disable editing"
      footer={{
        cancelText: 'Cancel',
        confirmText: 'Disable editing',
        onConfirm: handleDetach,
      }}
    >
      <Flex direction="column" gap="3">
        <Text size="2">
          This will remove the ability to customize &ldquo;{data.label}&rdquo;
          per item. Content already entered by editors is preserved and
          reappears if you enable editing on this slot again.
        </Text>
        <Text size="2">
          To permanently delete the slot and its content, delete its field from
          the content type&rsquo;s field settings.
        </Text>
        {editFieldsUrl && (
          <Text size="2">
            <Link href={editFieldsUrl} target="_blank" rel="noreferrer">
              Manage fields <ExternalLinkIcon width="12" height="12" />
            </Link>
          </Text>
        )}
      </Flex>
    </Dialog>
  );
};

export default RemoveExposedSlotDialog;
