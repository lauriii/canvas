import { AlertDialog, Button, Flex } from '@radix-ui/themes';

interface DeleteVariantDialogProps {
  // The variant being deleted, or null when the dialog is closed.
  variantId: string | null;
  onClose: () => void;
  onConfirm: () => void;
}

const DeleteVariantDialog = ({
  variantId,
  onClose,
  onConfirm,
}: DeleteVariantDialogProps) => (
  <AlertDialog.Root
    open={variantId !== null}
    onOpenChange={(isOpen) => {
      if (!isOpen) {
        onClose();
      }
    }}
  >
    <AlertDialog.Content>
      <AlertDialog.Title>Delete {variantId} variant</AlertDialog.Title>
      <AlertDialog.Description size="2">
        This removes the variant and its content from the page.
      </AlertDialog.Description>
      <Flex gap="3" mt="4" justify="end">
        <AlertDialog.Cancel>
          <Button variant="soft" color="gray">
            Cancel
          </Button>
        </AlertDialog.Cancel>
        <AlertDialog.Action>
          <Button variant="solid" color="red" onClick={onConfirm}>
            Delete variant
          </Button>
        </AlertDialog.Action>
      </Flex>
    </AlertDialog.Content>
  </AlertDialog.Root>
);

export default DeleteVariantDialog;
