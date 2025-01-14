import { Box, Button, Flex, Text, Avatar } from '@radix-ui/themes';
import {
  Component1Icon,
  Cross2Icon,
  CubeIcon,
  FileIcon,
} from '@radix-ui/react-icons';
import { useState } from 'react';
import * as Popover from '@radix-ui/react-popover';
import { differenceInMonths, format, formatDistanceToNow } from 'date-fns';
import { kebabCase } from 'lodash';
import Panel from '@/components/Panel';
import Tooltip from '@/components/Tooltip';
import styles from './PublishReview.module.css';
import type { PendingChange } from '@/services/pendingChangesApi';

export const DEFAULT_TITLE = 'Unpublished changes';
export const DEFAULT_BUTTON_TEXT = 'Publish all changes';

enum FallbackColor {
  SKY = 'sky',
  MINT = 'mint',
  LIME = 'lime',
  YELLOW = 'yellow',
  AMBER = 'amber',
  ORANGE = 'orange',
  BRONZE = 'bronze',
  JADE = 'jade',
  CYAN = 'cyan',
  INDIGO = 'indigo',
  IRIS = 'iris',
  VIOLET = 'violet',
  PINK = 'pink',
  RUBY = 'ruby',
}

export enum IconType {
  COMPONENT1 = 'component1',
  CUBE = 'cube',
  FILE = 'file',
}

type UnpublishedChange = PendingChange & {
  icon: IconType;
};

export type UnpublishedChanges = UnpublishedChange[];

interface PublishReviewProps {
  title?: string;
  changes: UnpublishedChanges;
  buttonText?: string;
  onPublishClick?: () => void;
  onOpenChangeCallback: () => void;
}

const PublishReview: React.FC<PublishReviewProps> = ({
  title = DEFAULT_TITLE,
  changes,
  onOpenChangeCallback,
}) => {
  const [isOpen, setIsOpen] = useState<boolean>(false);
  function triggerButtonText() {
    if (!changes || changes.length === 0) {
      return 'Publish';
    }
    if (changes.length === 1) {
      return 'Review 1 change';
    }
    return `Review ${changes.length} changes`;
  }

  const onOpenChangeHandler = (open: boolean): void => {
    setIsOpen(open);
    if (open) onOpenChangeCallback();
  };

  return (
    <Popover.Root open={isOpen} onOpenChange={onOpenChangeHandler}>
      <Popover.Trigger asChild>
        <Button
          variant="solid"
          disabled={!changes || !changes.length}
          data-testid="xb-publish-review"
        >
          {triggerButtonText()}
        </Button>
      </Popover.Trigger>
      <Popover.Content asChild data-testid="xb-publish-reviews-content">
        <Panel className={styles.content}>
          <Flex mb="5" align="center" justify="between" width="100%">
            <Box>
              <Text className={styles.headingTitle}>{title}</Text>
            </Box>
            <Box>
              <Popover.Close className={styles.close} aria-label="Close">
                <Cross2Icon />
              </Popover.Close>
            </Box>
          </Flex>
          <ChangeList changes={changes} />
        </Panel>
      </Popover.Content>
    </Popover.Root>
  );
};

const ChangeList = (props: { changes: UnpublishedChanges }) => {
  const { changes } = props;

  if (!changes?.length) return null;

  return (
    <ul className={styles.changeList} data-testid="pending-changes-list">
      {changes.map((change: UnpublishedChange, index: number) => (
        <ChangeRow
          key={`${kebabCase(change.label + change.updated)}`}
          change={change}
          index={index}
        />
      ))}
    </ul>
  );
};

export const ChangeRow = (props: {
  change: UnpublishedChange;
  index: number;
}) => {
  const { change, index } = props;
  const initial = change.owner.name.trim().charAt(0).toUpperCase();
  const avatarColor = getAvatarInitialColor(index);
  return (
    <li className={styles.changeRow} data-testid="pending-change-row">
      <Flex as="div" direction="row" align="center" justify="between" gap="4">
        {/* Left Section */}
        <Flex as="div" direction="row" align="center" gap="2">
          <ChangeIcon icon={change.icon} />
          <Text>{change.label}</Text>
        </Flex>
        {/* Right Section */}
        <Flex
          as="div"
          direction="row"
          align="center"
          gap="2"
          className={styles.changeRowRight}
        >
          <Text>{getTimeAgo(change.updated)}</Text>
          <Tooltip
            side="left"
            content={change.owner.name}
            children={
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
            }
          />
        </Flex>
      </Flex>
    </li>
  );
};

const ChangeIcon = (props: { icon: IconType }) => {
  const { icon } = props;
  return icon === IconType.COMPONENT1 ? (
    <Component1Icon className={styles.component1Icon} />
  ) : icon === IconType.CUBE ? (
    <CubeIcon className={styles.cubeIcon} />
  ) : icon === IconType.FILE ? (
    <FileIcon />
  ) : (
    ''
  );
};

/*
  We need to render change time as 1h ago or 8h ago or 20d ago
  while the date-fns plugin outputs as about 1 hour ago or
  8 hours ago or 20 days ago so preparing desired string
  by removing/replacing some strings.
 */
const getTimeAgo = (timestamp: number) => {
  const dateInMilliseconds = timestamp * 1000;
  const inputDate = new Date(dateInMilliseconds);

  // Calculate the difference in months
  const monthsDifference = differenceInMonths(new Date(), inputDate);

  // If the date is older than 1 month, use "dd MMM" format
  if (monthsDifference >= 1) {
    // @todo Implement Drupal-Specific Date Formatting(https://www.drupal.org/project/experience_builder/issues/3493779)
    return format(inputDate, 'd MMM');
  }

  const timeAgo = formatDistanceToNow(inputDate, { addSuffix: true });

  // Define a mapping for units
  const unitMappings: Record<string, string> = {
    'less than a minute': 'a moment',
    ' seconds': 's',
    ' second': 's',
    ' minutes': 'm',
    ' minute': 'm',
    ' hours': 'h',
    ' hour': 'h',
    ' days': 'd',
    ' day': 'd',
    ' month': 'mo',
    'about ': '',
  };

  return timeAgo.replace(
    new RegExp(Object.keys(unitMappings).join('|'), 'g'),
    (matched) => unitMappings[matched],
  );
};

const getAvatarInitialColor = (index: number): FallbackColor => {
  const colors: FallbackColor[] = Object.values(
    FallbackColor,
  ) as FallbackColor[];

  return colors[index % colors.length];
};
export default PublishReview;
