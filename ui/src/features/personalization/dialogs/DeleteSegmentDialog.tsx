import { AlertDialog, Button, Flex } from '@radix-ui/themes';

import { useDeleteSegmentMutation } from '@/services/personalization';

import type { Segment } from '@/types/Personalization';

interface DeleteSegmentDialogProps {
  // The segment being deleted, or null when the dialog is closed.
  segment: Segment | null;
  onClose: () => void;
}

const DeleteSegmentDialog = ({
  segment,
  onClose,
}: DeleteSegmentDialogProps) => {
  const [deleteSegment, { isLoading }] = useDeleteSegmentMutation();

  const handleDelete = async () => {
    if (!segment) {
      return;
    }
    await deleteSegment(segment.id);
    onClose();
  };

  return (
    <AlertDialog.Root
      open={segment !== null}
      onOpenChange={(isOpen) => {
        if (!isOpen) {
          onClose();
        }
      }}
    >
      <AlertDialog.Content>
        <AlertDialog.Title>Delete {segment?.label} segment</AlertDialog.Title>
        <AlertDialog.Description size="2">
          This will permanently delete the segment and its rules. This action
          cannot be undone.
        </AlertDialog.Description>
        <Flex gap="3" mt="4" justify="end">
          <AlertDialog.Cancel>
            <Button variant="soft" color="gray">
              Cancel
            </Button>
          </AlertDialog.Cancel>
          <AlertDialog.Action>
            <Button
              variant="solid"
              color="red"
              loading={isLoading}
              onClick={handleDelete}
            >
              Delete segment
            </Button>
          </AlertDialog.Action>
        </Flex>
      </AlertDialog.Content>
    </AlertDialog.Root>
  );
};

export default DeleteSegmentDialog;
