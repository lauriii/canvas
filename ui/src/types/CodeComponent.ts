import type derivedPropTypes from '@/features/code-editor/component-data/derivedPropTypes';

export interface DataFetch {
  id: string;
  data: any;
  error: boolean;
}

export type CodeComponentType = 'react' | 'external';

export interface CodeComponent {
  machineName: string;
  name: string;
  status: boolean;
  type: CodeComponentType;
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
  entityFields?: Record<string, string[]>;
}

export interface CodeComponentSerialized extends Omit<
  CodeComponent,
  | 'props'
  | 'slots'
  | 'dataFetches'
  | 'type'
  | 'sourceCodeJs'
  | 'sourceCodeCss'
  | 'compiledJs'
  | 'compiledCss'
  | 'importedJsComponents'
> {
  props: Record<string, CodeComponentPropSerialized>;
  slots: Record<string, CodeComponentSlotSerialized>;
  dataDependencies: DataDependencies;
  type?: CodeComponentType;
  sourceCodeJs?: string;
  sourceCodeCss?: string;
  compiledJs?: string;
  compiledCss?: string;
  importedJsComponents?: string[];
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
  'x-canvas-color-picker'?: 'kit-only' | 'kit-and-free';
  'x-canvas-color-folders'?: string[];
  'x-allowed-entity-type-id'?: string;
  'x-allowed-bundle'?: string;
  allowMultiple?: boolean;
  valueMode?: ValueMode;
  limitedCount?: number;
  items?: {
    type: 'string' | 'integer' | 'number' | 'boolean' | 'object';
    format?: string;
    contentMediaType?: string;
    'x-formatting-context'?: string;
    'x-allowed-entity-type-id'?: string;
    'x-allowed-bundle'?: string;
    $ref?: string;
    enum?: (string | number)[];
    'meta:enum'?: Record<
      CodeComponentPropEnumItem['value'],
      CodeComponentPropEnumItem['label']
    >;
  };
  entityFieldExpressions?: string[];
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
  'x-canvas-color-picker'?: 'kit-only' | 'kit-and-free';
  'x-canvas-color-folders'?: string[];
  'x-formatting-context'?: string;
  'x-allowed-entity-type-id'?: string;
  'x-allowed-bundle'?: string;
  maxItems?: number;
  items?: {
    type: 'string' | 'integer' | 'number' | 'boolean' | 'object';
    format?: string;
    contentMediaType?: string;
    'x-formatting-context'?: string;
    'x-allowed-entity-type-id'?: string;
    'x-allowed-bundle'?: string;
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

/**
 * Resolved color prop value for preview.
 *
 * Mirrors the PHP resolveColorPropValue() output exactly.
 *
 * @see src/Plugin/Canvas/ComponentSource/JsonSchemaPropsComponentSourceBase.php
 */
export interface ResolvedColorProp {
  value: BrandKitColorValue;
  cssColorValue: string;
  cssVariable: string | null;
  colorName: string | null;
}

export type CodeComponentPropPreviewValue =
  | string
  | number
  | boolean
  | string[]
  | number[]
  | CodeComponentPropImageExample[]
  | CodeComponentPropVideoExample[]
  | ResolvedColorProp
  | null;

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
  imports?: AssetLibraryManifestEntry[] | null;
  assets?: AssetLibraryManifestEntry[] | null;
  shared?: AssetLibraryManifestEntry[] | null;
  bundledSources?: AssetLibraryBundledSource[] | null;
  packageJson?: string | null;
}

export interface AssetLibraryManifestEntry {
  name: string;
  uri: string;
  path?: string;
  source?: string;
  url?: string;
}

export interface AssetLibraryBundledSource {
  path: string;
  source: string;
}

/**
 * Color value in W3C Design Token format.
 * @see https://www.designtokens.org/TR/2025.10/color/
 */
export interface BrandKitColorValue {
  /** Color space identifier (e.g., 'srgb', 'hsl') */
  colorSpace: 'srgb' | 'hsl';
  /**
   * Color components.
   * For sRGB: [R, G, B] each 0-1
   * For HSL: [H, S, L] where H is 0-360, S and L are 0-100
   */
  components: [number, number, number];
  /** Alpha (opacity) value 0-1, or null for fully opaque */
  alpha: number | null;
  /** Optional 6-digit hex fallback for sRGB colors */
  hex: string | null;
}

export interface BrandKitColor {
  id: string;
  name: string;
  cssVariable: string;
  value: BrandKitColorValue;
  /** Original input format for display purposes */
  displayFormat?: 'rgb' | 'hex' | 'hsl' | null;
  weight: number;
}

export interface BrandKit {
  id: string;
  label: string;
  fonts: BrandKitFont[] | null;
  colors: BrandKitColor[] | null;
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
