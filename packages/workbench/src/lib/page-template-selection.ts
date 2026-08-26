import type { DiscoveredPageTemplate } from './discovery-client';

/** Selects the enabled page template explicitly assigned to content or the default. */
export function selectPageTemplate(
  pageTemplates: DiscoveredPageTemplate[],
  selectedId: string | null,
): DiscoveredPageTemplate | null {
  const selected = selectedId
    ? pageTemplates.find((pageTemplate) => pageTemplate.id === selectedId)
    : pageTemplates.find((pageTemplate) => pageTemplate.isDefault);

  return selected && selected.status !== false ? selected : null;
}
