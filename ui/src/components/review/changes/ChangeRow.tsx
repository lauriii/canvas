import {
  Text,
  Flex,
  Checkbox,
  Avatar,
  Tooltip,
  Box,
  IconButton,
  DropdownMenu,
} from '@radix-ui/themes';
import {
  CubeIcon,
  FileIcon,
  Component1Icon,
  CodeIcon,
  DotsVerticalIcon,
  HomeIcon,
} from '@radix-ui/react-icons';
import { useCallback, useMemo } from 'react';
import styles from './ChangeRow.module.css';
import type { UnpublishedChange } from '@/types/Review';
import { getAvatarInitialColor, getTimeAgo } from '../utils';

const ChangeIcon = (props: { entityType: string }) => {
  const { entityType } = props;
  switch (entityType) {
    case 'js_component':
      return <Component1Icon className={styles.changeIcon} />;
    case 'xb_asset_library':
      return <CodeIcon className={styles.changeIcon} />;
    case 'page_region':
      return <CubeIcon className={styles.changeIcon} />;
    case 'staged_config_update':
      // Currently the only staged config update supported is setting
      // the homepage.
      return <HomeIcon className={styles.changeIcon} />;
    default:
      return <FileIcon className={styles.changeIcon} />;
  }
};

interface ChangeRowProps {
  change: UnpublishedChange;
  isBusy: boolean;
  selectedChanges: UnpublishedChange[];
  setSelectedChanges: (changes: UnpublishedChange[]) => void;
  onDiscardClick: (change: UnpublishedChange) => void;
  onViewClick?: (change: UnpublishedChange) => void;
}

const ChangeRow = ({
  change,
  isBusy = false,
  selectedChanges,
  setSelectedChanges,
  onDiscardClick,
  onViewClick,
}: ChangeRowProps) => {
  const initial = change.owner.name.trim().charAt(0).toUpperCase();
  const avatarColor = getAvatarInitialColor(change.owner.id);
  const date = new Date(change.updated * 1000);
  const color = change.hasConflict ? 'red' : undefined;
  const weight = change.hasConflict ? 'bold' : 'regular';

  const isSelected = useMemo(() => {
    return selectedChanges.some((c) => c.pointer === change.pointer);
  }, [change.pointer, selectedChanges]);

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
      <Flex as="div" direction="row" align="center" justify="between" gap="4">
        <Text as="label" color={color} weight={weight} size="1">
          <Flex as="div" direction="row" align="center" gap="2">
            <Checkbox
              size="1"
              disabled={isBusy}
              aria-label={`Select change ${change.label}`}
              onCheckedChange={handleChangeSelection}
              checked={isSelected}
            />
            <ChangeIcon entityType={change.entity_type} />
            {change.label}
          </Flex>
        </Text>
        <Flex
          as="div"
          direction="row"
          align="center"
          gap="2"
          className={styles.changeRowRight}
        >
          <Tooltip content={date.toLocaleString()}>
            <Text className={styles.time} size="1">
              {getTimeAgo(change.updated)}
            </Text>
          </Tooltip>
          <Tooltip content={`By ${change.owner.name}`}>
            <Box>
              <Avatar
                highContrast
                size="1"
                fallback={initial}
                className={styles.avatar}
                {...(change.owner.avatar
                  ? { src: change.owner.avatar }
                  : {
                      style: {
                        borderColor: `var(--${avatarColor}-11)`,
                      },
                      color: avatarColor,
                    })}
              />
            </Box>
          </Tooltip>
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
        </Flex>
      </Flex>
    </li>
  );
};

export default ChangeRow;
