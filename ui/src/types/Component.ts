export interface FieldData {
  [key: string]: FieldDataItem;
}

export interface FieldDataItem {
  expression: string;
  sourceType: string;
  sourceTypeSettings?: object;
  default_values: object;
  [x: string | number | symbol]: unknown;
}

export interface Component {
  name: string;
  id: string;
  default_markup: string;
  metadata: object;
  field_data: FieldData;
}

export interface ComponentsList {
  [key: string]: Component;
}
