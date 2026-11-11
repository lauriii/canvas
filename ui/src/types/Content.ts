export interface ContentStub {
  title: string;
  path: string;
  internalPath: string;
  id: number | string;
  status: boolean;
  isNew?: boolean;
  hasUnsavedStatusChange?: boolean;
  autoSaveLabel: string | null;
  autoSavePath: string;
  // The language the page was created in.
  langcode?: string;
  // The title and path of each of the page's translations, keyed by langcode,
  // and whether the translation has its own layout (a forked translation),
  // which is edited in the Canvas editor.
  translations?: Record<
    string,
    { title: string; path: string; forked?: boolean }
  >;
  links: {
    'delete-form'?: string;
    'edit-form'?: string;
    'https://drupal.org/project/canvas#link-rel-duplicate'?: string;
    'https://drupal.org/project/canvas#link-rel-set-as-homepage'?: string;
    disable?: string;
    enable?: string;
    // Operations provided by other modules, keyed by link relation type.
    [rel: string]: string | undefined;
  };
  // Labels of the operations provided by other modules.
  linkLabels?: Record<string, string>;
}
