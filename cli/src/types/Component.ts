export interface Component {
  machineName: string;
  name: string;
  status: boolean;
  framework?: 'react' | 'vue' | 'unknown';
  required?: string[];
  props?: Record<string, any>;
  slots?: Record<string, any>;
  // @todo: Update to camelCase in https://www.drupal.org/i/3502640.
  source_code_js?: string;
  compiled_js?: string;
  source_code_css?: string;
  compiled_css?: string;
  block_override?: string | null;
  imported_js_components: string[];
}

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
