import {
  ArrowLeftIcon,
  ArrowRightIcon,
  CheckCircledIcon,
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
  onBack: () => void;
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
  onBack,
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
    <ReviewHeader label={label} onBack={onBack} />
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

export const ReviewLoadingState = ({ onBack }: { onBack: () => void }) => (
  <div className={styles.page} data-testid="review-changes-page">
    <ReviewHeader label="Loading" onBack={onBack} />
    <Flex align="center" justify="center" className={styles.emptyState}>
      <Spinner />
    </Flex>
  </div>
);

// Review is an overlay on the editor, not a separate place, so the header has
// a back button (as extension pages do) instead of a breadcrumb.
const ReviewHeader = ({
  label,
  onBack,
}: {
  label: string;
  onBack: () => void;
}) => (
  <div className={styles.header}>
    <Flex align="center" gap="2" minWidth="0">
      <IconButton
        variant="ghost"
        color="gray"
        aria-label="Go back"
        onClick={onBack}
      >
        <ArrowLeftIcon />
      </IconButton>
      <Text size="1" className={styles.breadcrumbLabel}>
        {label}
      </Text>
      {label !== REVIEW_COMPLETE_LABEL && (
        <Badge color="gray" variant="soft" radius="small">
          Page
        </Badge>
      )}
    </Flex>
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
        >
          <ArrowLeftIcon />
          Previous
        </Button>
        <Button
          variant="ghost"
          color="blue"
          disabled={isApplyingSelection}
          onClick={onNext}
        >
          Next
          <ArrowRightIcon />
        </Button>
        <Button
          onClick={onApplyVersionSelection}
          disabled={isActionDisabled}
          color={selectedVersion === 'published' ? 'red' : undefined}
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
}: {
  selectedCount: number;
  isPublishing: boolean;
  onPublish: () => void;
  onClose: () => void;
  onPrevious?: () => void;
  canPrevious?: boolean;
}) => (
  <div className={styles.page} data-testid="review-complete-state">
    <ReviewHeader label={REVIEW_COMPLETE_LABEL} onBack={onClose} />
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
