import { useParams } from 'react-router';

import { useAppSelector } from '@/app/hooks';
import { selectPageData } from '@/features/pageData/pageDataSlice';
import { getEntityTitle } from '@/utils/entityTitle';

/**
 * Centralized hook to get the entity title from form fields.
 * Wraps the getEntityTitle utility for use in React components.
 */
export function useEntityTitle(): string | undefined {
  const { entityType } = useParams();
  const entityFormFields = useAppSelector(selectPageData);

  return getEntityTitle(entityType, entityFormFields) || '';
}
