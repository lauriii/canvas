/**
 * Serializes the Redux layout into the dev chat's current_layout parameter.
 *
 * @see \Drupal\canvas_dev_ai\Controller\CanvasDevAiBuilder::render()
 */

import type {
  ComponentModels,
  ComponentNode,
  RegionNode,
  ResolvedValues,
} from '@/features/layout/layoutModelSlice';

interface CurrentLayoutComponent {
  name: string;
  uuid: ComponentNode['uuid'];
  props: ResolvedValues;
  slots?: Record<string, { components: CurrentLayoutComponent[] }>;
}

interface CurrentLayout {
  regions: Record<
    string,
    { nodePathPrefix: number[]; components: CurrentLayoutComponent[] }
  >;
}

// Recursively describes each component by name, uuid and resolved prop values,
// nesting slot children under their slot id.
const processComponents = (
  components: ComponentNode[] | undefined,
  model: ComponentModels,
): CurrentLayoutComponent[] => {
  if (!components) return [];
  return components.map((component) => {
    const transformedComponent: CurrentLayoutComponent = {
      name: component.type.split('@')[0],
      uuid: component.uuid,
      props: model[component.uuid]?.resolved ?? {},
    };
    // Handle slots if they exist
    if (component.slots && component.slots.length > 0) {
      transformedComponent.slots = Object.fromEntries(
        component.slots.map((slot) => [
          slot.id,
          { components: processComponents(slot.components, model) },
        ]),
      );
    }
    return transformedComponent;
  });
};

/**
 * Builds the current_layout request parameter from a layout tree and its model.
 *
 * @see \Drupal\canvas_ai\CanvasAiPageBuilderHelper::getComponentsByUuid()
 * @see \Drupal\canvas_ai\CanvasAiPageBuilderHelper::getAvailableRegions()
 */
export const buildCurrentLayout = (
  layout: RegionNode[],
  model: ComponentModels,
): CurrentLayout => {
  const regions: CurrentLayout['regions'] = {};
  layout.forEach((region, regionIndex) => {
    regions[region.id] = {
      nodePathPrefix: [regionIndex],
      components: processComponents(region.components, model),
    };
  });
  return { regions };
};
