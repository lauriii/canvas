import { useEffect, useState } from 'react';
import { Flex, Text, TextField } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import Dialog, { DialogFieldLabel } from '@/components/Dialog';
import {
  addExposedSlot,
  selectExposedSlots,
  updateExposedSlotLabel,
} from '@/features/layout/layoutModelSlice';
import {
  selectDialogOpen,
  setDialogWithDataClosed,
} from '@/features/ui/dialogSlice';
import {
  deriveExposedSlotAlias,
  validateExposedSlotAlias,
} from '@/features/validation/validation';

import type { ExposeSlotDialogData } from '@/features/ui/dialogSlice';

const ExposeSlotDialog = () => {
  const dispatch = useAppDispatch();
  const { exposeSlot } = useAppSelector(selectDialogOpen);
  const exposedSlots = useAppSelector(selectExposedSlots);
  const { open } = exposeSlot;
  const data = exposeSlot.data as ExposeSlotDialogData;

  const isEditMode = open && data.mode === 'editLabel';

  const [label, setLabel] = useState('');
  const [alias, setAlias] = useState('');
  // Once the machine name is edited by hand it stops tracking the label.
  const [aliasEdited, setAliasEdited] = useState(false);

  // Initialize field state whenever the dialog is (re)opened.
  useEffect(() => {
    if (open) {
      setLabel(data.label ?? '');
      setAlias(data.alias ?? '');
      setAliasEdited(data.mode === 'editLabel');
    }
  }, [open, data.mode, data.alias, data.label]);

  // Aliases already used in this template (excluding the one being edited).
  const existingAliases = Object.keys(exposedSlots ?? {}).filter(
    (existing) => !(isEditMode && existing === data.alias),
  );

  const trimmedLabel = label.trim();
  const labelError = !trimmedLabel ? 'This field is required.' : '';
  const aliasError = isEditMode
    ? ''
    : validateExposedSlotAlias(alias, existingAliases);

  const handleLabelChange = (value: string) => {
    setLabel(value);
    // Auto-derive the machine name from the label until it is edited by hand.
    if (!isEditMode && !aliasEdited) {
      setAlias(deriveExposedSlotAlias(value));
    }
  };

  const handleAliasChange = (value: string) => {
    setAliasEdited(true);
    setAlias(value);
  };

  const close = () => {
    dispatch(setDialogWithDataClosed('exposeSlot'));
  };

  const canSubmit = isEditMode
    ? !!trimmedLabel
    : !!trimmedLabel && !!alias.trim() && !aliasError;

  const handleConfirm = () => {
    if (!canSubmit) {
      return;
    }
    if (isEditMode) {
      dispatch(
        updateExposedSlotLabel({ alias: data.alias!, label: trimmedLabel }),
      );
    } else {
      dispatch(
        addExposedSlot({
          alias,
          label: trimmedLabel,
          slotName: data.slotName,
          componentUuid: data.componentUuid,
        }),
      );
    }
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
      title={isEditMode ? 'Edit slot label' : 'Expose slot'}
      description={
        isEditMode
          ? 'Rename this exposed slot. The machine name cannot be changed.'
          : 'Exposes a slot so a user can drag items into an area of their template.'
      }
      footer={{
        cancelText: 'Cancel',
        confirmText: isEditMode ? 'Save' : 'Expose slot',
        onConfirm: handleConfirm,
        isConfirmDisabled: !canSubmit,
      }}
    >
      <form
        onSubmit={(e) => {
          e.preventDefault();
          handleConfirm();
        }}
      >
        <Flex direction="column" gap="2">
          <DialogFieldLabel htmlFor="exposedSlotLabel">
            Slot name
          </DialogFieldLabel>
          <TextField.Root
            autoComplete="off"
            id="exposedSlotLabel"
            value={label}
            onChange={(e) => handleLabelChange(e.target.value)}
            placeholder="Enter a name"
            size="1"
          />
          {open && labelError && (
            <Text size="1" color="red" weight="medium">
              {labelError}
            </Text>
          )}

          <DialogFieldLabel htmlFor="exposedSlotAlias">
            Machine name
          </DialogFieldLabel>
          <TextField.Root
            autoComplete="off"
            id="exposedSlotAlias"
            value={alias}
            onChange={(e) => handleAliasChange(e.target.value)}
            placeholder="my_exposed_slot"
            size="1"
            disabled={isEditMode}
            readOnly={isEditMode}
          />
          {isEditMode ? (
            <Text size="1" color="gray">
              The machine name cannot be changed after creation.
            </Text>
          ) : (
            alias.trim() &&
            aliasError && (
              <Text size="1" color="red" weight="medium">
                {aliasError}
              </Text>
            )
          )}
        </Flex>
      </form>
    </Dialog>
  );
};

export default ExposeSlotDialog;
