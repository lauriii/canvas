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
import { componentAndLayoutApi } from '@/services/componentAndLayout';
import {
  selectEntityId,
  selectEntityType,
} from '@/features/configuration/configurationSlice';
import { findInChanges } from '@/utils/function-utils';
import {
  selectLayout,
  selectModel,
  setUpdatePreview,
} from '@/features/layout/layoutModelSlice';
import {
  selectPageData,
  setInitialPageData,
} from '@/features/pageData/pageDataSlice';
import { contentApi } from '@/services/content';

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
      const changedCodeComponentIds = Object.values(changes)
        .filter((change) => change.entity_type === 'js_component')
        .map((change) => change.entity_id);

      await publishAllChanges(changes);

      if (isCurrentChanged) {
        // Update the isPublished and isNew status.
        dispatch(
          componentAndLayoutApi.util.updateQueryData(
            'getLayoutById',
            entityId,
            (draft) => {
              draft.isPublished = true;
              draft.isNew = false;
            },
          ),
        );
        // Avoid the "The content has either been modified by another user, or
        // you have already submitted modifications. As a result, your changes
        // cannot be saved" error.
        if ('changed' in entity_form_fields) {
          // Pause updating the preview/POSTing to Drupal for this action.
          dispatch(setUpdatePreview(false));
          dispatch(
            setInitialPageData({
              ...entity_form_fields,
              changed: Math.floor(new Date().getTime() / 1000),
            }),
          );
        }
      }

      // After publishing, the list of other pages might be out of date where the pages' title/alias has been updated.
      dispatch(
        contentApi.util.invalidateTags([{ type: 'Content', id: 'LIST' }]),
      );

      if (changedCodeComponentIds.length) {
        // Invalidate cache of all changed code component entities. This is
        // critical to prevent data loss, which would otherwise occur in the
        // following scenario:
        // 1. A code component change is auto-saved, then published.
        // 2. As a result, the auto-save entry gets deleted on the backend.
        // 3. The auto-save that occurred previously invalidated the auto-save
        //    query cache, so fetching data for the code component will correctly
        //    see the 204 response that is now returned.
        // 4. That will cause a fallback to the canonical source of the config
        //    entity. This is why we need to invalidate the cache for those.
        //    In the absence of this, a stale version would be returned, which
        //    would get auto-saved if anything changes, resulting to the loss of
        //    changes in step 1. E.g. with newly created and first-time published
        //    code components, this would wipe out all data.
        dispatch(
          componentAndLayoutApi.util.invalidateTags(
            changedCodeComponentIds.map((id) => ({
              type: 'CodeComponents',
              id,
            })),
          ),
        );
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
      return IconType.JS_COMPONENT;
    }
    if (entityType === 'xb_asset_library') {
      return IconType.ASSET_LIBRARY;
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
