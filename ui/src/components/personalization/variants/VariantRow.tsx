import { useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { DotsHorizontalIcon, DragHandleDots2Icon } from '@radix-ui/react-icons';
import {
  Badge,
  Box,
  DropdownMenu,
  Flex,
  IconButton,
  RadioGroup,
  Text,
} from '@radix-ui/themes';

import VariantAudience from '@/components/personalization/variants/VariantAudience';
import { humanizeVariantId } from '@/features/layout/personalizationUtils';

import type React from 'react';

interface VariantRowProps {
  variantId: string;
  // Segment IDs of the variant's case, used to describe the audience.
  segmentIds: string[];
  isDefault: boolean;
  isDisabled: boolean;
  onPromote: () => void;
  onToggleDisabled: () => void;
  onDelete: () => void;
}

const VariantRow = ({
  variantId,
  segmentIds,
  isDefault,
  isDisabled,
  onPromote,
  onToggleDisabled,
  onDelete,
}: VariantRowProps) => {
  const {
    attributes,
    listeners,
    setNodeRef,
    transform,
    transition,
    isDragging,
  } = useSortable({ id: variantId, disabled: isDefault });
  const label = humanizeVariantId(variantId);

  const style: React.CSSProperties = {
    transform: CSS.Transform.toString(transform),
    transition,
    // Disabled variants are dimmed in the list only; the preview is
    // unaffected.
    opacity: isDragging ? 0.5 : isDisabled ? 0.6 : 1,
  };

  return (
    <Flex
      ref={setNodeRef}
      align="center"
      gap="2"
      py="1"
      style={style}
      data-testid={`variant-row-${variantId}`}
    >
      <Flex width="16px" align="center" justify="center">
        {!isDefault && (
          <div
            {...attributes}
            {...listeners}
            aria-label={`Reorder ${label} variant`}
            style={{
              cursor: isDragging ? 'grabbing' : 'grab',
              display: 'flex',
            }}
          >
            <DragHandleDots2Icon />
          </div>
        )}
      </Flex>
      <Flex direction="column" style={{ flexGrow: 1, minWidth: 0 }}>
        <Text as="label" size="2">
          <Flex gap="2" align="center">
            <RadioGroup.Item value={variantId} />
            {/* The machine name stays available as hover text only. */}
            <span title={variantId}>{label}</span>
            {isDisabled && <Badge color="gray">Disabled</Badge>}
          </Flex>
        </Text>
        <Box pl="5">
          <VariantAudience isDefault={isDefault} segmentIds={segmentIds} />
        </Box>
      </Flex>
      <DropdownMenu.Root>
        <DropdownMenu.Trigger>
          <IconButton
            variant="ghost"
            color="gray"
            aria-label={`Open ${label} variant menu`}
          >
            <DotsHorizontalIcon />
          </IconButton>
        </DropdownMenu.Trigger>
        <DropdownMenu.Content align="end">
          <DropdownMenu.Item disabled={isDefault} onSelect={onPromote}>
            Promote to default
          </DropdownMenu.Item>
          <DropdownMenu.Item disabled={isDefault} onSelect={onToggleDisabled}>
            {isDisabled ? 'Enable variant' : 'Disable variant'}
          </DropdownMenu.Item>
          <DropdownMenu.Separator />
          <DropdownMenu.Item
            disabled={isDefault}
            color="red"
            onSelect={onDelete}
          >
            Delete variant
          </DropdownMenu.Item>
        </DropdownMenu.Content>
      </DropdownMenu.Root>
    </Flex>
  );
};

export default VariantRow;
