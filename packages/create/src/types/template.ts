export type Template = {
  id: string;
  aliases?: string[];
  label: string;
  repository: {
    url: string;
    ref: string;
    path?: string;
  };
};
