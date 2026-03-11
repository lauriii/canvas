import { useCallback, useMemo } from 'react';
import { ClockIcon, DotsVerticalIcon } from '@radix-ui/react-icons';
import {
  Avatar,
  Badge,
  Box,
  Checkbox,
  DropdownMenu,
  Flex,
  IconButton,
  Text,
  Tooltip,
} from '@radix-ui/themes';

import { getAvatarInitialColor, getChangeLabel, getTimeAgo } from '../utils';
import ChangeIcon from './ChangeIcon';

import type { UnpublishedChange } from '@/types/Review';

import styles from './ChangeRow.module.css';

interface ChangeRowProps {
  change: UnpublishedChange;
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

const ChangeRow = ({
  change,
  isBusy = false,
  selectedChanges,
  setSelectedChanges,
  onDiscardClick,
  onViewClick,
  pageStatusMap,
}: ChangeRowProps) => {
  const changeLabel = getChangeLabel(change);
  const initial = change.owner.name.trim().charAt(0).toUpperCase();
  const avatarColor = getAvatarInitialColor(change.owner.id);
  const date = new Date(change.updated * 1000);
  const color = change.hasConflict ? 'red' : undefined;
  const weight = change.hasConflict ? 'bold' : 'regular';

  const isSelected = useMemo(() => {
    return selectedChanges.some((c) => c.pointer === change.pointer);
  }, [change.pointer, selectedChanges]);

  // Check if this page is unpublished
  const { isUnpublished, willBeUnpublished } = useMemo(() => {
    if (change.entity_type !== 'canvas_page' || !pageStatusMap) {
      return { isUnpublished: false, willBeUnpublished: false };
    }
    const pageStatus = pageStatusMap[String(change.entity_id)];
    if (!pageStatus) {
      return { isUnpublished: false, willBeUnpublished: false };
    }
    // Determine unpublished status:
    // - Unpublish: unpublished with unsaved status change
    // - Unpublished: unpublished and not new (draft) and no unsaved changes
    const isUnpublished =
      !pageStatus.status &&
      !pageStatus.isNew &&
      !pageStatus.hasUnsavedStatusChange;
    const willBeUnpublished =
      !pageStatus.status &&
      !pageStatus.isNew &&
      pageStatus.hasUnsavedStatusChange;
    return { isUnpublished, willBeUnpublished };
  }, [change.entity_type, change.entity_id, pageStatusMap]);

  const handleChangeSelection = useCallback(
    (checked: boolean) => {
      if (checked) {
        setSelectedChanges([...selectedChanges, change]);
      } else {
        setSelectedChanges(
          selectedChanges.filter((c) => c.pointer !== change.pointer),
        );
      }
    },
    [change, selectedChanges, setSelectedChanges],
  );

  return (
    <li className={styles.changeRow} data-testid="pending-change-row">
      <Flex as="div" direction="row" align="start" justify="between" gap="4">
        <Text as="label" color={color} weight={weight} size="1">
          <Flex as="div" direction="row" align="start" gap="2" pt="1">
            <Checkbox
              size="1"
              disabled={isBusy}
              aria-label={`Select change ${changeLabel}`}
              onCheckedChange={handleChangeSelection}
              checked={isSelected}
            />
            <Flex height="16px" align="center">
              <ChangeIcon
                entityType={change.entity_type}
                entityId={change.entity_id}
              />
            </Flex>
            {changeLabel}
          </Flex>
        </Text>
        <Flex
          as="div"
          direction="row"
          align="start"
          gap="2"
          className={styles.changeRowRight}
        >
          {(isUnpublished || willBeUnpublished) && (
            <Box pt="1">
              <Tooltip
                content={
                  willBeUnpublished ? 'Applies on next publish' : undefined
                }
              >
                <Badge
                  size="1"
                  variant="solid"
                  color="gray"
                  style={{
                    fontSize: '11px',
                    height: '16px',
                    lineHeight: '16px',
                    padding: '0 4px',
                  }}
                >
                  {willBeUnpublished ? (
                    <Flex align="center" gap="1">
                      <ClockIcon width="11" height="11" />
                      Unpublish
                    </Flex>
                  ) : (
                    'Unpublished'
                  )}
                </Badge>
              </Tooltip>
            </Box>
          )}
          <Box pt="1">
            <Tooltip content={date.toLocaleString()}>
              <Text className={styles.time} size="1" wrap="nowrap">
                {getTimeAgo(change.updated)}
              </Text>
            </Tooltip>
          </Box>
          <Tooltip content={`By ${change.owner.name}`}>
            <Avatar
              highContrast
              size="1"
              fallback={initial}
              className={styles.avatar}
              {...(change.owner.avatar
                ? { src: change.owner.avatar }
                : {
                    style: {
                      border: `1px solid var(--${avatarColor}-11)`,
                    },
                    color: avatarColor,
                  })}
            />
          </Tooltip>
          <Box pt="1">
            <DropdownMenu.Root>
              <DropdownMenu.Trigger>
                <IconButton disabled={isBusy} aria-label="More options">
                  <DotsVerticalIcon />
                </IconButton>
              </DropdownMenu.Trigger>
              <DropdownMenu.Content>
                {onViewClick && (
                  <DropdownMenu.Item onSelect={() => onViewClick(change)}>
                    View changes
                  </DropdownMenu.Item>
                )}
                <DropdownMenu.Item onSelect={() => onDiscardClick(change)}>
                  Discard changes
                </DropdownMenu.Item>
              </DropdownMenu.Content>
            </DropdownMenu.Root>
          </Box>
        </Flex>
      </Flex>
    </li>
  );
};

export default ChangeRow;
