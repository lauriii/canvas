import {
  ArrowLeftIcon,
  ArrowRightIcon,
  Cross2Icon,
} from '@radix-ui/react-icons';
import {
  Badge,
  Button,
  Flex,
  IconButton,
  Spinner,
  Text,
} from '@radix-ui/themes';

import type React from 'react';

import styles from '@/features/versionComparison/VersionComparisonPage.module.css';

export interface ConflictResolutionViewProps {
  label: string;
  comparison: React.ReactNode;
  currentIndex: number;
  reviewIndex: number;
  reviewTotal: number;
  unresolvedTotal: number;
  isResolving?: boolean;
  canResolveConflict?: boolean;
  onPrevious: () => void;
  onNext: () => void;
  onResolveConflict: () => void;
  onClose: () => void;
  onNavigateToCanvas: () => void;
  onNavigateToConflict: () => void;
}

export const ConflictResolutionView = ({
  label,
  comparison,
  currentIndex,
  reviewIndex,
  reviewTotal,
  unresolvedTotal,
  isResolving = false,
  canResolveConflict = false,
  onPrevious,
  onNext,
  onResolveConflict,
  onClose,
  onNavigateToCanvas,
  onNavigateToConflict,
}: ConflictResolutionViewProps) => {
  return (
    <div className={styles.page} data-testid="conflict-resolution-page">
      <ConflictHeader
        label={label}
        onClose={onClose}
        onNavigateToCanvas={onNavigateToCanvas}
        onNavigateToConflict={onNavigateToConflict}
      />
      <div className={styles.content}>{comparison}</div>
      <ConflictFooter
        reviewIndex={reviewIndex}
        reviewTotal={reviewTotal}
        currentIndex={currentIndex}
        unresolvedTotal={unresolvedTotal}
        onPrevious={onPrevious}
        onNext={onNext}
        onResolveConflict={onResolveConflict}
        isResolving={isResolving}
        canResolveConflict={canResolveConflict}
      />
    </div>
  );
};

interface ConflictHeaderProps {
  label: string;
  onClose: () => void;
  onNavigateToCanvas: () => void;
  onNavigateToConflict: () => void;
}

const ConflictHeader = ({
  label,
  onClose,
  onNavigateToCanvas,
  onNavigateToConflict,
}: ConflictHeaderProps) => {
  return (
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
          onClick={onNavigateToConflict}
        >
          Conflict
        </button>
        <Text size="1" color="gray">
          /
        </Text>
        <Text size="1" className={styles.breadcrumbLabel}>
          {label}
        </Text>
        <Badge color="gray" variant="soft" radius="small">
          Page
        </Badge>
      </Flex>
      <IconButton
        variant="ghost"
        color="gray"
        highContrast
        aria-label="Close"
        onClick={onClose}
      >
        <Cross2Icon />
      </IconButton>
    </div>
  );
};

interface ConflictFooterProps {
  currentIndex: number;
  reviewIndex: number;
  reviewTotal: number;
  unresolvedTotal: number;
  isResolving: boolean;
  canResolveConflict: boolean;
  onPrevious: () => void;
  onNext: () => void;
  onResolveConflict: () => void;
}

const ConflictFooter = ({
  currentIndex,
  reviewIndex,
  reviewTotal,
  unresolvedTotal,
  isResolving,
  canResolveConflict,
  onPrevious,
  onNext,
  onResolveConflict,
}: ConflictFooterProps) => (
  <div className={styles.footer}>
    <Text size="2" color="gray">
      Review {reviewIndex + 1} of {reviewTotal}
    </Text>
    <Flex align="center" gap="5">
      <Button
        variant="ghost"
        color="gray"
        disabled={currentIndex === 0 || isResolving}
        onClick={onPrevious}
      >
        <ArrowLeftIcon />
        Previous
      </Button>
      <Button
        variant="ghost"
        color="blue"
        disabled={currentIndex >= unresolvedTotal - 1 || isResolving}
        onClick={onNext}
      >
        Next
        <ArrowRightIcon />
      </Button>
      <Button
        onClick={onResolveConflict}
        disabled={isResolving || !canResolveConflict}
      >
        Resolve conflict
        <Spinner loading={isResolving} />
      </Button>
    </Flex>
  </div>
);
