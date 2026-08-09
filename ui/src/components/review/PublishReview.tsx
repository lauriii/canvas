import { useEffect, useMemo, useState } from 'react';
import clsx from 'clsx';
import { CheckIcon, Cross2Icon } from '@radix-ui/react-icons';
import {
  Box,
  Button,
  Checkbox,
  Flex,
  Heading,
  Popover,
  ScrollArea,
  Spinner,
  Text,
} from '@radix-ui/themes';

import PermissionCheck from '@/components/PermissionCheck';
import ReviewErrors from '@/components/review/ReviewErrors';
import { getReviewGroupKey } from '@/components/review/utils';
import { Divider } from '@/features/code-editor/component-data/FormElement';
import { isConflictUxEnabled } from '@/features/conflict/conflictUtils';

import ChangeList from './changes/ChangeList';
import ConflictBanner from './ConflictBanner';

import type { ErrorResponse } from '@/services/pendingChangesApi';
import type {
  UnpublishedChange,
  UnpublishedChangeGroups,
} from '@/types/Review';

import styles from './PublishReview.module.css';

export const DEFAULT_TITLE = Drupal.t('Unpublished changes');

interface PublishReviewProps {
  title?: string;
  changes: UnpublishedChange[];
  errors?: ErrorResponse | undefined;
  open?: boolean;
  onPublishClick: (selectedChanges: UnpublishedChange[]) => void;
  onDiscardClick: (selectedChange: UnpublishedChange) => void;
  onViewClick?: (change: UnpublishedChange) => void;
  onReviewSelectedChanges?: (selectedChanges: UnpublishedChange[]) => void;
  onResolveConflict?: (change?: UnpublishedChange) => void;
  onOpenChangeCallback: (open: boolean) => void;
  isViewChangeAvailable?: (change: UnpublishedChange) => boolean;
  isPublishing: boolean;
  isDiscarding: boolean;
  isUpdating: boolean; // indicates if the preview is being updated
  isFetching?: boolean;
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
  onPublishClick,
  onDiscardClick,
  onViewClick,
  onReviewSelectedChanges,
  onResolveConflict,
  onOpenChangeCallback,
  isViewChangeAvailable,
  isPublishing = false,
  isDiscarding = false,
  isUpdating = false,
  isFetching = false,
  conflictCount = 0,
  pageStatusMap,
}) => {
  const conflictUxEnabled = isConflictUxEnabled();
  // State to manage the open/close state of the popover
  const [internalOpen, setInternalOpen] = useState<boolean>(false);
  const isOpen = controlledOpen ?? internalOpen;

  // Single source to determine if something is happening
  const isBusy = isUpdating || isPublishing || isDiscarding || isFetching;

  // State to manage selected changes
  const [selectedChanges, setSelectedChanges] = useState<UnpublishedChange[]>(
    [],
  );

  // Memoize the selected changes to avoid unnecessary re-renders
  const selectableChanges = useMemo(
    () =>
      conflictUxEnabled
        ? changes.filter((change) => !change.hasConflict)
        : changes,
    [changes, conflictUxEnabled],
  );

  const firstConflictedChange = useMemo(
    () => changes.find((change) => change.hasConflict),
    [changes],
  );

  const selectableChangesByPointer = useMemo(
    () =>
      new Map(
        selectableChanges.map((change) => [change.pointer, change] as const),
      ),
    [selectableChanges],
  );

  const selectedAvailableChanges = useMemo(
    () =>
      selectedChanges.reduce<UnpublishedChange[]>((currentChanges, change) => {
        const currentChange = selectableChangesByPointer.get(change.pointer);
        if (currentChange) {
          currentChanges.push(currentChange);
        }
        return currentChanges;
      }, []),
    [selectableChangesByPointer, selectedChanges],
  );

  const selectionIsCurrent =
    selectedChanges.length === selectedAvailableChanges.length &&
    selectedChanges.every(
      (change, index) => change === selectedAvailableChanges[index],
    );

  const selectedReviewableChanges = useMemo(() => {
    if (!conflictUxEnabled) {
      return [];
    }
    return selectedAvailableChanges.filter((change) =>
      isViewChangeAvailable ? isViewChangeAvailable(change) : true,
    );
  }, [conflictUxEnabled, isViewChangeAvailable, selectedAvailableChanges]);

  const allSelected = useMemo(() => {
    if (!selectableChanges?.length) return false;
    return selectedAvailableChanges?.length === selectableChanges?.length
      ? true
      : 'indeterminate';
  }, [selectableChanges, selectedAvailableChanges]);

  // Used to display the `Published` state, which resets on new selections
  const [hasPublished, setHasPublished] = useState<boolean>(false);

  useEffect(() => {
    if (isPublishing || errors?.errors?.length || !selectedChanges?.length) {
      return;
    }

    if (selectionIsCurrent) {
      return;
    }

    if (!selectedAvailableChanges.length) {
      setHasPublished(true);
      setSelectedChanges([]);
      return;
    }

    setSelectedChanges(selectedAvailableChanges);
  }, [
    errors?.errors?.length,
    isPublishing,
    selectedAvailableChanges,
    selectedChanges.length,
    selectionIsCurrent,
  ]);
  useEffect(() => {
    if (selectedAvailableChanges.length > 0) {
      setHasPublished(false);
    }
  }, [selectedAvailableChanges.length]);

  useEffect(() => {
    if (!conflictUxEnabled) {
      return;
    }
    const conflictedPointers = new Set(
      changes
        .filter((change) => change.hasConflict)
        .map((change) => change.pointer),
    );
    if (!conflictedPointers.size) {
      return;
    }
    setSelectedChanges((existing) =>
      existing.filter((change) => !conflictedPointers.has(change.pointer)),
    );
  }, [changes, conflictUxEnabled]);

  // The trigger button text changes based on the pending changes
  const triggerButtonText = useMemo(() => {
    if (!changes?.length) return Drupal.t('No changes');
    return Drupal.formatPlural(
      changes.length,
      'Review 1 change',
      'Review @count changes',
    );
  }, [changes]);

  // The button caption changes based on the state of the review
  const buttonText = useMemo(() => {
    if (isPublishing) return Drupal.t('Publishing');
    if (isBusy) return Drupal.t('Please wait');
    // "Published" here reports that publishing just finished, unlike the page
    // status badge where it names the state a page is in.
    if (hasPublished) {
      return Drupal.t('Published', {}, { context: 'Canvas publishing result' });
    }
    if (!changes?.length) return Drupal.t('No changes available');
    if (!selectedAvailableChanges?.length) return Drupal.t('No items selected');
    return Drupal.formatPlural(
      selectedAvailableChanges.length,
      'Publish 1 selected',
      'Publish @count selected',
    );
  }, [isPublishing, isBusy, hasPublished, changes, selectedAvailableChanges]);

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

  // Remove selections if all are selected, otherwise select all
  const handleSelectAll = () => {
    if (isBusy) return;
    if (allSelected === true) {
      setSelectedChanges([]);
    } else {
      setSelectedChanges(selectableChanges);
    }
  };

  // Publish the selected changes
  const handlePublishClick = () => {
    if (onPublishClick && selectedAvailableChanges?.length) {
      onPublishClick(selectedAvailableChanges);
    }
  };

  const handleDiscardClick = (change: UnpublishedChange) => {
    setSelectedChanges((existingChanges) =>
      existingChanges.filter((c) => c.pointer !== change.pointer),
    );
    onDiscardClick(change);
  };

  const onOpenChangeHandler = (open: boolean): void => {
    setHasPublished(false);
    if (!open) {
      setSelectedChanges([]);
    }
    if (controlledOpen === undefined) {
      setInternalOpen(open);
    }
    onOpenChangeCallback(open);
  };

  const handleResolveConflict = (change?: UnpublishedChange) => {
    onOpenChangeHandler(false);
    onResolveConflict?.(change ?? firstConflictedChange);
  };

  const handleReviewSelectedChanges = () => {
    if (!selectedReviewableChanges.length) {
      return;
    }
    setHasPublished(false);
    onOpenChangeHandler(false);
    onReviewSelectedChanges?.(selectedAvailableChanges);
  };

  return (
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
            <Box>
              <Heading as="h3" size="3" weight="medium">
                {title}
              </Heading>
            </Box>
            <Box>
              <Popover.Close
                className={styles.close}
                aria-label={Drupal.t('Close')}
              >
                <Cross2Icon />
              </Popover.Close>
            </Box>
          </Flex>
          <Divider />
          <Box
            p="4"
            className={isBusy || !changes?.length ? styles.disabled : ''}
          >
            <Text as="label" size="1" className={styles.selectAll}>
              <Flex align="center" gap="2">
                <Checkbox
                  id="select-all-changes"
                  disabled={isBusy || selectableChanges.length === 0}
                  checked={allSelected === true}
                  onCheckedChange={handleSelectAll}
                  size="1"
                  aria-label={Drupal.t('Select all changes')}
                  data-testid="canvas-publish-review-select-all"
                />
                {Drupal.t('Select All')}
              </Flex>
            </Text>
          </Box>
          <Divider />
          <Box className={isBusy ? styles.disabled : ''}>
            <ScrollArea
              style={{ maxHeight: '380px', width: '100%' }}
              type="scroll"
            >
              <ReviewErrors errorState={errors} />
              <Box px="4" pt="4">
                <Text size="1">
                  {changes.length
                    ? Drupal.t('@selected of @total changes selected', {
                        '@selected': selectedAvailableChanges.length,
                        '@total': changes?.length ?? 0,
                      })
                    : Drupal.t('All changes published!')}
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
                  <>
                    <ChangeList
                      groups={groups}
                      isBusy={isBusy}
                      selectedChanges={selectedAvailableChanges}
                      setSelectedChanges={setSelectedChanges}
                      onDiscardClick={handleDiscardClick}
                      onViewClick={onViewClick}
                      isViewChangeAvailable={isViewChangeAvailable}
                      onResolveConflict={handleResolveConflict}
                      pageStatusMap={pageStatusMap}
                    />
                  </>
                )}
              </Box>
            </ScrollArea>
          </Box>
          <Divider />
          <PermissionCheck hasPermission="publishChanges">
            <Flex p="4" direction="column" align="stretch" gap="2" width="100%">
              <Button
                className={
                  isPublishing || hasPublished ? styles.buttonBlue : ''
                }
                disabled={
                  !onPublishClick || isBusy || !selectedAvailableChanges.length
                }
                size="1"
                variant="solid"
                onClick={handlePublishClick}
              >
                {buttonText}
                <Spinner loading={isPublishing}>
                  {(isPublishing || hasPublished) && <CheckIcon />}
                </Spinner>
              </Button>
              {conflictUxEnabled && onReviewSelectedChanges && (
                <Button
                  size="1"
                  variant="ghost"
                  disabled={isBusy || !selectedReviewableChanges.length}
                  onClick={handleReviewSelectedChanges}
                  className={styles.reviewSelectedButton}
                >
                  {Drupal.t('Review selected changes')}
                </Button>
              )}
            </Flex>
          </PermissionCheck>
        </Box>
      </Popover.Content>
    </Popover.Root>
  );
};

export default PublishReview;
