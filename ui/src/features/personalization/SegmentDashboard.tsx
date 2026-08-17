import { useCallback, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { PlusIcon } from '@radix-ui/react-icons';
import { Button, Card, Flex, Heading, Skeleton, Text } from '@radix-ui/themes';

import SegmentList from '@/components/personalization/SegmentList';
import CreateSegmentDialog from '@/features/personalization/dialogs/CreateSegmentDialog';
import DeleteSegmentDialog from '@/features/personalization/dialogs/DeleteSegmentDialog';
import EditSegmentDialog from '@/features/personalization/dialogs/EditSegmentDialog';
import {
  useGetSegmentsQuery,
  useUpdateSegmentMutation,
} from '@/services/personalization';

import type { Segment } from '@/types/Personalization';

export default function SegmentDashboard() {
  const navigate = useNavigate();
  const { data: segments = {}, isLoading, error } = useGetSegmentsQuery();
  const [updateSegment] = useUpdateSegmentMutation();
  const [isCreateDialogOpen, setIsCreateDialogOpen] = useState(false);
  const [editedSegment, setEditedSegment] = useState<Segment | null>(null);
  const [deletedSegment, setDeletedSegment] = useState<Segment | null>(null);

  const handleEditSegment = useCallback(
    (id: string) => {
      navigate(`/segments/${id}`);
    },
    [navigate],
  );

  const handleEditSegmentDetails = useCallback(
    (id: string) => {
      setEditedSegment(segments[id] ?? null);
    },
    [segments],
  );

  const handleDeleteSegment = useCallback(
    (id: string) => {
      setDeletedSegment(segments[id] ?? null);
    },
    [segments],
  );

  const handleToggleSegment = useCallback(
    async (id: string, enabled: boolean) => {
      try {
        await updateSegment({
          id,
          changes: { status: enabled },
        });
      } catch (error) {
        console.error('Failed to update segment status:', error);
      }
    },
    [updateSegment],
  );

  const handleReorderSegments = useCallback(
    async (reorderedSegments: Segment[]) => {
      try {
        // Update weights for all segments based on their new order
        // Skip the default segment as it has a fixed high weight
        const updatePromises = reorderedSegments
          .filter((segment) => segment.id !== 'default')
          .map((segment, index) =>
            updateSegment({
              id: segment.id,
              changes: { weight: index },
            }),
          );

        await Promise.all(updatePromises);
      } catch (error) {
        console.error('Failed to update segment weights:', error);
      }
    },
    [updateSegment],
  );

  if (isLoading) {
    // Mirror the loaded layout: heading row with its action button, then
    // the segment table card.
    return (
      <Flex direction="column" gap="6" data-testid="segment-dashboard-loading">
        <Flex justify="between" align="start" gap="3">
          <Flex direction="column" gap="2">
            <Skeleton height="1.75rem" width="10rem" />
            <Skeleton height="1.2rem" width="24rem" />
          </Flex>
          <Skeleton height="2rem" width="9rem" mt="1" />
        </Flex>
        <Card>
          <Flex direction="column" gap="3" p="2">
            <Skeleton height="1.2rem" width="100%" />
            <Skeleton height="1.2rem" width="100%" />
            <Skeleton height="1.2rem" width="100%" />
          </Flex>
        </Card>
      </Flex>
    );
  }

  if (error) {
    return <div>Error loading segments: {JSON.stringify(error)}</div>;
  }

  return (
    <Flex direction="column" gap="6">
      <Flex justify="between" align="start" gap="3">
        <Flex direction="column" gap="2">
          <Heading as="h1" size="5">
            Segments
          </Heading>
          <Text size="2" color="gray">
            Reusable audiences for personalization. Variants target segments;
            the first matching variant in a page's priority order is shown.
          </Text>
        </Flex>
        <Button mt="1" onClick={() => setIsCreateDialogOpen(true)}>
          <PlusIcon />
          Create segment
        </Button>
      </Flex>
      <SegmentList
        segments={Object.values(segments)}
        onCreateSegment={() => setIsCreateDialogOpen(true)}
        onEditSegment={handleEditSegment}
        onEditSegmentDetails={handleEditSegmentDetails}
        onDeleteSegment={handleDeleteSegment}
        onToggleSegment={handleToggleSegment}
        onReorderSegments={handleReorderSegments}
      />
      <CreateSegmentDialog
        open={isCreateDialogOpen}
        onOpenChange={setIsCreateDialogOpen}
        segments={segments}
      />
      <EditSegmentDialog
        segment={editedSegment}
        onClose={() => setEditedSegment(null)}
      />
      <DeleteSegmentDialog
        segment={deletedSegment}
        onClose={() => setDeletedSegment(null)}
      />
    </Flex>
  );
}
