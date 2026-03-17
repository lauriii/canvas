import type derivedPropTypes from '@/features/code-editor/component-data/derivedPropTypes';

export interface DataFetch {
  id: string;
  data: any;
  error: boolean;
}

export interface CodeComponent {
  machineName: string;
  name: string;
  status: boolean;
  props: CodeComponentProp[];
  required: string[];
  slots: any[];
  sourceCodeJs: string;
  sourceCodeCss: string;
  compiledJs: string;
  compiledCss: string;
  importedJsComponents: string[];
  dataFetches: {
    [key: string]: DataFetch;
  };
  dataDependencies: DataDependencies;
}

export interface DataDependencies {
  drupalSettings?: Array<string>;
  urls?: Array<string>;
}

export interface CodeComponentSerialized extends Omit<
  CodeComponent,
  'props' | 'slots' | 'dataFetches'
> {
  props: Record<string, CodeComponentPropSerialized>;
  slots: Record<string, CodeComponentSlotSerialized>;
  dataDependencies: DataDependencies;
  links?: Record<string, string>;
}

/**
 * Constants for ValueMode.
 */
export const VALUE_MODE_LIMITED = 'limited';
export const VALUE_MODE_UNLIMITED = 'unlimited';

/**
 * Mode for handling multiple values in array props.
 * - VALUE_MODE_LIMITED: Fixed number of values (defined by limitedCount)
 * - VALUE_MODE_UNLIMITED: Dynamic number of values with add/remove capabilities
 */
export type ValueMode = typeof VALUE_MODE_LIMITED | typeof VALUE_MODE_UNLIMITED;

export interface CodeComponentPropEnumItem {
  label: string;
  value: string | number;
}

export interface CodeComponentProp {
  id: string;
  name: string;
  type: 'string' | 'integer' | 'number' | 'boolean' | 'object' | 'array';
  enum?: CodeComponentPropEnumItem[];
  example?:
    | string
    | boolean
    | string[]
    | number[]
    | CodeComponentPropImageExample
    | CodeComponentPropImageExample[]
    | CodeComponentPropVideoExample
    | CodeComponentPropVideoExample[];
  $ref?: string;
  format?: string;
  derivedType: (typeof derivedPropTypes)[number]['type'] | null;
  contentMediaType?: string;
  'x-formatting-context'?: string;
  allowMultiple?: boolean;
  valueMode?: ValueMode;
  limitedCount?: number;
  items?: {
    type: 'string' | 'integer' | 'number' | 'boolean' | 'object';
    format?: string;
    contentMediaType?: string;
    'x-formatting-context'?: string;
    $ref?: string;
    enum?: (string | number)[];
    'meta:enum'?: Record<
      CodeComponentPropEnumItem['value'],
      CodeComponentPropEnumItem['label']
    >;
  };
}

export interface CodeComponentPropImageExample {
  src: string;
  width: number;
  height: number;
  alt: string;
}

export interface CodeComponentPropSerialized {
  title: string;
  type: 'string' | 'integer' | 'number' | 'boolean' | 'object' | 'array';
  enum?: (string | number)[];
  'meta:enum'?: Record<
    CodeComponentPropEnumItem['value'],
    CodeComponentPropEnumItem['label']
  >;
  examples?: (
    | string
    | number
    | boolean
    | string[]
    | number[]
    | CodeComponentPropImageExample
    | CodeComponentPropImageExample[]
    | CodeComponentPropVideoExample
    | CodeComponentPropVideoExample[]
  )[];
  $ref?: string;
  format?: string;
  contentMediaType?: string;
  'x-formatting-context'?: string;
  maxItems?: number;
  items?: {
    type: 'string' | 'integer' | 'number' | 'boolean' | 'object';
    format?: string;
    contentMediaType?: string;
    'x-formatting-context'?: string;
    $ref?: string;
    enum?: (string | number)[];
    'meta:enum'?: Record<
      CodeComponentPropEnumItem['value'],
      CodeComponentPropEnumItem['label']
    >;
  };
}

export interface CodeComponentSlot {
  id: string;
  name: string;
  example?: string;
}

export interface CodeComponentSlotSerialized {
  title: string;
  examples?: string[];
}

export type CodeComponentPropPreviewValue =
  | string
  | number
  | boolean
  | string[]
  | number[]
  | CodeComponentPropImageExample[]
  | CodeComponentPropVideoExample[];

export interface AssetLibrary {
  id: string;
  label: string;
  css: {
    original: string;
    compiled: string;
  };
  js: {
    original: string;
    compiled: string;
  };
}

export interface BrandKit {
  id: string;
  label: string;
  fonts: BrandKitFont[] | null;
}

export type BrandKitFontVariantType = 'static' | 'variable';

export interface BrandKitFontAxis {
  tag: string;
  name?: string;
  min: number;
  max: number;
  default: number;
}

export interface BrandKitFontAxisSetting {
  tag: string;
  value: number;
}

export interface BrandKitFont {
  id: string;
  family: string;
  uri: string;
  format: 'woff2' | 'woff' | 'ttf' | 'otf';
  variantType?: BrandKitFontVariantType;
  weight: string;
  style: string;
  axes?: BrandKitFontAxis[] | null;
  axisSettings?: BrandKitFontAxisSetting[] | null;
  url?: string;
}

export type AssetLibraryFont = BrandKitFont;
export type AssetLibraryFontAxis = BrandKitFontAxis;
export type AssetLibraryFontAxisSetting = BrandKitFontAxisSetting;
export type AssetLibraryFontVariantType = BrandKitFontVariantType;

export interface CodeComponentPropVideoExample {
  src: string;
  poster: string;
}
