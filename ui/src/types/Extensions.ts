export interface LegacyExtension {
  id: string;
  name: string;
  description: string;
  imgSrc: string;
  component?: any;
}

export interface Extension {
  id: string;
  name: string;
  description: string;
  icon?: string;
  url: string;
  type?: 'canvas' | 'code-editor' | 'page';
  api_version: string;
  permissions?: string[];
}

export type ActiveExtension = Extension | null;
