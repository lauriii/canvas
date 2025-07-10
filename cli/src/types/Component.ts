export interface Component {
  machineName: string;
  name: string;
  status: boolean;
  framework?: 'react' | 'vue' | 'unknown';
  required?: string[];
  props?: Record<string, any>;
  slots?: Record<string, any>;
  sourceCodeJs?: string;
  compiledJs?: string;
  sourceCodeCss?: string;
  compiledCss?: string;
  importedJsComponents: string[];
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
