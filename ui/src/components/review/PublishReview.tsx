import {
  Box,
  Button,
  Flex,
  Text,
  Avatar,
  Tooltip,
  Callout,
  Heading,
} from '@radix-ui/themes';
import CmsIcon from '@assets/icons/cms.svg?react';
import {
  Component1Icon,
  Cross2Icon,
  CubeIcon,
  FaceIcon,
  FileIcon,
} from '@radix-ui/react-icons';
import { useState } from 'react';
import * as Popover from '@radix-ui/react-popover';
import { differenceInMonths, format, formatDistanceToNow } from 'date-fns';
import { kebabCase } from 'lodash';
import Panel from '@/components/Panel';

import styles from './PublishReview.module.css';
import type {
  ErrorResponse,
  PendingChange,
} from '@/services/pendingChangesApi';
import ReviewErrors from '@/components/review/ReviewErrors';

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
  CMS = 'cms',
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
  errors?: ErrorResponse | undefined;
  buttonText?: string;
  onPublishClick: () => void;
  onOpenChangeCallback: (open: boolean) => void;
  isPublishing: boolean;
}

// @todo https://www.drupal.org/i/3501449 - this colour randomizer should be replaced with a proper solution
const colors = Object.values(FallbackColor);
const usernameColorMap: Map<number, FallbackColor> = new Map();
// Initialize colorIndex with a random starting point
let colorIndex = Math.floor(Math.random() * colors.length);

/**
 * Function to get a consistent color for a given username
 * @param userId
 */
function getAvatarInitialColor(userId: number): FallbackColor {
  // Return the cached color if it exists
  if (usernameColorMap.has(userId)) {
    return usernameColorMap.get(userId)!;
  }

  const color = colors[colorIndex];
  // Store the color in the map for future reference
  usernameColorMap.set(userId, color);
  // Increment the color index, wrapping around if necessary
  colorIndex = (colorIndex + 1) % colors.length;

  return color;
}

const PublishReview: React.FC<PublishReviewProps> = ({
  title = DEFAULT_TITLE,
  changes,
  errors,
  buttonText = DEFAULT_BUTTON_TEXT,
  onPublishClick,
  onOpenChangeCallback,
  isPublishing,
}) => {
  const [isOpen, setIsOpen] = useState<boolean>(false);

  function triggerButtonText() {
    if (!changes || changes.length === 0) {
      return 'No changes';
    }
    if (changes.length === 1) {
      return 'Review 1 change';
    }
    return `Review ${changes.length} changes`;
  }

  const onOpenChangeHandler = (open: boolean): void => {
    setIsOpen(open);
    onOpenChangeCallback(open);
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
              <Heading as="h3" size="2" className={styles.headingTitle}>
                {title}
              </Heading>
            </Box>
            <Box>
              <Popover.Close className={styles.close} aria-label="Close">
                <Cross2Icon />
              </Popover.Close>
            </Box>
          </Flex>
          {(!changes || changes.length === 0) && (
            <Callout.Root color="green">
              <Callout.Icon>
                <FaceIcon />
              </Callout.Icon>
              <Callout.Text>All changes published!</Callout.Text>
            </Callout.Root>
          )}
          {changes?.length > 0 && (
            <>
              <ChangeList changes={changes} />
              <ReviewErrors errorState={errors} />
            </>
          )}
          <Flex mt="1" justify="end" align="center" width="100%">
            <Box mt="3">
              <Button
                disabled={
                  !onPublishClick || isPublishing || !changes || !changes.length
                }
                variant="solid"
                onClick={onPublishClick}
              >
                {isPublishing ? (
                  <span className={styles.loading}>Publishing</span>
                ) : (
                  buttonText
                )}
              </Button>
            </Box>
          </Flex>
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
  const { change } = props;
  const initial = change.owner.name.trim().charAt(0).toUpperCase();
  const avatarColor = getAvatarInitialColor(change.owner.id);
  const date = new Date(change.updated * 1000);
  const color = change.hasConflict ? 'red' : undefined;
  const weight = change.hasConflict ? 'bold' : 'regular';
  return (
    <li className={styles.changeRow} data-testid="pending-change-row">
      <Flex as="div" direction="row" align="center" justify="between" gap="4">
        <Flex as="div" direction="row" align="center" gap="2">
          <ChangeIcon icon={change.icon} />
          <Text color={color} weight={weight}>
            {change.label}
          </Text>
        </Flex>
        <Flex
          as="div"
          direction="row"
          align="center"
          gap="2"
          className={styles.changeRowRight}
        >
          <Tooltip content={date.toLocaleString()}>
            <Text>{getTimeAgo(change.updated)}</Text>
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
        </Flex>
      </Flex>
    </li>
  );
};

const ChangeIcon = (props: { icon: IconType }) => {
  const { icon } = props;
  if (icon === IconType.CMS) {
    return <CmsIcon className={styles.cmsIcon} />;
  }
  if (icon === IconType.COMPONENT1) {
    return <Component1Icon className={styles.component1Icon} />;
  }
  if (icon === IconType.CUBE) {
    return <CubeIcon className={styles.cubeIcon} />;
  }
  if (icon === IconType.FILE) {
    return <FileIcon className={styles.fileIcon} />;
  }
  return '';
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

export default PublishReview;
