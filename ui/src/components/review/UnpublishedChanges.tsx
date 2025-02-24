import { useCallback, useEffect, useState } from 'react';
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
import { useLazyGetLayoutByIdQuery } from '@/services/componentAndLayout';
import {
  selectEntityId,
  selectEntityType,
} from '@/features/configuration/configurationSlice';
import { findInChanges } from '@/utils/function-utils';
import { selectLayout, selectModel } from '@/features/layout/layoutModelSlice';
import { selectPageData } from '@/features/pageData/pageDataSlice';

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
  const entityId = useAppSelector(selectEntityId);
  const entityType = useAppSelector(selectEntityType);
  const [refetchLayout] = useLazyGetLayoutByIdQuery();
  const dispatch = useAppDispatch();
  const { showBoundary } = useErrorBoundary();
  const layout = useAppSelector(selectLayout);
  const model = useAppSelector(selectModel);
  const entity_form_fields = useAppSelector(selectPageData);

  useEffect(() => {
    if (error) {
      showBoundary(error);
    }
  }, [error, showBoundary]);

  const manualRefetch = useCallback(() => {
    // only refetch the list of pending changes if this entity is not already in the list.
    if (changes && !findInChanges(changes, entityId, entityType)) {
      refetch();
    }
  }, [changes, entityId, entityType, refetch]);

  // if the layout, model or form fields change, immediately check for pending changes
  useEffect(() => {
    manualRefetch();
  }, [layout, model, entity_form_fields, manualRefetch]);

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

  const onPublishClick = async () => {
    if (changes) {
      const isCurrentChanged = findInChanges(changes, entityId, entityType);
      await publishAllChanges(changes);

      // if the list of changes contained the current entity, then re-fetch it to get the new isPublished/isNew values.
      if (isCurrentChanged) {
        refetchLayout(entityId);
      }
    }
  };

  const getIconType = (entityType: string) => {
    if (entityType === 'page_region') {
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
      isFetching={isFetching}
      changes={changesWithIcon}
      errors={errorResponse}
      onOpenChangeCallback={onOpenChangeHandler}
      onPublishClick={onPublishClick}
      isPublishing={isPublishing}
    />
  );
};

export default UnpublishedChanges;
