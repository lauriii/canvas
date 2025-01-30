export interface CodeComponent {
  machineName: string;
  name: string;
  status: boolean;
  props: {
    [key: string]: {
      type: string;
      title: string;
      examples: Array<string | number | boolean>;
    };
  };
  required: string[];
  slots: any[];
  source_code_js: string;
  source_code_css: string;
  compiled_js: string;
  compiled_css: string;
}
