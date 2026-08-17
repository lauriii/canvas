import { useState } from 'react';
import parse from 'html-react-parser';
import snakeCase from 'lodash/snakeCase';
import { useNavigate } from 'react-router-dom';
import { Flex, Text, TextArea, TextField } from '@radix-ui/themes';

import Dialog, { DialogFieldLabel } from '@/components/Dialog';
import { extractErrorMessageFromApiResponse } from '@/features/error-handling/error-handling';
import {
  useCreateSegmentMutation,
  useUpdateSegmentMutation,
} from '@/services/personalization';

import type { Segment } from '@/types/Personalization';

interface CreateSegmentDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  segments: Record<string, Segment>;
}

const CreateSegmentDialog = ({
  open,
  onOpenChange,
  segments,
}: CreateSegmentDialogProps) => {
  const [label, setLabel] = useState('');
  const [description, setDescription] = useState('');
  const [createSegment, { isLoading, isError, error, reset }] =
    useCreateSegmentMutation();
  const [updateSegment] = useUpdateSegmentMutation();
  const navigate = useNavigate();

  const trimmedLabel = label.trim();
  const id = snakeCase(trimmedLabel);
  const idExists = Object.values(segments).some((segment) => segment.id === id);
  const validationError =
    trimmedLabel && idExists
      ? 'A segment with this name already exists. Choose a different name.'
      : '';

  const handleOpenChange = (isOpen: boolean) => {
    if (!isOpen) {
      setLabel('');
      setDescription('');
      reset();
    }
    onOpenChange(isOpen);
  };

  const handleCreate = async () => {
    if (!trimmedLabel || validationError) {
      return;
    }
    // Push existing segments down so the new segment shows at the top.
    // The default segment keeps its fixed high weight.
    const existingSegments = Object.values(segments).filter(
      (segment) => segment.id !== 'default',
    );
    await Promise.all(
      existingSegments.map((segment) =>
        updateSegment({
          id: segment.id,
          changes: { weight: segment.weight + 1 },
        }),
      ),
    );
    const result = await createSegment({
      id,
      label: trimmedLabel,
      description: description.trim(),
      // The server forces new segments to start disabled.
      status: false,
      weight: 0,
    });
    if (result && !('error' in result)) {
      handleOpenChange(false);
      navigate(`/segments/${id}`);
    }
  };

  return (
    <Dialog
      open={open}
      onOpenChange={handleOpenChange}
      title="Create segment"
      description="New segments start disabled. Enable the segment once its rules are ready."
      error={
        isError
          ? {
              title: 'Failed to create segment',
              message: parse(extractErrorMessageFromApiResponse(error)),
              resetButtonText: 'Try again',
              onReset: handleCreate,
            }
          : undefined
      }
      footer={{
        cancelText: 'Cancel',
        confirmText: 'Create',
        onConfirm: handleCreate,
        isConfirmDisabled: !trimmedLabel || !!validationError,
        isConfirmLoading: isLoading,
      }}
    >
      <form
        onSubmit={(e) => {
          e.preventDefault();
          handleCreate();
        }}
      >
        <Flex direction="column" gap="2">
          <DialogFieldLabel htmlFor="segmentLabel">Name</DialogFieldLabel>
          <TextField.Root
            autoComplete="off"
            id="segmentLabel"
            value={label}
            onChange={(e) => setLabel(e.target.value)}
            placeholder="Enter a name"
            size="1"
          />
          {trimmedLabel && !validationError && (
            <Text size="1" color="gray">
              Machine name: {id}
            </Text>
          )}
          {validationError && (
            <Text size="1" color="red" weight="medium">
              {validationError}
            </Text>
          )}
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

export default CreateSegmentDialog;
