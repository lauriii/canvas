export interface FieldData {
  [key: string]: FieldDataItem;
}

export interface FieldDataItem {
  expression: string;
  sourceType: string;
  sourceTypeSettings?: {
    storage?: object;
  };
  jsonSchema?: {
    properties?: object;
    enum?: any[];
  };
  default_values: object;
  [x: string | number | symbol]: unknown;
}

export interface Component {
  name: string;
  id: string;
  default_markup: string;
  css: string;
  js_header: string;
  js_footer: string;
  // `metadata` is specific to the SDC Component type.
  // @see https://www.drupal.org/project/experience_builder/issues/3475584
  metadata?: {
    slots?: {
      [key: string]: {
        title: string;
        [key: string]: any;
      };
    };
    [key: string]: any;
  };
  // `field_data` is specific to the SDC Component type.
  // @see https://www.drupal.org/project/experience_builder/issues/3475584
  field_data?: FieldData;
  source: string;
}

export interface ComponentsList {
  [key: string]: Component;
}
