import { Link as RouterLink } from 'react-router-dom';
import {
  closestCenter,
  DndContext,
  KeyboardSensor,
  PointerSensor,
  useSensor,
  useSensors,
} from '@dnd-kit/core';
import {
  arrayMove,
  SortableContext,
  sortableKeyboardCoordinates,
  useSortable,
  verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import {
  Crosshair2Icon,
  DotsHorizontalIcon,
  DragHandleDots2Icon,
  PlusIcon,
} from '@radix-ui/react-icons';
import {
  Badge,
  Button,
  Card,
  DropdownMenu,
  Flex,
  IconButton,
  Link,
  Switch,
  Table,
  Text,
} from '@radix-ui/themes';

import { orderedRules } from '@/features/personalization/orderedRules';
import { ruleSummary } from '@/features/personalization/rules';

import type { DragEndEvent } from '@dnd-kit/core';
import type { Segment } from '@/types/Personalization';

import styles from './SegmentList.module.css';

/**
 * One-line plain-language summary of what a segment does, shown under its
 * label.
 */
const SegmentSummary = ({ segment }: { segment: Segment }) => {
  // The default segment has no rules by design: it always matches.
  if (segment.id === 'default') {
    return (
      <Text size="1" color="gray">
        Matches all visitors
      </Text>
    );
  }
  // Summarize every rule the segment carries, including condition types this
  // client has no editor for, in a stable order.
  const summaries = orderedRules(segment.rules).map(ruleSummary);
  if (summaries.length === 0) {
    return (
      <Text size="1" color="amber">
        No rules yet — matches no one
      </Text>
    );
  }
  return (
    <Text size="1" color="gray">
      {summaries.join('; and ')}
    </Text>
  );
};

interface SortableTableRowProps {
  segment: Segment;
  onToggleSegment: (segmentId: string, enabled: boolean) => void;
  onEditSegment: (segmentId: string) => void;
  onEditSegmentDetails: (segmentId: string) => void;
  onDeleteSegment: (segmentId: string) => void;
}

const SortableTableRow = ({
  segment,
  onToggleSegment,
  onEditSegment,
  onEditSegmentDetails,
  onDeleteSegment,
}: SortableTableRowProps) => {
  const { id, status, label } = segment;
  const isDefaultSegment = id === 'default';
  const {
    attributes,
    listeners,
    setNodeRef,
    transform,
    transition,
    isDragging,
  } = useSortable({ id, disabled: isDefaultSegment });

  const style = {
    transform: CSS.Transform.toString(transform),
    transition,
    opacity: isDragging ? 0.5 : 1,
  };

  return (
    <Table.Row ref={setNodeRef} style={style}>
      <Table.Cell>
        {!isDefaultSegment && (
          <div
            {...attributes}
            {...listeners}
            style={{ cursor: isDragging ? 'grabbing' : 'grab' }}
          >
            <DragHandleDots2Icon />
          </div>
        )}
      </Table.Cell>
      <Table.Cell>
        {isDefaultSegment ? (
          <Badge color={status ? 'green' : 'gray'}>
            {status ? 'Enabled' : 'Disabled'}
          </Badge>
        ) : (
          <Text as="label" size="1">
            <Flex gap="2" align="center">
              <Switch
                size="1"
                checked={status}
                aria-label={`Enable ${label}`}
                onCheckedChange={(enabled) => onToggleSegment?.(id, enabled)}
              />
              {status ? 'Enabled' : 'Disabled'}
            </Flex>
          </Text>
        )}
      </Table.Cell>
      <Table.Cell>
        <Flex direction="column" gap="1">
          {/* Disabled segments render a dimmed label. */}
          {isDefaultSegment ? (
            <Text color={status ? undefined : 'gray'}>{label}</Text>
          ) : (
            <Link asChild color={status ? undefined : 'gray'}>
              <RouterLink to={`/segments/${id}`}>{label}</RouterLink>
            </Link>
          )}
          <SegmentSummary segment={segment} />
        </Flex>
      </Table.Cell>
      <Table.Cell>
        <Flex gap="6" align="center" justify="end">
          {!isDefaultSegment && (
            <DropdownMenu.Root>
              <DropdownMenu.Trigger>
                <IconButton variant="ghost" aria-label={`Open ${label} menu`}>
                  <DotsHorizontalIcon />
                </IconButton>
              </DropdownMenu.Trigger>
              <DropdownMenu.Content align="end">
                <DropdownMenu.Item onSelect={() => onEditSegment?.(id)}>
                  Edit segment rules
                </DropdownMenu.Item>
                <DropdownMenu.Item onSelect={() => onEditSegmentDetails?.(id)}>
                  Edit segment details
                </DropdownMenu.Item>
                <DropdownMenu.Separator />
                <DropdownMenu.Item
                  color="red"
                  onSelect={() => onDeleteSegment?.(id)}
                >
                  Delete segment
                </DropdownMenu.Item>
              </DropdownMenu.Content>
            </DropdownMenu.Root>
          )}
        </Flex>
      </Table.Cell>
    </Table.Row>
  );
};

interface SegmentListProps {
  segments: Segment[];
  onCreateSegment: () => void;
  onReorderSegments: (segments: Segment[]) => void;
  onToggleSegment: (segmentId: string, enabled: boolean) => void;
  onEditSegment: (segmentId: string) => void;
  onEditSegmentDetails: (segmentId: string) => void;
  onDeleteSegment: (segmentId: string) => void;
}

const SegmentList = ({
  segments = [],
  onCreateSegment,
  onReorderSegments,
  onToggleSegment,
  onEditSegment,
  onEditSegmentDetails,
  onDeleteSegment,
}: SegmentListProps) => {
  // Sort segments by weight (ascending), with undefined weights treated as 0
  const sortedSegments = [...segments].sort((a, b) => a.weight - b.weight);
  const sensors = useSensors(
    useSensor(PointerSensor),
    useSensor(KeyboardSensor, {
      coordinateGetter: sortableKeyboardCoordinates,
    }),
  );

  const handleDragEnd = (event: DragEndEvent) => {
    const { active, over } = event;

    if (active.id !== over?.id) {
      const oldIndex = sortedSegments.findIndex(
        (segment) => segment.id === active.id,
      );
      const newIndex = sortedSegments.findIndex(
        (segment) => segment.id === over?.id,
      );

      const reorderedSegments = arrayMove(sortedSegments, oldIndex, newIndex);
      onReorderSegments?.(reorderedSegments);
    }
  };

  return (
    <Flex direction="column" gap="4">
      {sortedSegments.length > 0 && (
        <Text size="3" weight="bold">
          Personalization segments
        </Text>
      )}
      <Card className={styles.segmentListCard}>
        {sortedSegments.length > 0 ? (
          <DndContext
            sensors={sensors}
            collisionDetection={closestCenter}
            onDragEnd={handleDragEnd}
          >
            <Table.Root className={styles.segmentTable}>
              <Table.Header>
                <Table.Row>
                  <Table.ColumnHeaderCell width="2rem"></Table.ColumnHeaderCell>
                  <Table.ColumnHeaderCell width="8rem">
                    Status
                  </Table.ColumnHeaderCell>
                  <Table.ColumnHeaderCell>Segment title</Table.ColumnHeaderCell>
                  <Table.ColumnHeaderCell width="6rem"></Table.ColumnHeaderCell>
                </Table.Row>
              </Table.Header>

              <Table.Body>
                <SortableContext
                  items={sortedSegments.map((segment) => segment.id)}
                  strategy={verticalListSortingStrategy}
                >
                  {sortedSegments.map((segment) => (
                    <SortableTableRow
                      key={segment.id}
                      segment={segment}
                      onToggleSegment={onToggleSegment}
                      onEditSegment={onEditSegment}
                      onEditSegmentDetails={onEditSegmentDetails}
                      onDeleteSegment={onDeleteSegment}
                    />
                  ))}
                </SortableContext>
              </Table.Body>
            </Table.Root>
          </DndContext>
        ) : (
          <Flex p="8" direction="column" gap="3" align="center">
            <Flex align="center" gap="0" direction="column">
              <Crosshair2Icon />
              <Text size="1" weight="medium">
                Create a new segment
              </Text>
              <Text size="1" align="center">
                A segment is a group of visitors with shared interests or
                behaviors.
              </Text>
            </Flex>
            <Button onClick={onCreateSegment}>
              <PlusIcon /> Create segment
            </Button>
          </Flex>
        )}
      </Card>
      {/* Drag handles only exist on non-default rows, so the note appears
          with them. */}
      {sortedSegments.some((segment) => segment.id !== 'default') && (
        <Text size="1" color="gray">
          Drag to reorder this list. The order is display only — the variant a
          visitor sees is decided by the variant priority on each page.
        </Text>
      )}
    </Flex>
  );
};

export default SegmentList;
