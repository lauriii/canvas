import { useCallback, useMemo } from 'react';
import { kebabCase } from 'lodash';
import { Box, Checkbox, Flex, Text } from '@radix-ui/themes';

import { getGroupLabel } from '../utils';
import ChangeRow from './ChangeRow';

import type { UnpublishedChange } from '@/types/Review';

import styles from './ChangeGroup.module.css';

interface ChangeGroupProps {
  entityType: string;
  changes: UnpublishedChange[];
  isBusy: boolean;
  selectedChanges: UnpublishedChange[];
  setSelectedChanges: (changes: UnpublishedChange[]) => void;
  onDiscardClick: (change: UnpublishedChange) => void;
  onViewClick?: (change: UnpublishedChange) => void;
  pageStatusMap?: Record<
    string,
    { status: boolean; isNew?: boolean; hasUnsavedStatusChange?: boolean }
  >;
}

const ChangeGroup = ({
  entityType,
  changes,
  isBusy,
  selectedChanges,
  setSelectedChanges,
  onDiscardClick,
  onViewClick,
  pageStatusMap,
}: ChangeGroupProps) => {
  const isGroupSelected = useMemo(() => {
    const groupSelectionCount = changes.filter((change) =>
      selectedChanges.some((selected) => selected.pointer === change.pointer),
    ).length;

    if (groupSelectionCount === 0) return false;
    if (groupSelectionCount < changes.length) return 'indeterminate';
    return true;
  }, [changes, selectedChanges]);

  const handleGroupSelection = useCallback(() => {
    const groupPointers = changes.map((change) => change.pointer);
    // If the group is fully selected, deselect all changes in the group
    if (isGroupSelected === true) {
      setSelectedChanges(
        selectedChanges.filter(
          (change) => !groupPointers.includes(change.pointer),
        ),
      );
      return;
    }
    // If the group is not fully selected, select remaining changes in the group
    setSelectedChanges([
      ...selectedChanges,
      ...changes.filter(
        (change) =>
          !selectedChanges.some(
            (selected) => selected.pointer === change.pointer,
          ),
      ),
    ]);
  }, [isGroupSelected, changes, selectedChanges, setSelectedChanges]);

  const groupLabel = getGroupLabel(entityType);

  return (
    <Box data-testid="pending-change-group">
      <Text as="label" size="1">
        <Flex as="div" direction="row" align="center" gap="2" mb="2">
          <Checkbox
            size="1"
            disabled={isBusy}
            checked={isGroupSelected}
            onCheckedChange={handleGroupSelection}
            aria-label={`Select all changes in ${groupLabel}`}
          />
          {groupLabel}
        </Flex>
      </Text>
      <ul className={styles.changeList}>
        {changes.map((change: UnpublishedChange, index: number) => (
          <ChangeRow
            key={`${kebabCase(change.label + change.updated)}`}
            change={change}
            isBusy={isBusy}
            selectedChanges={selectedChanges}
            setSelectedChanges={setSelectedChanges}
            onDiscardClick={onDiscardClick}
            onViewClick={onViewClick}
            pageStatusMap={pageStatusMap}
          />
        ))}
      </ul>
    </Box>
  );
};

export default ChangeGroup;
