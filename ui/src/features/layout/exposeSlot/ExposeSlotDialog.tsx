import { useEffect, useState } from 'react';
import { useParams } from 'react-router';
import {
  Flex,
  SegmentedControl,
  Select,
  Text,
  TextField,
} from '@radix-ui/themes';
import { skipToken } from '@reduxjs/toolkit/query';

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
  deriveSlotFieldName,
  validateSlotFieldName,
} from '@/features/validation/validation';
import {
  useCreateSlotFieldMutation,
  useGetSlotFieldCandidatesQuery,
} from '@/services/slotFields';

import type { ExposeSlotDialogData } from '@/features/ui/dialogSlice';

type Tab = 'create' | 'existing';

const ExposeSlotDialog = () => {
  const dispatch = useAppDispatch();
  const { entityType, bundle, viewMode } = useParams();
  const contentTemplateId =
    entityType && bundle && viewMode
      ? `${entityType}.${bundle}.${viewMode}`
      : undefined;
  const { exposeSlot } = useAppSelector(selectDialogOpen);
  const exposedSlots = useAppSelector(selectExposedSlots);
  const { open } = exposeSlot;
  const data = exposeSlot.data as ExposeSlotDialogData;

  const isEditMode = open && data.mode === 'editLabel';

  const [tab, setTab] = useState<Tab>('create');
  const [label, setLabel] = useState('');
  const [fieldName, setFieldName] = useState('');
  // Once the machine name is edited by hand it stops tracking the label.
  const [fieldNameEdited, setFieldNameEdited] = useState(false);
  const [existingField, setExistingField] = useState('');
  const [submitError, setSubmitError] = useState('');

  const [createSlotField, { isLoading: isCreating }] =
    useCreateSlotFieldMutation();
  // The bundle's existing component_tree fields, for the "use existing" path.
  const { data: candidates } = useGetSlotFieldCandidatesQuery(
    !isEditMode && open && contentTemplateId ? contentTemplateId : skipToken,
  );

  // Initialize field state whenever the dialog is (re)opened.
  useEffect(() => {
    if (open) {
      const initialLabel = data.label ?? data.slotTitle ?? '';
      setLabel(initialLabel);
      setFieldName(data.alias ?? deriveSlotFieldName(initialLabel));
      setFieldNameEdited(data.mode === 'editLabel');
      setTab('create');
      setExistingField('');
      setSubmitError('');
    }
  }, [open, data.mode, data.alias, data.label, data.slotTitle]);

  // Slot field names already referenced by this template (the working set).
  const referencedFields = Object.keys(exposedSlots ?? {});
  // "Use existing" offers the bundle's fields not already referenced here.
  const availableCandidates = (candidates ?? []).filter(
    (candidate) => !referencedFields.includes(candidate.fieldName),
  );

  const trimmedLabel = label.trim();
  const labelError = !trimmedLabel ? 'This field is required.' : '';
  const fieldNameError =
    isEditMode || tab === 'existing'
      ? ''
      : validateSlotFieldName(fieldName, referencedFields);

  const handleLabelChange = (value: string) => {
    setLabel(value);
    // Auto-derive the machine name from the label until it is edited by hand.
    if (!isEditMode && tab === 'create' && !fieldNameEdited) {
      setFieldName(deriveSlotFieldName(value));
    }
  };

  const handleFieldNameChange = (value: string) => {
    setFieldNameEdited(true);
    setFieldName(value);
  };

  const close = () => {
    dispatch(setDialogWithDataClosed('exposeSlot'));
  };

  const canSubmit = isEditMode
    ? !!trimmedLabel
    : tab === 'existing'
      ? !!existingField
      : !!trimmedLabel && !!fieldName.trim() && !fieldNameError && !isCreating;

  const handleConfirm = async () => {
    if (!canSubmit) {
      return;
    }
    setSubmitError('');

    if (isEditMode) {
      dispatch(
        updateExposedSlotLabel({ alias: data.alias!, label: trimmedLabel }),
      );
      close();
      return;
    }

    if (tab === 'existing') {
      const chosen = availableCandidates.find(
        (candidate) => candidate.fieldName === existingField,
      );
      if (!chosen) {
        return;
      }
      dispatch(
        addExposedSlot({
          alias: chosen.fieldName,
          label: chosen.label,
          slotName: data.slotName,
          componentUuid: data.componentUuid,
        }),
      );
      close();
      return;
    }

    // Create new slot: create the backing field, then reference it.
    if (!contentTemplateId) {
      setSubmitError('Could not determine the content template.');
      return;
    }
    try {
      const created = await createSlotField({
        contentTemplateId,
        fieldName,
        label: trimmedLabel,
      }).unwrap();
      dispatch(
        addExposedSlot({
          alias: created.fieldName,
          label: trimmedLabel,
          slotName: data.slotName,
          componentUuid: data.componentUuid,
        }),
      );
      close();
    } catch {
      setSubmitError(
        'Could not create the slot field. The machine name may already be in use.',
      );
    }
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
          : 'Exposes a slot so a content editor can add items to this area of their content.'
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
          {!isEditMode && (
            <SegmentedControl.Root
              value={tab}
              onValueChange={(value) => setTab(value as Tab)}
              size="1"
              mb="1"
            >
              <SegmentedControl.Item value="create">
                Create new slot
              </SegmentedControl.Item>
              <SegmentedControl.Item value="existing">
                Use existing slot
              </SegmentedControl.Item>
            </SegmentedControl.Root>
          )}

          {!isEditMode && tab === 'existing' ? (
            availableCandidates.length === 0 ? (
              <Text size="1" color="gray">
                This content type has no reusable slot fields. Create a new slot
                instead.
              </Text>
            ) : (
              <>
                <DialogFieldLabel htmlFor="exposedSlotExisting">
                  Slot field
                </DialogFieldLabel>
                <Select.Root
                  value={existingField}
                  onValueChange={setExistingField}
                >
                  <Select.Trigger
                    id="exposedSlotExisting"
                    placeholder="Select a slot field"
                  />
                  <Select.Content>
                    {availableCandidates.map((candidate) => (
                      <Select.Item
                        key={candidate.fieldName}
                        value={candidate.fieldName}
                      >
                        {candidate.label} ({candidate.fieldName})
                      </Select.Item>
                    ))}
                  </Select.Content>
                </Select.Root>
                <Text size="1" color="gray">
                  Reusing a field restores any content entities already stored
                  in it.
                </Text>
              </>
            )
          ) : (
            <>
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

              <DialogFieldLabel htmlFor="exposedSlotFieldName">
                Machine name
              </DialogFieldLabel>
              <TextField.Root
                autoComplete="off"
                id="exposedSlotFieldName"
                value={fieldName}
                onChange={(e) => handleFieldNameChange(e.target.value)}
                placeholder="canvas_slot_example"
                size="1"
                disabled={isEditMode}
                readOnly={isEditMode}
              />
              {isEditMode ? (
                <Text size="1" color="gray">
                  The machine name cannot be changed after creation.
                </Text>
              ) : (
                <>
                  {fieldName.trim() && fieldNameError && (
                    <Text size="1" color="red" weight="medium">
                      {fieldNameError}
                    </Text>
                  )}
                  <Text size="1" color="gray">
                    This adds a new field to the content type. Its content is
                    edited on the canvas, not on the content form.
                  </Text>
                </>
              )}
            </>
          )}

          {submitError && (
            <Text size="1" color="red" weight="medium">
              {submitError}
            </Text>
          )}
        </Flex>
      </form>
    </Dialog>
  );
};

export default ExposeSlotDialog;
