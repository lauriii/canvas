import type derivedPropTypes from '@/features/code-editor/component-data/derivedPropTypes';

export interface CodeComponent {
  machineName: string;
  name: string;
  status: boolean;
  block_override: string | null;
  props: CodeComponentProp[];
  required: string[];
  slots: any[];
  source_code_js: string;
  source_code_css: string;
  compiled_js: string;
  compiled_css: string;
}

export interface CodeComponentSerialized
  extends Omit<CodeComponent, 'props' | 'slots'> {
  props: Record<string, CodeComponentPropSerialized>;
  slots: Record<string, CodeComponentSlotSerialized>;
}

export interface CodeComponentProp {
  id: string;
  name: string;
  type: 'string' | 'integer' | 'number' | 'boolean' | 'object';
  enum?: string[];
  example?: string | CodeComponentPropImageExample;
  $ref?: string;
  format?: string;
  derivedType: (typeof derivedPropTypes)[number]['type'] | null;
}

export interface CodeComponentPropImageExample {
  src: string;
  width: number;
  height: number;
  alt: string;
}

export interface CodeComponentPropSerialized {
  title: string;
  type: 'string' | 'integer' | 'number' | 'boolean' | 'object';
  enum?: (string | number)[];
  examples?: (string | number | CodeComponentPropImageExample)[];
  $ref?: string;
  format?: string;
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

export type CodeComponentPropPreviewValue = string | number | boolean;

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
