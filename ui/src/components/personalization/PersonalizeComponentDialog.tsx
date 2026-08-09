import { AlertDialog, Button, Flex } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { personalizeComponent } from '@/features/layout/layoutModelSlice';
import { getP13nComponentTypes } from '@/features/layout/personalizationUtils';
import {
  selectDialogOpen,
  setDialogWithDataClosed,
} from '@/features/ui/dialogSlice';
import { useGetComponentsQuery } from '@/services/componentAndLayout';

/**
 * Confirmation for personalizing a single component from the component
 * context menu. The dialog is mounted globally because the context menu that
 * opens it unmounts when the menu closes.
 */
const PersonalizeComponentDialog = () => {
  const dispatch = useAppDispatch();
  const { personalizeComponentConfirm } = useAppSelector(selectDialogOpen);
  const { data: components } = useGetComponentsQuery();

  const componentUuid =
    'componentUuid' in personalizeComponentConfirm.data
      ? personalizeComponentConfirm.data.componentUuid
      : undefined;

  const handleClose = () => {
    dispatch(setDialogWithDataClosed('personalizeComponentConfirm'));
  };

  const handleConfirm = () => {
    const p13nTypes = getP13nComponentTypes(components);
    if (componentUuid && p13nTypes) {
      dispatch(personalizeComponent({ componentUuid, ...p13nTypes }));
    }
    handleClose();
  };

  return (
    <AlertDialog.Root
      open={personalizeComponentConfirm.open}
      onOpenChange={(open) => {
        if (!open) {
          handleClose();
        }
      }}
    >
      <AlertDialog.Content>
        <AlertDialog.Title>Personalize this component</AlertDialog.Title>
        <AlertDialog.Description size="2">
          This makes the component variant-aware. Its current content becomes
          the Default variant.
        </AlertDialog.Description>
        <Flex gap="3" mt="4" justify="end">
          <AlertDialog.Cancel>
            <Button variant="soft" color="gray">
              Cancel
            </Button>
          </AlertDialog.Cancel>
          <AlertDialog.Action>
            <Button variant="solid" onClick={handleConfirm}>
              Personalize component
            </Button>
          </AlertDialog.Action>
        </Flex>
      </AlertDialog.Content>
    </AlertDialog.Root>
  );
};

export default PersonalizeComponentDialog;
