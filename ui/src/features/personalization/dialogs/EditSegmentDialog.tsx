import { useEffect, useState } from 'react';
import parse from 'html-react-parser';
import { Flex, TextArea, TextField } from '@radix-ui/themes';

import Dialog, { DialogFieldLabel } from '@/components/Dialog';
import { extractErrorMessageFromApiResponse } from '@/features/error-handling/error-handling';
import { useUpdateSegmentMutation } from '@/services/personalization';

import type { Segment } from '@/types/Personalization';

interface EditSegmentDialogProps {
  // The segment being edited, or null when the dialog is closed.
  segment: Segment | null;
  onClose: () => void;
}

const EditSegmentDialog = ({ segment, onClose }: EditSegmentDialogProps) => {
  const [label, setLabel] = useState('');
  const [description, setDescription] = useState('');
  const [updateSegment, { isLoading, isError, error, reset }] =
    useUpdateSegmentMutation();

  useEffect(() => {
    if (segment) {
      setLabel(segment.label);
      setDescription(segment.description ?? '');
    }
  }, [segment]);

  const handleOpenChange = (isOpen: boolean) => {
    if (!isOpen) {
      reset();
      onClose();
    }
  };

  const handleSave = async () => {
    if (!segment || !label.trim()) {
      return;
    }
    const result = await updateSegment({
      id: segment.id,
      changes: { label: label.trim(), description: description.trim() },
    });
    if (result && !('error' in result)) {
      handleOpenChange(false);
    }
  };

  return (
    <Dialog
      open={segment !== null}
      onOpenChange={handleOpenChange}
      title="Edit segment details"
      error={
        isError
          ? {
              title: 'Failed to update segment',
              message: parse(extractErrorMessageFromApiResponse(error)),
              resetButtonText: 'Try again',
              onReset: handleSave,
            }
          : undefined
      }
      footer={{
        cancelText: 'Cancel',
        confirmText: 'Save',
        onConfirm: handleSave,
        isConfirmDisabled: !label.trim(),
        isConfirmLoading: isLoading,
      }}
    >
      <form
        onSubmit={(e) => {
          e.preventDefault();
          handleSave();
        }}
      >
        <Flex direction="column" gap="2">
          <DialogFieldLabel htmlFor="segmentLabel">Name</DialogFieldLabel>
          <TextField.Root
            autoComplete="off"
            id="segmentLabel"
            value={label}
            onChange={(e) => setLabel(e.target.value)}
            size="1"
          />
          <DialogFieldLabel htmlFor="segmentDescription">
            Description (optional)
          </DialogFieldLabel>
          <TextArea
            id="segmentDescription"
            value={description}
            onChange={(e) => setDescription(e.target.value)}
            placeholder="Describe who this segment targets"
            size="1"
          />
        </Flex>
      </form>
    </Dialog>
  );
};

export default EditSegmentDialog;
