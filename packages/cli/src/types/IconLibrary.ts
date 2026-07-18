/** Asset entry stored on an icon library config entity. */
export interface IconLibraryAsset {
  name: string;
  uri: string;
  url: string;
  /** SHA-256 of the file contents; absent on entries saved before hashing. */
  hash?: string | null;
}

/** Asset entry sent when creating or updating an icon library (no server url). */
export interface IconLibraryAssetInput {
  name: string;
  uri: string;
  hash?: string;
}

/** Icon library config entity (normalized shape from the config API). */
export interface IconLibrary {
  id: string;
  label: string;
  description: string | null;
  /** Twig template override; null means the server default template. */
  template: string | null;
  assets: IconLibraryAsset[] | null;
}

/** A single icon in an icon pack listing. */
export interface IconPackIcon {
  /** Fully qualified icon id ("pack:icon"). */
  id: string;
  name: string;
  label: string;
  svg?: string;
  url?: string;
}

/** An installed icon pack from the icons listing endpoint. */
export interface IconPack {
  id: string;
  label: string;
  description: string;
  iconCount: number;
  icons: IconPackIcon[];
}
