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
      {/* The breadcrumb items and the entity type badge are single words, so
          they carry a context separating them from the same words used as verbs
          or nouns elsewhere. */}
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
          onClick={onNavigateToConflict}
        >
          {Drupal.t('Conflict', {}, { context: 'Canvas breadcrumb' })}
        </button>
        <Text size="1" color="gray">
          /
        </Text>
        <Text size="1" className={styles.breadcrumbLabel}>
          {label}
        </Text>
        <Badge color="gray" variant="soft" radius="small">
          {Drupal.t('Page', {}, { context: 'Canvas entity type' })}
        </Badge>
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
      {Drupal.t('Review !current of !total', {
        '!current': reviewIndex + 1,
        '!total': reviewTotal,
      })}
    </Text>
    <Flex align="center" gap="5">
      <Button
        variant="ghost"
        color="gray"
        disabled={currentIndex === 0 || isResolving}
        onClick={onPrevious}
      >
        <ArrowLeftIcon />
        {Drupal.t('Previous')}
      </Button>
      <Button
        variant="ghost"
        color="blue"
        disabled={currentIndex >= unresolvedTotal - 1 || isResolving}
        onClick={onNext}
      >
        {Drupal.t('Next')}
        <ArrowRightIcon />
      </Button>
      <Button
        onClick={onResolveConflict}
        disabled={isResolving || !canResolveConflict}
      >
        {Drupal.t('Resolve conflict')}
        <Spinner loading={isResolving} />
      </Button>
    </Flex>
  </div>
);
