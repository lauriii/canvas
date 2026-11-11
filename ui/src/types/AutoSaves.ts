export type AutoSavesHash = Record<
  string,
  {
    autoSaveStartingPoint: string;
    hash: string | null;
  }
>;

export type AutoSavesHashRecord = Record<string, AutoSavesHash>;
