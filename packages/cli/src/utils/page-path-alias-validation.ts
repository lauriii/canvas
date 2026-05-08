export interface ExistingPagePathAliasChange {
  localPath: string;
  remotePath: string;
}

export function normalizePathAlias(pathAlias: string): string {
  return pathAlias.trim();
}

export function formatPagePathAliasChangeError(
  change: Pick<ExistingPagePathAliasChange, 'localPath' | 'remotePath'>,
): string {
  return (
    'Path alias changes are not allowed for existing pages. ' +
    `Remote path is "${change.remotePath}"; local path is "${change.localPath}".`
  );
}

export function getPathAliasChange(
  localPathAlias: string,
  remotePathAlias: string,
): ExistingPagePathAliasChange | null {
  const localPath = normalizePathAlias(localPathAlias);
  const remotePath = normalizePathAlias(remotePathAlias);
  if (localPath === remotePath) {
    return null;
  }

  return {
    localPath,
    remotePath,
  };
}
