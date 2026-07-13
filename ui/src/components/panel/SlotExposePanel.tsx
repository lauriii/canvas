import { BoxModelIcon } from '@radix-ui/react-icons';
import { Box, Button, Callout, Flex, Text } from '@radix-ui/themes';

import { useAppDispatch } from '@/app/hooks';
import { getSlotHostComponentUuid } from '@/features/layout/exposedSlots';
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
 * Template editor: the settings panel shown when a not-yet-exposed slot is
 * selected. It names the slot's current state (content owned by the template,
 * identical on every item) and what exposing changes (editors can override that
 * default or add their own content per item), then offers the Expose slot
 * action. Mirrors the equivalent SlotContextMenuContent menu item, making the
 * affordance discoverable through selection rather than only hover / right-click.
 *
 * @see LockedSlotPanel (the per-content sibling, for an exposed slot's default)
 */
const SlotExposePanel: React.FC<SlotExposePanelProps> = ({
  slot,
  slotTitle,
}) => {
  const dispatch = useAppDispatch();

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
    <Box my="2" data-testid="canvas-slot-expose-panel">
      <Text as="p" size="2" weight="medium" mb="2">
        {slotTitle}
      </Text>
      <Callout.Root size="1" color="gray" variant="surface">
        <Callout.Icon>
          <BoxModelIcon />
        </Callout.Icon>
        <Callout.Text>
          This slot is the same on every item; its content comes from the
          template. Expose it to let editors override that content, or add their
          own, on each item.
        </Callout.Text>
      </Callout.Root>
      <Flex mt="3" justify="start">
        <Button
          size="1"
          onClick={openExposeDialog}
          className="canvas-button"
          data-testid="canvas-slot-expose-button"
        >
          Expose slot
        </Button>
      </Flex>
    </Box>
  );
};

export default SlotExposePanel;
