import { useParams } from 'react-router';

import { useAppSelector } from '@/app/hooks';
import { selectPageData } from '@/features/pageData/pageDataSlice';
import { selectSnapshotTitle } from '@/features/pagePreview/previewSlice';
import { getEntityTitle } from '@/utils/entityTitle';

/**
 * Centralized hook to get the entity title from form fields.
 * Wraps the getEntityTitle utility for use in React components.
 * Returns the translated snapshot title when in language preview mode.
 */
export function useEntityTitle(): string | undefined {
  const { entityType } = useParams();
  const entityFormFields = useAppSelector(selectPageData);
  const snapshotTitle = useAppSelector(selectSnapshotTitle);

  // Prefer the snapshot title (set during language preview) over the
  // default-language page data title.
  if (snapshotTitle) {
    return snapshotTitle;
  }

  return getEntityTitle(entityType, entityFormFields) || '';
}
