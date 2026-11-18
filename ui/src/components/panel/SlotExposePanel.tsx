import { useParams } from 'react-router';
import { BoxModelIcon } from '@radix-ui/react-icons';
import { Box, Button, Callout, Flex, Text } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  EXPOSED_SLOTS_VIEW_MODE,
  findSlotNestingConflict,
  getSlotHostComponentUuid,
} from '@/features/layout/exposedSlots';
import {
  selectExposedSlots,
  selectLayout,
} from '@/features/layout/layoutModelSlice';
import { setDialogWithDataOpen } from '@/features/ui/dialogSlice';

import type { SlotNode } from '@/features/layout/layoutModelSlice';
import type { ExposeSlotDialogData } from '@/features/ui/dialogSlice';

interface SlotExposePanelProps {
  /** The selected, not-yet-exposed slot. */
  slot: SlotNode;
  /** Human-readable slot title: the heading and the dialog's default label. */
  slotTitle: string;
}

/**
 * Template editor: the settings panel of a selected slot that is not exposed.
 * Offers the same Enable editing action as SlotContextMenuContent.
 *
 * @see InheritedSlotPanel (the per-content sibling, for an exposed slot's
 *   default)
 */
const SlotExposePanel: React.FC<SlotExposePanelProps> = ({
  slot,
  slotTitle,
}) => {
  const dispatch = useAppDispatch();
  const exposedSlots = useAppSelector(selectExposedSlots);
  const layout = useAppSelector(selectLayout);
  const { viewMode } = useParams();
  // Only one view mode can expose slots; other templates can't enable editing.
  const isViewModeBlocked = viewMode !== EXPOSED_SLOTS_VIEW_MODE;

  // Exposed slots must not nest; block the invalid exposure here exactly like
  // the slot context menu does, instead of letting the save fail on the
  // ValidExposedSlot constraint.
  const nestingConflict = findSlotNestingConflict(
    exposedSlots,
    layout,
    slot,
    getSlotHostComponentUuid(slot),
  );

  const openExposeDialog = () => {
    const data: ExposeSlotDialogData = {
      mode: 'expose',
      componentUuid: getSlotHostComponentUuid(slot),
      slotName: slot.name,
      slotTitle,
    };
    dispatch(setDialogWithDataOpen({ operation: 'exposeSlot', data }));
  };

  return (
    <Box my="5" data-testid="canvas-slot-expose-panel">
      <Text as="p" size="2" weight="medium" mb="2">
        {slotTitle}
      </Text>
      <Callout.Root size="1" color="gray" variant="surface">
        <Callout.Icon>
          <BoxModelIcon />
        </Callout.Icon>
        {/* Reset leaked Drupal admin p margins. */}
        <Callout.Text m="0">
          Enable editing on this slot to let editors customize the content on
          each item.
        </Callout.Text>
      </Callout.Root>
      <Flex mt="3" justify="start" direction="column" gap="2">
        <Box>
          <Button
            size="1"
            onClick={openExposeDialog}
            className="canvas-button"
            data-testid="canvas-slot-expose-button"
            disabled={isViewModeBlocked || !!nestingConflict}
          >
            Enable editing
          </Button>
        </Box>
        {isViewModeBlocked && (
          <Text
            size="1"
            color="gray"
            data-testid="canvas-slot-expose-view-mode-blocked"
          >
            Editing can only be enabled on slots in the Full content view mode
            template.
          </Text>
        )}
        {!isViewModeBlocked && nestingConflict && (
          <Text size="1" color="gray" data-testid="canvas-slot-expose-blocked">
            {nestingConflict.direction === 'inside'
              ? `Editing cannot be enabled on this slot because it is inside the editable slot "${nestingConflict.definition.label}".`
              : `Editing cannot be enabled on this slot because it contains the editable slot "${nestingConflict.definition.label}".`}
          </Text>
        )}
      </Flex>
    </Box>
  );
};

export default SlotExposePanel;
