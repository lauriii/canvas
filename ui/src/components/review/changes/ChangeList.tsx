import { Box } from '@radix-ui/themes';

import ChangeGroup from './ChangeGroup';

import type {
  UnpublishedChange,
  UnpublishedChangeGroups,
} from '@/types/Review';

interface ChangeListProps {
  groups: UnpublishedChangeGroups;
  isBusy: boolean;
  // When false, rows render without selection checkboxes; publishing always
  // covers the whole workspace.
  selectable?: boolean;
  selectedChanges?: UnpublishedChange[];
  setSelectedChanges?: (changes: UnpublishedChange[]) => void;
  onDiscardClick: (change: UnpublishedChange) => void;
  onViewClick?: (change: UnpublishedChange) => void;
  isViewChangeAvailable?: (change: UnpublishedChange) => boolean;
  onResolveConflict?: (change: UnpublishedChange) => void;
  pageStatusMap?: Record<
    string,
    { status: boolean; isNew?: boolean; hasUnsavedStatusChange?: boolean }
  >;
}

const ChangeList = ({
  groups,
  isBusy,
  selectable = true,
  selectedChanges,
  setSelectedChanges,
  onDiscardClick,
  onViewClick,
  isViewChangeAvailable,
  onResolveConflict,
  pageStatusMap,
}: ChangeListProps) => {
  return (
    groups && (
      <Box data-testid="pending-changes-list">
        {Object.entries(groups).map(([entityType, changes]) => {
          return (
            <ChangeGroup
              key={entityType}
              entityType={entityType}
              changes={changes}
              isBusy={isBusy}
              selectable={selectable}
              selectedChanges={selectedChanges}
              setSelectedChanges={setSelectedChanges}
              onDiscardClick={onDiscardClick}
              onViewClick={onViewClick}
              isViewChangeAvailable={isViewChangeAvailable}
              onResolveConflict={onResolveConflict}
              pageStatusMap={pageStatusMap}
            />
          );
        })}
      </Box>
    )
  );
};

export default ChangeList;
