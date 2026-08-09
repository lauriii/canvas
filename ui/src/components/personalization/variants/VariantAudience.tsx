import { Fragment } from 'react';
import { Text } from '@radix-ui/themes';

import { useGetSegmentsQuery } from '@/services/personalization';

interface VariantAudienceProps {
  isDefault: boolean;
  // Segment IDs the variant's case targets, in stored order.
  segmentIds: string[];
}

/**
 * One-line audience description of a variant: the labels of its segments,
 * falling back to the raw segment ID when the segment no longer exists.
 */
const VariantAudience = ({ isDefault, segmentIds }: VariantAudienceProps) => {
  const { data: segments } = useGetSegmentsQuery(undefined, {
    skip: isDefault,
  });

  if (isDefault) {
    return (
      <Text size="1" color="gray">
        Everyone (fallback)
      </Text>
    );
  }
  if (segmentIds.length === 0) {
    return (
      <Text size="1" color="gray">
        No audience selected
      </Text>
    );
  }
  return (
    <Text size="1" color="gray">
      Audience:{' '}
      {segmentIds.map((segmentId, index) => {
        const segment = segments?.[segmentId];
        return (
          <Fragment key={segmentId}>
            {index > 0 && ', '}
            {segment ? (
              segment.label
            ) : (
              <Text
                size="1"
                color="amber"
                weight="medium"
                title={`Missing segment: ${segmentId}`}
              >
                {segmentId}
              </Text>
            )}
          </Fragment>
        );
      })}
    </Text>
  );
};

export default VariantAudience;
