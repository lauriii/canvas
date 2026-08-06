export type Template = {
  id: string;
  aliases?: string[];
  label: string;
  experimental?: boolean;
  repository: {
    url: string;
    ref: string;
    path?: string;
  };
};
