import { useEffect, useMemo, useState } from 'react';
import clsx from 'clsx';
import { format } from 'date-fns';
import {
  CheckIcon,
  ChevronDownIcon,
  Cross2Icon,
  DotsVerticalIcon,
  ExclamationTriangleIcon,
} from '@radix-ui/react-icons';
import {
  Box,
  Button,
  Callout,
  DropdownMenu,
  Flex,
  Heading,
  IconButton,
  Popover,
  ScrollArea,
  Spinner,
  Text,
  TextField,
} from '@radix-ui/themes';

import Dialog from '@/components/Dialog';
import PermissionCheck from '@/components/PermissionCheck';
import ReviewErrors from '@/components/review/ReviewErrors';
import { getReviewGroupKey } from '@/components/review/utils';
import { formatScheduledDate } from '@/components/workspaces/utils';
import WorkspaceStatusBadge from '@/components/workspaces/WorkspaceStatusBadge';
import { Divider } from '@/features/code-editor/component-data/FormElement';
import { isConflictUxEnabled } from '@/features/conflict/conflictUtils';

import ChangeList from './changes/ChangeList';
import ConflictBanner from './ConflictBanner';

import type { ErrorResponse } from '@/services/pendingChangesApi';
import type {
  Workspace,
  WorkspaceStatusTransition,
} from '@/services/workspacesApi';
import type {
  UnpublishedChange,
  UnpublishedChangeGroups,
} from '@/types/Review';

import styles from './PublishReview.module.css';

export const DEFAULT_TITLE = 'Unpublished changes';

interface PublishReviewProps {
  title?: string;
  changes: UnpublishedChange[];
  errors?: ErrorResponse | undefined;
  open?: boolean;
  workspace?: Workspace | null;
  onPublishClick: () => void;
  onDiscardClick: (selectedChange: UnpublishedChange) => void;
  onViewClick?: (change: UnpublishedChange) => void;
  onResolveConflict?: (change?: UnpublishedChange) => void;
  onOpenChangeCallback: (open: boolean) => void;
  onTransitionStatus?: (transition: WorkspaceStatusTransition) => void;
  onSchedulePublish?: (publishAt: number) => void;
  onCancelSchedule?: () => void;
  isPublishing: boolean;
  isDiscarding: boolean;
  isUpdating: boolean; // indicates if the preview is being updated
  isFetching?: boolean;
  isTransitioning?: boolean;
  isScheduling?: boolean;
  conflictCount?: number;
  pageStatusMap?: Record<
    string,
    { status: boolean; isNew?: boolean; hasUnsavedStatusChange?: boolean }
  >;
}

