import { BarChartIcon } from '@radix-ui/react-icons';
import { Box, Callout, Text } from '@radix-ui/themes';

import { useGetSlotFieldUsageQuery } from '@/services/slotFields';

interface SlotUsagePanelProps {
  /** Content template config id, e.g. `node.article.full`. */
  contentTemplateId: string;
  /** The slot's backing field machine name (its stable identity). */
  fieldName: string;
}

/**
 * Template editor: usage statistics for a selected exposed slot. Reports how
 * many of the bundle's entities have overridden the slot versus inherit its
 * default. Counts are computed at read time from stored entity data.
 *
 * @see \Drupal\canvas\Controller\ApiContentTemplateSlotFieldController::usage
 */
const SlotUsagePanel: React.FC<SlotUsagePanelProps> = ({
  contentTemplateId,
  fieldName,
}) => {
  const { data, isFetching, isError } = useGetSlotFieldUsageQuery({
    contentTemplateId,
    fieldName,
  });

  return (
    <Box my="2" data-testid="canvas-slot-usage-panel">
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
          <Callout.Text>
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
    </Box>
  );
};

export default SlotUsagePanel;
