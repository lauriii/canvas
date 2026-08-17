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

const REVIEW_COMPLETE_LABEL = Drupal.t('All changes reviewed');

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
  <div className={styles.page} data-testid="review-changes-page">
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
  <div className={styles.page} data-testid="review-changes-page">
    <ReviewHeader
      label={Drupal.t('Loading')}
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
    {/* The breadcrumb items and the entity type badge are single words, so they
        carry a context separating them from the same words used as verbs or
        nouns elsewhere. */}
    <Flex align="center" gap="1" minWidth="0">
      <button
        type="button"
        className={styles.breadcrumbLink}
        onClick={onNavigateToCanvas}
      >
        {Drupal.t('Canvas', {}, { context: 'Canvas breadcrumb' })}
      </button>
      <Text size="1" color="gray">
        /
      </Text>
      <button
        type="button"
        className={styles.breadcrumbLink}
        onClick={onNavigateToReview}
      >
        {Drupal.t('Review', {}, { context: 'Canvas breadcrumb' })}
      </button>
      <Text size="1" color="gray">
        /
      </Text>
      <Text size="1" className={styles.breadcrumbLabel}>
        {label}
      </Text>
      {label !== REVIEW_COMPLETE_LABEL && (
        <Badge color="gray" variant="soft" radius="small">
          {Drupal.t('Page', {}, { context: 'Canvas entity type' })}
        </Badge>
      )}
    </Flex>
    <IconButton
      variant="ghost"
      color="gray"
      highContrast
      aria-label={Drupal.t('Close')}
      onClick={onClose}
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
      ? Drupal.t('Discard changes')
      : Drupal.t('Publish selected changes');
  const isActionDisabled =
    isApplyingSelection ||
    !selectedVersion ||
    (selectedVersion === 'new' && !canPublish);

  return (
    <div className={styles.footer}>
      <Flex direction="column" gap="1">
        <Text size="2" color="gray">
          {Drupal.t('Review !current of !total', {
            '!current': reviewIndex + 1,
            '!total': reviewTotal,
          })}
        </Text>
        <Text as="label" size="2">
          <Flex align="center" gap="2">
            <Switch
              size="1"
              checked={isSelectedForPublishing}
              onCheckedChange={onSelectedForPublishingChange}
            />
            {Drupal.t('Selected for publishing')}
          </Flex>
        </Text>
      </Flex>
      <Flex align="center" gap="5">
        <Button
          variant="ghost"
          color="gray"
          disabled={!canPrevious || isApplyingSelection}
          onClick={onPrevious}
        >
          <ArrowLeftIcon />
          {Drupal.t('Previous')}
        </Button>
        <Button
          variant="ghost"
          color="blue"
          disabled={isApplyingSelection}
          onClick={onNext}
        >
          {Drupal.t('Next')}
          <ArrowRightIcon />
        </Button>
        <Button
          onClick={onApplyVersionSelection}
          disabled={isActionDisabled}
          color={selectedVersion === 'published' ? 'red' : undefined}
        >
          {selectedVersion ? actionText : Drupal.t('Action')}
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
        {Drupal.t(
          "You've reviewed all selected changes. You're now ready to publish.",
        )}
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
            {Drupal.t('Previous')}
          </Button>
        )}
        <Button
          onClick={onPublish}
          disabled={isPublishing || selectedCount === 0}
        >
          {Drupal.t('Publish selected changes')}
          <Spinner loading={isPublishing} />
        </Button>
        <Button variant="outline" onClick={onClose}>
          {Drupal.t('Close')}
        </Button>
      </Flex>
    </Flex>
  </div>
);
