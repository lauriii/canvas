import { useMemo, useState } from 'react';
import snakeCase from 'lodash/snakeCase';
import { CheckboxGroup, Flex, Select, Text, TextField } from '@radix-ui/themes';

import { useAppDispatch } from '@/app/hooks';
import Dialog, { DialogFieldLabel } from '@/components/Dialog';
import { addVariant } from '@/features/layout/layoutModelSlice';
import { DEFAULT_VARIANT_ID } from '@/features/layout/personalizationUtils';
import { setPreviewedVariant } from '@/features/ui/uiSlice';
import { useGetSegmentsQuery } from '@/services/personalization';

interface CreateVariantDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  switchUuid: string;
  // Existing variant IDs of the switch, in priority order.
  variants: string[];
}

const CreateVariantDialog = ({
  open,
  onOpenChange,
  switchUuid,
  variants,
}: CreateVariantDialogProps) => {
  const dispatch = useAppDispatch();
  const [label, setLabel] = useState('');
  const [selectedSegments, setSelectedSegments] = useState<string[]>([]);
  const [sourceVariantId, setSourceVariantId] = useState(DEFAULT_VARIANT_ID);
  const { data: segments } = useGetSegmentsQuery();

  const segmentList = useMemo(
    () => Object.values(segments ?? {}).sort((a, b) => a.weight - b.weight),
    [segments],
  );

  const trimmedLabel = label.trim();
  const variantId = snakeCase(trimmedLabel);
  const idExists = variants.includes(variantId);
  const validationError =
    trimmedLabel && idExists
      ? 'A variant with this name already exists. Choose a different name.'
      : '';
  const isConfirmDisabled =
    !trimmedLabel || !!validationError || selectedSegments.length === 0;

  const handleOpenChange = (isOpen: boolean) => {
    if (!isOpen) {
      setLabel('');
      setSelectedSegments([]);
      setSourceVariantId(DEFAULT_VARIANT_ID);
    }
    onOpenChange(isOpen);
  };

  const handleCreate = () => {
    if (isConfirmDisabled) {
      return;
    }
    dispatch(
      addVariant({
        switchUuid,
        variantId,
        segments: selectedSegments,
        sourceVariantId,
      }),
    );
    // Preview the new variant right away.
    dispatch(setPreviewedVariant({ switchUuid, variantId }));
    handleOpenChange(false);
  };

  return (
    <Dialog
      open={open}
      onOpenChange={handleOpenChange}
      title="New variant"
      description="A variant is shown to the first matching audience, in priority order."
      footer={{
        cancelText: 'Cancel',
        confirmText: 'Create',
        onConfirm: handleCreate,
        isConfirmDisabled,
      }}
    >
      <form
        onSubmit={(e) => {
          e.preventDefault();
          handleCreate();
        }}
      >
        <Flex direction="column" gap="2">
          <DialogFieldLabel htmlFor="variantLabel">Name</DialogFieldLabel>
          <TextField.Root
            autoComplete="off"
            id="variantLabel"
            value={label}
            onChange={(e) => setLabel(e.target.value)}
            placeholder="Enter a name"
            size="1"
          />
          {trimmedLabel && !validationError && (
            <Text size="1" color="gray">
              Machine name: {variantId}
            </Text>
          )}
          {validationError && (
            <Text size="1" color="red" weight="medium">
              {validationError}
            </Text>
          )}
          <DialogFieldLabel htmlFor="variantAudience">
            Audience
          </DialogFieldLabel>
          <CheckboxGroup.Root
            id="variantAudience"
            size="1"
            value={selectedSegments}
            onValueChange={setSelectedSegments}
            aria-label="Audience"
          >
            {segmentList.map((segment) => (
              <CheckboxGroup.Item key={segment.id} value={segment.id}>
                {segment.label}
              </CheckboxGroup.Item>
            ))}
          </CheckboxGroup.Root>
          <DialogFieldLabel htmlFor="variantSource">
            Start from
          </DialogFieldLabel>
          <Select.Root
            size="1"
            value={sourceVariantId}
            onValueChange={setSourceVariantId}
          >
            <Select.Trigger id="variantSource" />
            <Select.Content>
              {variants.map((id) => (
                <Select.Item key={id} value={id}>
                  {id}
                </Select.Item>
              ))}
            </Select.Content>
          </Select.Root>
        </Flex>
      </form>
    </Dialog>
  );
};

export default CreateVariantDialog;
