import {
  ArrowLeftIcon,
  ArrowRightIcon,
  CheckCircledIcon,
  Cross2Icon,
} from '@radix-ui/react-icons';
import {
  Badge,
  Button,
  Flex,
  IconButton,
  Spinner,
  Switch,
  Text,
} from '@radix-ui/themes';

import type { ReactNode } from 'react';
import type { PageVersionSelection } from '@/features/versionComparison/PageVersionComparisonView';

import styles from '@/features/versionComparison/VersionComparisonPage.module.css';

const REVIEW_COMPLETE_LABEL = 'All changes reviewed';

export interface ReviewChangesViewProps {
  label: string;
  comparison: ReactNode;
  onClose: () => void;
  onNavigateToCanvas: () => void;
  onNavigateToReview: () => void;
  reviewIndex: number;
  reviewTotal: number;
  selectedVersion: PageVersionSelection;
  isSelectedForPublishing: boolean;
  isApplyingSelection: boolean;
  canPublish: boolean;
  canPrevious: boolean;
  onSelectedForPublishingChange: (checked: boolean) => void;
  onPrevious: () => void;
  onNext: () => void;
  onApplyVersionSelection: () => void;
}

export const ReviewChangesView = ({
  label,
  comparison,
  onClose,
  onNavigateToCanvas,
  onNavigateToReview,
  reviewIndex,
  reviewTotal,
  selectedVersion,
  isSelectedForPublishing,
  isApplyingSelection,
  canPublish,
  canPrevious,
  onSelectedForPublishingChange,
  onPrevious,
  onNext,
  onApplyVersionSelection,
}: ReviewChangesViewProps) => (
  <div
    className={styles.page}
    data-testid="review-changes-page"
    data-canvas-element="review-changes-page"
  >
    <ReviewHeader
      label={label}
      onClose={onClose}
      onNavigateToCanvas={onNavigateToCanvas}
      onNavigateToReview={onNavigateToReview}
    />
    <div className={styles.content}>{comparison}</div>
    <ReviewFooter
      reviewIndex={reviewIndex}
      reviewTotal={reviewTotal}
      selectedVersion={selectedVersion}
      isSelectedForPublishing={isSelectedForPublishing}
      isApplyingSelection={isApplyingSelection}
      canPublish={canPublish}
      onSelectedForPublishingChange={onSelectedForPublishingChange}
      onPrevious={onPrevious}
      onNext={onNext}
      onApplyVersionSelection={onApplyVersionSelection}
      canPrevious={canPrevious}
    />
  </div>
);

export const ReviewLoadingState = ({
  onClose,
  onNavigateToCanvas = onClose,
  onNavigateToReview = onClose,
}: {
  onClose: () => void;
  onNavigateToCanvas?: () => void;
  onNavigateToReview?: () => void;
}) => (
  <div
    className={styles.page}
    data-testid="review-changes-page"
    data-canvas-element="review-changes-page"
  >
    <ReviewHeader
      label="Loading"
      onClose={onClose}
      onNavigateToCanvas={onNavigateToCanvas}
      onNavigateToReview={onNavigateToReview}
    />
    <Flex align="center" justify="center" className={styles.emptyState}>
      <Spinner />
    </Flex>
  </div>
);

const ReviewHeader = ({
  label,
  onClose,
  onNavigateToCanvas,
  onNavigateToReview,
}: {
  label: string;
  onClose: () => void;
  onNavigateToCanvas: () => void;
  onNavigateToReview: () => void;
}) => (
  <div className={styles.header}>
    <Flex align="center" gap="1" minWidth="0">
      <button
        type="button"
        className={styles.breadcrumbLink}
        onClick={onNavigateToCanvas}
      >
        Canvas
      </button>
      <Text size="1" color="gray">
        /
      </Text>
      <button
        type="button"
        className={styles.breadcrumbLink}
        onClick={onNavigateToReview}
      >
        Review
      </button>
      <Text size="1" color="gray">
        /
      </Text>
      <Text size="1" className={styles.breadcrumbLabel}>
        {label}
      </Text>
      {label !== REVIEW_COMPLETE_LABEL && (
        <Badge color="gray" variant="soft" radius="small">
          Page
        </Badge>
      )}
    </Flex>
    <IconButton
      variant="ghost"
      color="gray"
      highContrast
      aria-label="Close"
      onClick={onClose}
      data-canvas-element="review-changes-close"
    >
      <Cross2Icon />
    </IconButton>
  </div>
);

