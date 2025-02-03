import { useEffect, useState } from 'react';
import { useErrorBoundary } from 'react-error-boundary';
import { isEmpty } from 'lodash';
import type { PendingChange } from '@/services/pendingChangesApi';
import { pendingChangesApi } from '@/services/pendingChangesApi';
import { CONFLICT_CODE } from '@/services/pendingChangesApi';
import {
  useGetAllPendingChangesQuery,
  usePublishAllPendingChangesMutation,
} from '@/services/pendingChangesApi';
import PublishReview, { IconType } from '@/components/review/PublishReview';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  selectPreviousPendingChanges,
  selectConflicts,
  setConflicts,
  setPreviousPendingChanges,
  selectErrors,
} from '@/components/review/PublishReview.slice';

const REFETCH_INTERVAL_MS = 10000;

const UnpublishedChanges = () => {
  const previousPendingChanges = useAppSelector(selectPreviousPendingChanges);
  const conflicts = useAppSelector(selectConflicts);
  const errorResponse = useAppSelector(selectErrors);
  const [publishAllChanges, { isLoading: isPublishing }] =
    usePublishAllPendingChangesMutation();
  const [pollingInterval, setPollingInterval] =
    useState<number>(REFETCH_INTERVAL_MS);
  const {
    data: changes,
    error,
    refetch,
    isFetching,
  } = useGetAllPendingChangesQuery(undefined, {
    pollingInterval: pollingInterval,
    skipPollingIfUnfocused: true,
  });
  const dispatch = useAppDispatch();

  const { showBoundary } = useErrorBoundary();

  useEffect(() => {
    if (error) {
      showBoundary(error);
    }
  }, [error, showBoundary]);

  useEffect(() => {
    if (previousPendingChanges) refetch();
  }, [previousPendingChanges, refetch]);

  const onOpenChangeHandler = (open: boolean): void => {
    if (open) {
      refetch().then(() => {
        setPollingInterval(0);
      });
    } else {
      setPollingInterval(REFETCH_INTERVAL_MS);
    }
  };

  const onPublishClick = () => {
    if (changes) {
      publishAllChanges(changes);
    }
  };

  const getIconType = (entityType: string) => {
    if (entityType === 'page_template') {
      return IconType.CUBE;
    }
    if (entityType === 'xb_page') {
      return IconType.FILE;
    }
    if (entityType === 'js_component') {
      return IconType.COMPONENT1;
    }
    return IconType.CMS;
  };

  if (!isFetching && conflicts && conflicts.length) {
    window.setTimeout(() => {
      conflicts.forEach((conflict) => {
        if (conflict.code === CONFLICT_CODE.UNEXPECTED) {
          dispatch(
            pendingChangesApi.util.updateQueryData(
              'getAllPendingChanges',
              undefined,
              (draft) => {
                if (previousPendingChanges) {
                  draft[conflict.source.pointer] = {
                    ...previousPendingChanges[conflict.source.pointer],
                    hasConflict: true,
                  };
                }
              },
            ),
          );
        }
        if (conflict.code === CONFLICT_CODE.EXPECTED) {
          dispatch(
            pendingChangesApi.util.updateQueryData(
              'getAllPendingChanges',
              undefined,
              (draft) => {
                if (draft[conflict.source.pointer]) {
                  draft[conflict.source.pointer].hasConflict = true;
                }
              },
            ),
          );
        }
      });
      dispatch(setConflicts());
      dispatch(setPreviousPendingChanges());
    }, 100);
  }

  const pendingChanges = !isEmpty(changes)
    ? (Object.values(changes) as PendingChange[])
    : [];

  const changesWithIcon = pendingChanges
    .map((change) => {
      return {
        ...change,
        icon: getIconType(change.entity_type),
      };
    })
    .sort((a, b) => b.updated - a.updated);

  return (
    <PublishReview
      changes={changesWithIcon}
      errors={errorResponse}
      onOpenChangeCallback={onOpenChangeHandler}
      onPublishClick={onPublishClick}
      isPublishing={isPublishing}
    />
  );
};

export default UnpublishedChanges;