const PublishReview: React.FC<PublishReviewProps> = ({
  title = DEFAULT_TITLE,
  changes,
  errors,
  open: controlledOpen,
  workspace = null,
  onPublishClick,
  onDiscardClick,
  onViewClick,
  onResolveConflict,
  onOpenChangeCallback,
  onTransitionStatus,
  onSchedulePublish,
  onCancelSchedule,
  isPublishing = false,
  isDiscarding = false,
  isUpdating = false,
  isFetching = false,
  isTransitioning = false,
  isScheduling = false,
  conflictCount = 0,
  pageStatusMap,
}) => {
  const conflictUxEnabled = isConflictUxEnabled();
  // State to manage the open/close state of the popover
  const [internalOpen, setInternalOpen] = useState<boolean>(false);
  const isOpen = controlledOpen ?? internalOpen;

  // Single source to determine if something is happening
  const isBusy =
    isUpdating ||
    isPublishing ||
    isDiscarding ||
    isFetching ||
    isTransitioning ||
    isScheduling;

  const firstConflictedChange = useMemo(
    () => changes.find((change) => change.hasConflict),
    [changes],
  );

  // Used to display the `Published` state. Publishing covers the whole
  // workspace, so a publish attempt snapshots the pending pointers and the
  // flash appears once they have all disappeared from the change list.
  const [hasPublished, setHasPublished] = useState<boolean>(false);
  const [pendingPublishPointers, setPendingPublishPointers] = useState<
    string[]
  >([]);

  useEffect(() => {
    if (
      isPublishing ||
      errors?.errors?.length ||
      !pendingPublishPointers.length
    ) {
      return;
    }

    const currentPointers = new Set(changes.map((change) => change.pointer));
    if (
      pendingPublishPointers.every((pointer) => !currentPointers.has(pointer))
    ) {
      setHasPublished(true);
      setPendingPublishPointers([]);
    }
  }, [changes, errors?.errors?.length, isPublishing, pendingPublishPointers]);

  // Reset the published flash as soon as new pending changes arrive.
  useEffect(() => {
    if (changes.length > 0 && pendingPublishPointers.length === 0) {
      setHasPublished(false);
    }
  }, [changes.length, pendingPublishPointers.length]);

  // Schedule publish dialog state.
  const [scheduleDialogOpen, setScheduleDialogOpen] = useState(false);
  const [scheduleValue, setScheduleValue] = useState('');

  // The trigger button text changes based on the pending changes
  const triggerButtonText = useMemo(() => {
    if (!changes?.length) return 'No changes';
    if (changes.length === 1) return 'Review 1 change';
    return `Review ${changes.length} changes`;
  }, [changes]);

  // The publish button caption changes based on the state of the review
  const publishButtonText = useMemo(() => {
    if (isPublishing) return 'Publishing';
    if (isBusy) return 'Please wait';
    if (hasPublished) return 'Published';
    if (!changes?.length) return 'No changes available';
    return 'Publish now';
  }, [isPublishing, isBusy, hasPublished, changes]);

  const groups: UnpublishedChangeGroups = useMemo(() => {
    if (!changes?.length) return {};
    return changes.reduce((acc, change) => {
      const key = getReviewGroupKey(change.entity_type ?? 'unknown');
      if (!acc[key]) {
        acc[key] = [];
      }
      acc[key].push(change);
      return acc;
    }, {} as UnpublishedChangeGroups);
  }, [changes]);

  // Publish the whole workspace
  const handlePublishClick = () => {
    if (!changes?.length) {
      return;
    }
    setPendingPublishPointers(changes.map((change) => change.pointer));
    onPublishClick();
  };

  const onOpenChangeHandler = (open: boolean): void => {
    // Keep the popover open while the schedule dialog is showing.
    if (!open && scheduleDialogOpen) {
      return;
    }
    setHasPublished(false);
    if (controlledOpen === undefined) {
      setInternalOpen(open);
    }
    onOpenChangeCallback(open);
  };

  const handleResolveConflict = (change?: UnpublishedChange) => {
    onOpenChangeHandler(false);
    onResolveConflict?.(change ?? firstConflictedChange);
  };

  const handleScheduleConfirm = () => {
    if (!scheduleValue) {
      return;
    }
    // The datetime-local value is in the browser's local time zone; Date
    // parses it as such, so this converts local time to Unix seconds.
    const publishAt = Math.floor(new Date(scheduleValue).getTime() / 1000);
    onSchedulePublish?.(publishAt);
    setScheduleDialogOpen(false);
    setScheduleValue('');
  };

  const isScheduled = !!workspace?.scheduledPublishAt;
  const needsReview = !!workspace?.requireReview;
  // Without workspace data the panel falls back to plain publishing.
  const canPublish = !workspace || workspace.access.publish;

  const renderActions = () => {
    if (isScheduled && workspace) {
      return (
        <>
          <Button size="1" variant="solid" disabled>
            Scheduled
          </Button>
          {workspace.access.publish && onCancelSchedule && (
            <DropdownMenu.Root>
              <DropdownMenu.Trigger>
                <IconButton
                  size="1"
                  variant="soft"
                  disabled={isBusy}
                  aria-label="More publish options"
                >
                  <DotsVerticalIcon />
                </IconButton>
              </DropdownMenu.Trigger>
              <DropdownMenu.Content>
                <DropdownMenu.Item onSelect={() => onCancelSchedule()}>
                  Cancel scheduled publish
                </DropdownMenu.Item>
              </DropdownMenu.Content>
            </DropdownMenu.Root>
          )}
        </>
      );
    }

    if (workspace && needsReview && workspace.status === 'draft') {
      return (
        <Button
          size="1"
          variant="solid"
          disabled={
            isBusy || !changes?.length || !workspace.access.submitForReview
          }
          onClick={() => onTransitionStatus?.('submit')}
        >
          Send for review
          <Spinner loading={isTransitioning} />
        </Button>
      );
    }

    if (workspace && workspace.status === 'in_review') {
      if (!workspace.access.approve) {
        return (
          <Button size="1" variant="solid" disabled>
            In review
          </Button>
        );
      }
      return (
        <>
          <Button
            size="1"
            variant="soft"
            color="gray"
            disabled={isBusy}
            onClick={() => onTransitionStatus?.('reject')}
          >
            Send back to draft
          </Button>
          <Button
            size="1"
            variant="solid"
            disabled={isBusy}
            onClick={() => onTransitionStatus?.('approve')}
          >
            Approve
            <Spinner loading={isTransitioning} />
          </Button>
        </>
      );
    }

    // Publishable state: publish now, with schedule and review options in a
    // split-button dropdown when workspace data is available.
    return (
      <PermissionCheck hasPermission="publishChanges">
        <Button
          className={clsx({
            [styles.buttonBlue]: isPublishing || hasPublished,
          })}
          disabled={isBusy || !changes?.length || !canPublish}
          size="1"
          variant="solid"
          onClick={handlePublishClick}
        >
          {publishButtonText}
          <Spinner loading={isPublishing}>
            {(isPublishing || hasPublished) && <CheckIcon />}
          </Spinner>
        </Button>
        {workspace && (
          <DropdownMenu.Root>
            <DropdownMenu.Trigger>
              <IconButton
                size="1"
                variant="soft"
                disabled={isBusy}
                aria-label="More publish options"
              >
                <ChevronDownIcon />
              </IconButton>
            </DropdownMenu.Trigger>
            <DropdownMenu.Content>
              <DropdownMenu.Item
                disabled={!canPublish}
                onSelect={() => setScheduleDialogOpen(true)}
              >
                Schedule publish
              </DropdownMenu.Item>
              {needsReview && (
                <DropdownMenu.Item
                  onSelect={() => onTransitionStatus?.('reject')}
                >
                  Send back to draft
                </DropdownMenu.Item>
              )}
            </DropdownMenu.Content>
          </DropdownMenu.Root>
        )}
      </PermissionCheck>
    );
  };

  return (
    <>
      <Popover.Root open={isOpen} onOpenChange={onOpenChangeHandler}>
        <Popover.Trigger>
          <Button
            variant="solid"
            disabled={!changes?.length || isBusy}
            data-testid="canvas-publish-review"
            className={clsx(styles.triggerButton, {
              [styles.disableClick]: isBusy,
              [styles.noChanges]: !changes?.length,
            })}
          >
            {triggerButtonText}
          </Button>
        </Popover.Trigger>
        <Popover.Content
          asChild
          data-testid="canvas-publish-reviews-content"
          width="100vw"
          maxWidth="360px"
        >
          <Box p="0" m="0">
            <Flex p="4" align="center" justify="between" width="100%">
              <Flex align="center" gap="2">
                <Heading as="h3" size="3" weight="medium">
                  {workspace?.label ?? title}
                </Heading>
                {workspace && <WorkspaceStatusBadge workspace={workspace} />}
              </Flex>
              <Box>
                <Popover.Close className={styles.close} aria-label="Close">
                  <Cross2Icon />
                </Popover.Close>
              </Box>
            </Flex>
            <Divider />
            {workspace?.scheduledPublishAt && (
              <Box px="4" py="3">
                <Flex align="center" justify="between" gap="2">
                  <Text size="1">
                    Scheduled to publish{' '}
                    {formatScheduledDate(workspace.scheduledPublishAt)}
                  </Text>
                  {workspace.access.publish && onCancelSchedule && (
                    <Button
                      size="1"
                      variant="soft"
                      color="gray"
                      disabled={isBusy}
                      onClick={() => onCancelSchedule()}
                    >
                      Cancel
                    </Button>
                  )}
                </Flex>
              </Box>
            )}
            {workspace?.scheduledPublishError && (
              <Box px="4" py="3">
                <Callout.Root color="red" size="1">
                  <Callout.Icon>
                    <ExclamationTriangleIcon />
                  </Callout.Icon>
                  <Callout.Text>{workspace.scheduledPublishError}</Callout.Text>
                </Callout.Root>
              </Box>
            )}
            <Box className={isBusy ? styles.disabled : ''}>
              <ScrollArea
                style={{ maxHeight: '380px', width: '100%' }}
                type="scroll"
              >
                <ReviewErrors errorState={errors} />
                <Box px="4" pt="4">
                  <Text size="1">
                    {changes.length
                      ? `${changes.length} unpublished ${
                          changes.length === 1 ? 'change' : 'changes'
                        }`
                      : 'All changes published!'}
                  </Text>
                </Box>
                {conflictUxEnabled && conflictCount > 0 && (
                  <Box px="4" pt="4">
                    <ConflictBanner
                      conflictCount={conflictCount}
                      onResolveClick={() => handleResolveConflict()}
                      disabled={isBusy}
                    />
                  </Box>
                )}
                <Box px="4" pt="4">
                  {changes?.length > 0 && (
                    <ChangeList
                      groups={groups}
                      isBusy={isBusy}
                      selectable={false}
                      onDiscardClick={onDiscardClick}
                      onViewClick={onViewClick}
                      onResolveConflict={handleResolveConflict}
                      pageStatusMap={pageStatusMap}
                    />
                  )}
                </Box>
              </ScrollArea>
            </Box>
            <Divider />
            <Flex p="4" justify="end" align="center" gap="2" width="100%">
              {renderActions()}
            </Flex>
          </Box>
        </Popover.Content>
      </Popover.Root>
      <Dialog
        open={scheduleDialogOpen}
        onOpenChange={(open) => {
          setScheduleDialogOpen(open);
          if (!open) {
            setScheduleValue('');
          }
        }}
        title="Schedule publish"
        footer={{
          cancelText: 'Cancel',
          confirmText: 'Schedule',
          onConfirm: handleScheduleConfirm,
          isConfirmDisabled: !scheduleValue,
          isConfirmLoading: isScheduling,
        }}
      >
        <Flex direction="column" gap="2">
          <Text as="label" size="1" htmlFor="canvas-schedule-publish-at">
            Publish at
          </Text>
          <TextField.Root
            id="canvas-schedule-publish-at"
            data-testid="canvas-schedule-publish-input"
            type="datetime-local"
            min={format(new Date(), "yyyy-MM-dd'T'HH:mm")}
            value={scheduleValue}
            onChange={(event) => setScheduleValue(event.target.value)}
          />
        </Flex>
      </Dialog>
    </>
  );
};

export default PublishReview;
