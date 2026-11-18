import { BarChartIcon } from '@radix-ui/react-icons';
import { Box, Button, Callout, Flex, Text } from '@radix-ui/themes';

import { useAppDispatch } from '@/app/hooks';
import { setDialogWithDataOpen } from '@/features/ui/dialogSlice';
import { useGetSlotFieldUsageQuery } from '@/services/slotFields';

import type { RemoveExposedSlotDialogData } from '@/features/ui/dialogSlice';

interface SlotUsagePanelProps {
  /** Content template config id, e.g. `node.article.full`. */
  contentTemplateId: string;
  /** The slot's backing field machine name (its stable identity). */
  fieldName: string;
  /** The exposed slot's label, shown in the Disable editing dialog. */
  label: string;
}

/**
 * Template editor: usage statistics for a selected exposed slot. Reports how
 * many of the bundle's entities override the slot, and offers the same
 * Disable editing action as SlotContextMenuContent.
 *
 * @see \Drupal\canvas\Controller\ApiContentTemplateSlotFieldController::usage
 */
const SlotUsagePanel: React.FC<SlotUsagePanelProps> = ({
  contentTemplateId,
  fieldName,
  label,
}) => {
  const dispatch = useAppDispatch();
  const { data, isFetching, isError } = useGetSlotFieldUsageQuery({
    contentTemplateId,
    fieldName,
  });

  const openRemoveDialog = () => {
    const dialogData: RemoveExposedSlotDialogData = { fieldName, label };
    dispatch(
      setDialogWithDataOpen({
        operation: 'removeExposedSlotConfirm',
        data: dialogData,
      }),
    );
  };

  return (
    <Box my="5" data-testid="canvas-slot-usage-panel">
      <Text as="p" size="2" weight="medium" mb="2">
        Usage
      </Text>
      {isFetching ? (
        <Text size="1" color="gray">
          Checking usage…
        </Text>
      ) : isError || !data ? (
        <Text size="1" color="gray">
          Slot usage is unavailable.
        </Text>
      ) : (
        <Callout.Root size="1" color="gray" variant="surface">
          <Callout.Icon>
            <BarChartIcon />
          </Callout.Icon>
          {/* Reset leaked Drupal admin p margins. */}
          <Callout.Text m="0">
            {data.overridden === 0 ? (
              <>No entities override this slot yet.</>
            ) : (
              <>
                <strong>{data.overridden}</strong>{' '}
                {data.overridden === 1
                  ? 'entity overrides'
                  : 'entities override'}{' '}
                this slot.
              </>
            )}
          </Callout.Text>
        </Callout.Root>
      )}
      <Flex mt="3" justify="start">
        <Button
          size="1"
          onClick={openRemoveDialog}
          className="canvas-button"
          data-testid="canvas-slot-disable-editing-button"
        >
          Disable editing
        </Button>
      </Flex>
    </Box>
  );
};

export default SlotUsagePanel;
