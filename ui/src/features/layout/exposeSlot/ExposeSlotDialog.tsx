import { useEffect, useState } from 'react';
import { useParams } from 'react-router';
import { Flex, Select, Text, TextField } from '@radix-ui/themes';
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

// The "Add new slot" option in the slot-field Select (any real field machine
// name is a valid choice; this sentinel is the only reserved one).
const NEW_SLOT = '__new__';

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

  const [label, setLabel] = useState('');
  const [fieldName, setFieldName] = useState('');
  // Once the machine name is edited by hand it stops tracking the label.
  const [fieldNameEdited, setFieldNameEdited] = useState(false);
  // The chosen slot field, or NEW_SLOT to create a new one.
  const [slotChoice, setSlotChoice] = useState<string>(NEW_SLOT);
  const [submitError, setSubmitError] = useState('');

  const [createSlotField, { isLoading: isCreating }] =
    useCreateSlotFieldMutation();
  // The entity type's reusable component_tree fields (this bundle's plus slot
  // fields on other bundles, which share storage), for the "use existing" path.
  const { data: candidates, isFetching: candidatesLoading } =
    useGetSlotFieldCandidatesQuery(
      !isEditMode && open && contentTemplateId ? contentTemplateId : skipToken,
    );

  // Initialize field state whenever the dialog is (re)opened. slotChoice starts
  // empty (no real value) so the default-selection effect below can pick an
  // existing slot once candidates load, rather than defaulting to "add new".
  useEffect(() => {
    if (open) {
      const initialLabel = data.label ?? data.slotTitle ?? '';
      setLabel(initialLabel);
      setFieldName(data.alias ?? deriveSlotFieldName(initialLabel));
      setFieldNameEdited(data.mode === 'editLabel');
      setSlotChoice('');
      setSubmitError('');
    }
  }, [open, data.mode, data.alias, data.label, data.slotTitle]);

  // Slot field names already referenced by this template (the working set).
  const referencedFields = Object.keys(exposedSlots ?? {});
  // Fields offered for reuse: those not already referenced here. Server-sorted
  // content-first, so the first is the most useful existing slot.
  const availableCandidates = (candidates ?? []).filter(
    (candidate) => !referencedFields.includes(candidate.fieldName),
  );

  // Default to reusing an existing slot (the content-first candidate), not
  // creating a new one; fall back to "add new" only when none exist. Runs once
  // per open, after candidates load (slotChoice is '' until then).
  useEffect(() => {
    if (open && !isEditMode && slotChoice === '' && !candidatesLoading) {
      setSlotChoice(availableCandidates[0]?.fieldName ?? NEW_SLOT);
    }
  }, [open, isEditMode, slotChoice, candidatesLoading, availableCandidates]);
  const isCreatingNew = !isEditMode && slotChoice === NEW_SLOT;
  const chosenCandidate = availableCandidates.find(
    (candidate) => candidate.fieldName === slotChoice,
  );

  const trimmedLabel = label.trim();
  const labelError = !trimmedLabel ? 'This field is required.' : '';
  const fieldNameError = isCreatingNew
    ? validateSlotFieldName(fieldName, referencedFields)
    : '';

  const handleLabelChange = (value: string) => {
    setLabel(value);
    // Auto-derive the machine name from the label until it is edited by hand.
    if (isCreatingNew && !fieldNameEdited) {
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
    : isCreatingNew
      ? !!trimmedLabel && !!fieldName.trim() && !fieldNameError && !isCreating
      : !!chosenCandidate && !isCreating;

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

    if (!isCreatingNew) {
      if (!chosenCandidate) {
        return;
      }
      // A slot field defined only on another bundle shares its storage but has
      // no field config here yet; attach it to this bundle before referencing.
      if (!chosenCandidate.onThisBundle) {
        if (!contentTemplateId) {
          setSubmitError('Could not determine the content template.');
          return;
        }
        try {
          await createSlotField({
            contentTemplateId,
            fieldName: chosenCandidate.fieldName,
            label: chosenCandidate.label,
          }).unwrap();
        } catch {
          setSubmitError('Could not add the slot field to this content type.');
          return;
        }
      }
      dispatch(
        addExposedSlot({
          alias: chosenCandidate.fieldName,
          label: chosenCandidate.label,
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
      // Wider than the default so slot-field options ("Label (machine_name) —
      // N with content") fit without truncating.
      width={isEditMode ? undefined : '380px'}
      onOpenChange={(isOpen) => {
        if (!isOpen) {
          close();
        }
      }}
      title={isEditMode ? 'Edit slot label' : 'Expose slot'}
      description={
        isEditMode
          ? 'Rename this exposed slot. The machine name cannot be changed.'
          : 'Exposes this slot so content editors can override its content, or add their own, on each item.'
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
            <>
              <DialogFieldLabel htmlFor="exposedSlotChoice">
                Slot field
              </DialogFieldLabel>
              <Select.Root
                size="1"
                value={slotChoice}
                onValueChange={setSlotChoice}
              >
                <Select.Trigger
                  id="exposedSlotChoice"
                  placeholder="Select a slot field"
                />
                {/* Popper positioning + a capped height keep the list scrollable
                    and bounded no matter how many slot fields exist. */}
                <Select.Content
                  position="popper"
                  style={{ maxHeight: '320px' }}
                >
                  {availableCandidates.map((candidate) => (
                    <Select.Item
                      key={candidate.fieldName}
                      value={candidate.fieldName}
                    >
                      {candidate.label} ({candidate.fieldName})
                      {candidate.contentCount > 0 &&
                        ` — ${candidate.contentCount} with content`}
                    </Select.Item>
                  ))}
                  {availableCandidates.length > 0 && <Select.Separator />}
                  <Select.Item value={NEW_SLOT}>Add new slot…</Select.Item>
                </Select.Content>
              </Select.Root>
              {!isCreatingNew && chosenCandidate && (
                <Text size="1" color="gray">
                  {chosenCandidate.contentCount > 0
                    ? `Reusing this field restores content already stored in it by ${chosenCandidate.contentCount} ${chosenCandidate.contentCount === 1 ? 'entity' : 'entities'}.`
                    : chosenCandidate.onThisBundle
                      ? 'Reusing a field restores any content entities already stored in it.'
                      : 'This slot field is defined on another content type. Exposing it here adds it to this content type too, sharing the same storage.'}
                </Text>
              )}
            </>
          )}

          {(isEditMode || isCreatingNew) && (
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
