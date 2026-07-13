import { useMemo } from 'react';
import { useParams } from 'react-router';

import { useAppSelector } from '@/app/hooks';
import { selectExposedSlots } from '@/features/layout/layoutModelSlice';
import { useGetContentTemplatesQuery } from '@/services/componentAndLayout';

import type { TemplateViewMode } from '@/services/componentAndLayout';

/**
 * Per-content editing: resolves the content template the edited entity follows.
 *
 * The client is not given the bundle/view mode directly in per-content mode, so
 * the template is found in the content-templates listing by matching the
 * editor's exposed-slot aliases against each template's exposed slots. Used to
 * surface, and jump to, the template being overridden.
 */
const useMatchingContentTemplate = (): TemplateViewMode | undefined => {
  const { entityType } = useParams();
  const exposedSlots = useAppSelector(selectExposedSlots);
  const { data: templates } = useGetContentTemplatesQuery();

  return useMemo(() => {
    if (!templates || !entityType || !templates[entityType]) {
      return undefined;
    }
    const aliases = Object.keys(exposedSlots ?? {});
    if (aliases.length === 0) {
      return undefined;
    }
    for (const bundle of Object.values(templates[entityType].bundles)) {
      for (const viewMode of Object.values(bundle.viewModes)) {
        const exposed = Object.keys(viewMode.exposed_slots ?? {});
        if (
          exposed.length > 0 &&
          aliases.every((alias) => exposed.includes(alias))
        ) {
          return viewMode;
        }
      }
    }
    return undefined;
  }, [templates, entityType, exposedSlots]);
};

export default useMatchingContentTemplate;
