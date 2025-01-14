import { useEffect } from 'react';
import { useErrorBoundary } from 'react-error-boundary';
import { isEmpty } from 'lodash';
import type { PendingChange } from '@/services/pendingChangesApi';
import { useGetAllPendingChangesQuery } from '@/services/pendingChangesApi';
import PublishReview, { IconType } from '@/components/review/PublishReview';
import { useAppSelector } from '@/app/hooks';
import { selectPostPreviewCompletedStatus } from '@/components/review/PublishReview.slice';

const REFETCH_INTERVAL_MS = 10000;

const UnpublishedChanges = () => {
  const postPreviewCompleted = useAppSelector(selectPostPreviewCompletedStatus);
  const {
    data: changes,
    error,
    refetch,
  } = useGetAllPendingChangesQuery(undefined, {
    pollingInterval: REFETCH_INTERVAL_MS,
    skipPollingIfUnfocused: true,
    skip: !postPreviewCompleted,
  });

  const { showBoundary } = useErrorBoundary();

  useEffect(() => {
    if (error) {
      showBoundary(error);
    }
  }, [error, showBoundary]);

  const onOpenChangeHandler = (): void => {
    refetch();
  };

  const getIconType = (entityType: string) => {
    if (entityType === 'page_template') {
      return IconType.CUBE;
    }
    if (entityType === 'page') {
      return IconType.FILE;
    }
    return IconType.COMPONENT1;
  };

  const pendingChanges = !isEmpty(changes)
    ? (Object.values(changes) as PendingChange[])
    : [];

  const changesWithIcon = pendingChanges.map((change) => {
    return {
      ...change,
      icon: getIconType(change.entity_type),
    };
  });

  return (
    <PublishReview
      changes={changesWithIcon}
      onOpenChangeCallback={onOpenChangeHandler}
    />
  );
};

export default UnpublishedChanges;
