export type ConnectionStatus =
  | 'checking'
  | 'ready'
  | 'unreachable'
  | 'setup-needed';

export interface HeadlessFrontend {
  id: string;
  url: string;
  status: ConnectionStatus;
}

export type PackageManager = 'npm' | 'pnpm' | 'yarn' | 'bun';

export const PACKAGE_MANAGERS: PackageManager[] = [
  'npm',
  'pnpm',
  'yarn',
  'bun',
];