const ReviewFooter = ({
  reviewIndex,
  reviewTotal,
  selectedVersion,
  isSelectedForPublishing,
  isApplyingSelection,
  canPublish,
  canPrevious,
  onSelectedForPublishingChange,
  onPrevious,
  onNext,
  onApplyVersionSelection,
}: {
  reviewIndex: number;
  reviewTotal: number;
  selectedVersion: PageVersionSelection;
  isSelectedForPublishing: boolean;
  isApplyingSelection: boolean;
  canPublish: boolean;
  canPrevious: boolean;
  onSelectedForPublishingChange: (checked: boolean) => void;
  onPrevious: () => void;
  onNext: () => void;
  onApplyVersionSelection: () => void;
}) => {
  const actionText =
    selectedVersion === 'published'
      ? 'Discard changes'
      : 'Publish selected changes';
  const isActionDisabled =
    isApplyingSelection ||
    !selectedVersion ||
    (selectedVersion === 'new' && !canPublish);

  return (
    <div className={styles.footer}>
      <Flex direction="column" gap="1">
        <Text size="2" color="gray">
          Review {reviewIndex + 1} of {reviewTotal}
        </Text>
        <Text as="label" size="2">
          <Flex align="center" gap="2">
            <Switch
              size="1"
              checked={isSelectedForPublishing}
              onCheckedChange={onSelectedForPublishingChange}
              data-canvas-element="review-changes-select-for-publishing"
            />
            Selected for publishing
          </Flex>
        </Text>
      </Flex>
      <Flex align="center" gap="5">
        <Button
          variant="ghost"
          color="gray"
          disabled={!canPrevious || isApplyingSelection}
          onClick={onPrevious}
          data-canvas-element="review-changes-previous"
        >
          <ArrowLeftIcon />
          Previous
        </Button>
        <Button
          variant="ghost"
          color="blue"
          disabled={isApplyingSelection}
          onClick={onNext}
          data-canvas-element="review-changes-next"
        >
          Next
          <ArrowRightIcon />
        </Button>
        <Button
          onClick={onApplyVersionSelection}
          disabled={isActionDisabled}
          color={selectedVersion === 'published' ? 'red' : undefined}
          data-canvas-element="review-changes-apply-version"
        >
          {selectedVersion ? actionText : 'Action'}
          <Spinner loading={isApplyingSelection} />
        </Button>
      </Flex>
    </div>
  );
};

export const ReviewCompleteState = ({
  selectedCount,
  isPublishing,
  onPublish,
  onClose,
  onPrevious,
  canPrevious = false,
  onNavigateToCanvas = onClose,
  onNavigateToReview = onPrevious ?? onClose,
}: {
  selectedCount: number;
  isPublishing: boolean;
  onPublish: () => void;
  onClose: () => void;
  onPrevious?: () => void;
  canPrevious?: boolean;
  onNavigateToCanvas?: () => void;
  onNavigateToReview?: () => void;
}) => (
  <div className={styles.page} data-testid="review-complete-state">
    <ReviewHeader
      label={REVIEW_COMPLETE_LABEL}
      onClose={onClose}
      onNavigateToCanvas={onNavigateToCanvas}
      onNavigateToReview={onNavigateToReview}
    />
    <Flex
      direction="column"
      align="center"
      justify="center"
      className={styles.emptyState}
      gap="3"
    >
      <CheckCircledIcon className={styles.emptyIcon} />
      <Text size="4" weight="medium">
        {REVIEW_COMPLETE_LABEL}
      </Text>
      <Text size="2">
        You've reviewed all selected changes. You're now ready to publish.
      </Text>
      <Flex align="center" gap="3">
        {onPrevious && (
          <Button
            variant="ghost"
            color="gray"
            onClick={onPrevious}
            disabled={!canPrevious || isPublishing}
          >
            <ArrowLeftIcon />
            Previous
          </Button>
        )}
        <Button
          onClick={onPublish}
          disabled={isPublishing || selectedCount === 0}
        >
          Publish selected changes
          <Spinner loading={isPublishing} />
        </Button>
        <Button variant="outline" onClick={onClose}>
          Close
        </Button>
      </Flex>
    </Flex>
  </div>
);
