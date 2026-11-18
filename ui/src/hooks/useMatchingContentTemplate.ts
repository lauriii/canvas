import { useMemo } from 'react';

import { useAppSelector } from '@/app/hooks';
import { selectPerContentTemplateInfo } from '@/features/layout/layoutModelSlice';
import { useGetContentTemplatesQuery } from '@/services/componentAndLayout';

import type { TemplateViewMode } from '@/services/componentAndLayout';

/**
 * Per-content editing: resolves the content template the edited entity follows.
 *
 * Looks up the template the Layout API names in the templates listing.
 */
const useMatchingContentTemplate = (): TemplateViewMode | undefined => {
  const templateInfo = useAppSelector(selectPerContentTemplateInfo);
  const { data: templates } = useGetContentTemplatesQuery();

  return useMemo(() => {
    if (!templateInfo || !templates) {
      return undefined;
    }
    return templates[templateInfo.entityType]?.bundles?.[templateInfo.bundle]
      ?.viewModes?.[templateInfo.viewMode];
  }, [templates, templateInfo]);
};

export default useMatchingContentTemplate;
