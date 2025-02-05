import type { TransformConfig } from '@/utils/transforms';

export interface FieldData {
  [key: string]: FieldDataItem;
}

export interface FieldDataItem {
  expression: string;
  sourceType: string;
  sourceTypeSettings?: {
    storage?: object;
    instance?: object;
  };
  jsonSchema?: {
    type: 'number' | 'integer' | 'string' | 'boolean' | 'array' | 'object';
    properties?: object;
    enum?: any[];
  };
  // @todo Also split this into 'source' and 'resolved' in https://www.drupal.org/i/3493943 — e.g. in the
  // case of a media image reference the source value would be the media target
  // ID, whilst the resolved values would be a URI to the image.
  default_values: object;
  [x: string | number | symbol]: unknown;
}

export interface SimpleComponent {
  name: string;
  id: string;
  default_markup: string;
  css: string;
  js_header: string;
  js_footer: string;
  // The source plugin label.
  source: string;
}

export interface PropSourceComponent extends SimpleComponent {
  // @todo rename this to propSources - https://www.drupal.org/project/experience_builder/issues/3504421
  field_data: FieldData;
  metadata: {
    slots?: {
      [key: string]: {
        title: string;
        [key: string]: any;
      };
    };
    [key: string]: any;
  };
  source: string;
  transforms: TransformConfig;
}

export type Component = PropSourceComponent | SimpleComponent;

export interface ComponentsList {
  [key: string]: Component;
}

/**
 * Type predicate.
 *
 * @param {Component | undefined} component
 *   Component to test.
 *
 * @return boolean
 *   TRUE if the component has field data.
 *
 * @todo rename this to componentHasPropSources in https://www.drupal.org/project/experience_builder/issues/3504421
 */
export const componentHasFieldData = (
  component: Component | undefined,
): component is PropSourceComponent => {
  return component !== undefined && 'field_data' in component;
};
