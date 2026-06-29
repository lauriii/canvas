import path from 'path';

import type { DiscoveredContentTemplate } from '@drupal-canvas/discovery';

function contentTemplateFileName(
  template: DiscoveredContentTemplate | undefined,
): string | undefined {
  const templatePath = template?.relativePath || template?.path;
  return templatePath ? path.basename(templatePath) : undefined;
}

function contentTemplateFallbackName(
  template: DiscoveredContentTemplate | undefined,
): string {
  return (
    contentTemplateFileName(template) ||
    template?.slug ||
    template?.name ||
    'unknown'
  );
}

export function contentTemplateResultName(
  templateLabel: string | undefined,
  template: DiscoveredContentTemplate | undefined,
  options: { includeFileName?: boolean } = {},
): string {
  const fileName = contentTemplateFileName(template);
  const label = templateLabel || template?.label;

  if (label) {
    return options.includeFileName && fileName
      ? `${label} (${fileName})`
      : label;
  }

  return contentTemplateFallbackName(template);
}
