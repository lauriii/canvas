import { useMemo } from 'react';

import { useAppSelector } from '@/app/hooks';
import { selectPerContentTemplateInfo } from '@/features/layout/layoutModelSlice';
import { useGetContentTemplatesQuery } from '@/services/componentAndLayout';

import type { TemplateViewMode } from '@/services/componentAndLayout';

/**
 * Per-content editing: resolves the content template the edited entity follows.
 *
 * The Layout API names the applicable template (entity type, bundle, view
 * mode) directly, so the templates listing is only consulted for that exact
 * entry (labels, suggested preview entity). Used to surface, and jump to, the
 * template being overridden.
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
