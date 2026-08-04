import { useCallback } from 'react';

import { useAppDispatch } from '@/app/hooks';
import { setUpdatePreview } from '@/features/layout/layoutModelSlice';
import { setInitialPageData } from '@/features/pageData/pageDataSlice';
import { brandKitApi } from '@/services/brandKit';
import { componentAndLayoutApi } from '@/services/componentAndLayout';
import { contentApi } from '@/services/content';
import { usePublishAllPendingChangesMutation } from '@/services/pendingChangesApi';
import { findInChanges } from '@/utils/function-utils';

import type { PendingChanges } from '@/services/pendingChangesApi';
import type { UnpublishedChange } from '@/types/Review';

interface PublishPendingChangesOptions {
  currentEntityId?: string;
  currentEntityType?: string;
  entityFormFields?: Record<string, unknown>;
}

const buildPendingChangesPayload = (
  selectedChanges: UnpublishedChange[],
): PendingChanges =>
  selectedChanges.reduce((acc, change) => {
    acc[change.pointer] = {
      entity_type: change.entity_type,
      entity_id: change.entity_id,
      data_hash: change.data_hash,
      langcode: change.langcode,
      owner: change.owner,
      label: change.label,
      updated: change.updated,
    };
    return acc;
  }, {} as PendingChanges);

export const usePublishPendingChanges = ({
  currentEntityId,
  currentEntityType,
  entityFormFields,
}: PublishPendingChangesOptions = {}) => {
  const dispatch = useAppDispatch();
  const [publishAllChanges, publishState] =
    usePublishAllPendingChangesMutation();

  const publishPendingChanges = useCallback(
    async (selectedChanges: UnpublishedChange[]): Promise<boolean> => {
      if (!selectedChanges?.length) {
        return false;
      }

      const changesToPublish = buildPendingChangesPayload(selectedChanges);
      const isCurrentChanged = findInChanges(
        changesToPublish,
        currentEntityId,
        currentEntityType,
      );
      const changedCodeComponentIds = Object.values(changesToPublish)
        .filter((change) => change.entity_type === 'js_component')
        .map((change) => change.entity_id);
      const changedBrandKitIds = Object.values(changesToPublish)
        .filter((change) => change.entity_type === 'brand_kit')
        .map((change) => String(change.entity_id));

      try {
        // The workspace is the unit of publish: the request takes no item
        // selection. $selectedChanges only scopes the cache cleanup below.
        await publishAllChanges().unwrap();
      } catch {
        // Error state is handled in pendingChangesApi.publishAllPendingChanges.
        return false;
      }

      if (isCurrentChanged && currentEntityId && currentEntityType) {
        // Update the isPublished and isNew status.
        dispatch(
          componentAndLayoutApi.util.updateQueryData(
            'getPageLayout',
            { entityId: currentEntityId, entityType: currentEntityType },
            (draft) => {
              draft.isPublished = true;
              draft.isNew = false;
            },
          ),
        );

        // Pause updating the preview/POSTing to Drupal for this action.
        dispatch(setUpdatePreview(false));

        // Keep the current page timestamp aligned so later autosaves are not
        // treated as stale after publishing.
        if (entityFormFields && 'changed' in entityFormFields) {
          dispatch(setUpdatePreview(false));
          dispatch(
            setInitialPageData({
              ...entityFormFields,
              changed: Math.floor(new Date().getTime() / 1000),
            }),
          );
        }
      }

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

      if (changedBrandKitIds.length) {
        dispatch(
          brandKitApi.util.invalidateTags([
            ...changedBrandKitIds.flatMap((id) => [
              { type: 'BrandKits' as const, id },
              { type: 'BrandKitsAutoSave' as const, id },
            ]),
            { type: 'BrandKits' as const, id: 'LIST' },
          ]),
        );
      }

      return true;
    },
    [
      currentEntityId,
      currentEntityType,
      dispatch,
      entityFormFields,
      publishAllChanges,
    ],
  );

  return [publishPendingChanges, publishState] as const;
};
