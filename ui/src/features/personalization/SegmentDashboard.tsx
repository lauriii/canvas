import { useCallback, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { PlusIcon } from '@radix-ui/react-icons';
import { Button, Flex } from '@radix-ui/themes';

import DefaultSitePanel from '@/components/personalization/DefaultSitePanel';
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
    return <div>Loading segments...</div>;
  }

  if (error) {
    return <div>Error loading segments: {JSON.stringify(error)}</div>;
  }

  return (
    <Flex direction="column" gap="6">
      <Flex justify="end" align="center">
        <Button onClick={() => setIsCreateDialogOpen(true)}>
          <PlusIcon />
          Create segment
        </Button>
      </Flex>
      <DefaultSitePanel />
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
