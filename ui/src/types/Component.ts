import type { TransformConfig } from '@/utils/transforms';

export interface FieldData {
  [key: string]: FieldDataItem;
}

export interface DefaultValues {
  resolved: object;
  source: object;
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
    format?: string;
  };
  default_values: DefaultValues;
  [x: string | number | symbol]: unknown;
}

interface BaseComponent {
  id: string;
  name: string;
  library: string;
  category: string;
  source: string;
  default_markup: string;
  css: string;
  js_header: string;
  js_footer: string;
  version: string;
  propSources: FieldData;
}

export type libraryTypes =
  | 'dynamic_components'
  | 'primary_components'
  | 'extension_components'
  | 'elements';

// For now, these are only Blocks. Later, it will be more.
export interface DynamicComponent extends BaseComponent {
  library: 'dynamic_components';
}

// JSComponent Interface
export interface JSComponent extends BaseComponent {
  library: 'primary_components';
  source: 'Code component';
  transforms: any[];
}

// PropSourceComponent Interface
export interface PropSourceComponent extends BaseComponent {
  library: 'elements' | 'extension_components';
  propSources: FieldData;
  metadata: {
    slots?: {
      [key: string]: {
        title: string;
        [key: string]: any;
      };
    };
    [key: string]: any;
  };
  transforms: TransformConfig;
}
// Union type for any component
export type XBComponent = DynamicComponent | JSComponent | PropSourceComponent;

// ComponentsList representing the API response
export interface ComponentsList {
  [key: string]: XBComponent;
}

/**
 * Type predicate.
 *
 * @param {XBComponent | undefined} component
 *   Component to test.
 *
 * @return boolean
 *   TRUE if the component has field data.
 *
 * @todo rename this to componentHasPropSources in https://www.drupal.org/project/experience_builder/issues/3504421
 */
export const componentHasFieldData = (
  component: XBComponent | undefined,
): component is PropSourceComponent => {
  return component !== undefined && 'propSources' in component;
};
