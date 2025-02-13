export interface CodeComponent {
  machineName: string;
  name: string;
  status: boolean;
  props: CodeComponentProp[];
  required: string[];
  slots: any[];
  source_code_js: string;
  source_code_css: string;
  compiled_js: string;
  compiled_css: string;
}

export interface CodeComponentProp {
  id: string;
  name: string;
  type: 'string' | 'integer' | 'number' | 'boolean';
  enum?: string[];
  example?: string;
}

export interface CodeComponentSlot {
  id: string;
  name: string;
  example?: string;
}
