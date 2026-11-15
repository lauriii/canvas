import { useCallback } from 'react';
import { toast } from 'sonner';

import { useAppDispatch } from '@/app/hooks';
import { setInitialized } from '@/features/layout/layoutModelSlice';
import { componentAndLayoutApi } from '@/services/componentAndLayout';
import { useRestorePageRevisionMutation } from '@/services/content';
import { pendingChangesApi } from '@/services/pendingChangesApi';

/**
 * Restores a revision of a page as its draft, and shows it in the editor.
 */
export const useRestorePageRevision = () => {
  const dispatch = useAppDispatch();
  const [restore, { isLoading }] = useRestorePageRevisionMutation();

  const restoreRevision = useCallback(
    async (entityId: string, revisionId: number): Promise<boolean> => {
      try {
        await restore({ entityId, revisionId }).unwrap();
      } catch {
        toast.error('The version could not be restored. Please try again.');
        return false;
      }
      // The editor reads the layout once per page; have it read the draft the
      // restore made.
      dispatch(setInitialized(false));
      dispatch(componentAndLayoutApi.util.invalidateTags([{ type: 'Layout' }]));
      dispatch(
        pendingChangesApi.util.invalidateTags([
          { type: 'PendingChanges', id: 'LIST' },
        ]),
      );
      toast.success('Version restored');
      return true;
    },
    [dispatch, restore],
  );

  return { restoreRevision, isRestoring: isLoading };
};
