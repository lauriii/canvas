import { getCanvasSettings } from '@/utils/drupal-globals';

/**
 * Synchronously extracts the entity title from form fields.
 * This is a pure utility function that doesn't depend on React hooks.
 */
export function getEntityTitle(
  entityType: string | undefined,
  entityFormFields: Record<string, any>,
): string | undefined {
  if (!entityType) return undefined;

  const canvasSettings = getCanvasSettings();
  const titleLabel =
    canvasSettings.entityTypeKeys[entityType]?.label || 'title';

  return entityFormFields[`${titleLabel}[0][value]`] || undefined;
}
