import { useEffect, useRef } from 'react';
import { useParams } from 'react-router-dom';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { selectPageData } from '@/features/pageData/pageDataSlice';
import { contentApi } from '@/services/content';
import { usePostPreviewMutation } from '@/services/preview';
import { getEntityTitle } from '@/utils/entityTitle';

/**
 * A hook to update the entity title and path alias by watching for changes
 * to either of them in the entity form fields.
 * When either changes, it waits for the success of the POST update sent to the server
 * and when that's successful it invalidates the page/content list cache to ensure
 * updated data is fetched/shown.
 */
function useSyncTitle() {
  const dispatch = useAppDispatch();
  const { entityType } = useParams();
  const entity_form_fields = useAppSelector(selectPageData);
  const title = getEntityTitle(entityType, entity_form_fields);

  // --- Refs for tracking "previous" state & Polling ---
  const previousEntityFormTitle = useRef(title);
  // @todo stop hardcoding `path` after https://drupal.org/i/3503446.
  const previousEntityFormAlias = useRef(entity_form_fields['path[0][alias]']);
  const hasChangedRef = useRef(false);

  const [, { isSuccess }] = usePostPreviewMutation({
    fixedCacheKey: 'editorFramePreview',
  });

  // Track when title or alias changes
  useEffect(() => {
    const currentAlias = entity_form_fields['path[0][alias]'];

    if (
      previousEntityFormTitle.current !== title ||
      previousEntityFormAlias.current !== currentAlias
    ) {
      hasChangedRef.current = true;
      previousEntityFormTitle.current = title;
      previousEntityFormAlias.current = currentAlias;
    }
  }, [title, entity_form_fields]);

  // Invalidate tags when preview is successful and there has been a change
  useEffect(() => {
    if (isSuccess && hasChangedRef.current) {
      dispatch(
        contentApi.util.invalidateTags([{ type: 'Content', id: 'LIST' }]),
      );
      hasChangedRef.current = false;
    }
  }, [dispatch, isSuccess]);
}

export default useSyncTitle;
