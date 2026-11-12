import type { FieldDataItem } from '@/types/Component';
import type { ClientWidgetContext, ClientWidgetDefinition } from './types';

/**
 * The client widget registry: Drupal field widget plugin id → client widget.
 *
 * The server keeps choosing the widget per prop (`prop_field_definitions`);
 * the client renders its registered counterpart, or falls back to the
 * server-built Drupal widget (the escape hatch) when no client widget is
 * registered for the id.
 *
 * Registration is override-capable public architecture: registering an id
 * that already has a widget replaces it. Registration happens at editor boot;
 * resolution at render time is a synchronous map lookup, so late or absent
 * registration can never block or slow form rendering — an unregistered id
 * simply resolves to the escape hatch.
 *
 * This module deliberately has no imports from the prop panel so it can be
 * used by any consumer (prop panel, code editor, canvas_translate) without
 * cycles.
 */
const registry = new Map<string, ClientWidgetDefinition>();

export function registerClientWidget(
  widgetId: string,
  definition: ClientWidgetDefinition,
): void {
  registry.set(widgetId, definition);
}

export function resolveClientWidget(
  widgetId: string,
): ClientWidgetDefinition | undefined {
  return registry.get(widgetId);
}

export function getRegisteredClientWidgetIds(): string[] {
  return [...registry.keys()];
}

/**
 * Builds the widget context for a prop from cached component metadata.
 */
export function buildClientWidgetContext(
  propName: string,
  componentId: string,
  componentVersion: string,
  fieldData: FieldDataItem,
): ClientWidgetContext {
  return {
    propName,
    componentId,
    componentVersion,
    jsonSchema: (fieldData.jsonSchema ?? {
      type: 'string',
    }) as ClientWidgetContext['jsonSchema'],
    sourceTypeSettings: fieldData.sourceTypeSettings ?? {},
    cardinality: fieldData.sourceTypeSettings?.cardinality ?? 1,
    required: Boolean(fieldData.required),
    fieldData,
  };
}

export interface PropFormsSettings {
  native: boolean;
  disabledWidgets: string[];
}

/**
 * Resolves the client widget a prop renders with, honoring the site-wide
 * kill switch, the per-widget-id disable list, and widget eligibility.
 * Returns undefined when the prop must render via the escape hatch.
 */
export function resolveNativeWidgetForProp(
  context: ClientWidgetContext,
  settings: PropFormsSettings,
): ClientWidgetDefinition | undefined {
  if (!settings.native) {
    return undefined;
  }
  const widgetId = context.fieldData.field_widget;
  if (!widgetId || settings.disabledWidgets.includes(widgetId)) {
    return undefined;
  }
  const definition = registry.get(widgetId);
  if (!definition) {
    return undefined;
  }
  // Multi-value props stay native only when the widget renders its own
  // multi-value UI (e.g. the media widget's selection list, multi-selects);
  // otherwise they keep the server-built widget's add/remove/reorder UX via
  // the escape hatch.
  const isMultiValueProp =
    context.cardinality !== 1 || context.jsonSchema.type === 'array';
  if (isMultiValueProp && !definition.handlesMultipleValues) {
    return undefined;
  }
  if (definition.isEligible && !definition.isEligible(context)) {
    return undefined;
  }
  return definition;
}
