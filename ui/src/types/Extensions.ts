export interface ExtensionDefinition {
  name: string;
  id: string;
  description: string;
  imgSrc: string;
}

export type ExtensionsList = ExtensionDefinition[];
export type ActiveExtension = ExtensionDefinition | null;
