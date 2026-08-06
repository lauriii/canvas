import { lstat, rename } from 'node:fs/promises';
import { isAbsolute, relative, resolve, sep } from 'node:path';

export async function moveTemplateDirectory(
  repositoryDir: string,
  projectDir: string,
  repositoryPath: string,
): Promise<void> {
  const templateDir = resolve(repositoryDir, repositoryPath);
  const relativeTemplateDir = relative(repositoryDir, templateDir);

  if (
    !relativeTemplateDir ||
    relativeTemplateDir === '..' ||
    relativeTemplateDir.startsWith(`..${sep}`) ||
    isAbsolute(relativeTemplateDir)
  ) {
    throw new Error(
      `Template repository path "${repositoryPath}" must be a relative path within the repository`,
    );
  }

  let templateStats;
  try {
    templateStats = await lstat(templateDir);
  } catch (error) {
    if (error instanceof Error && 'code' in error && error.code === 'ENOENT') {
      throw new Error(
        `Template repository path "${repositoryPath}" does not exist`,
      );
    }
    throw error;
  }

  if (!templateStats.isDirectory()) {
    throw new Error(
      `Template repository path "${repositoryPath}" must point to a directory`,
    );
  }

  try {
    await lstat(projectDir);
    throw new Error(`Project directory "${projectDir}" already exists`);
  } catch (error) {
    if (error instanceof Error && 'code' in error && error.code === 'ENOENT') {
      // The project directory must not exist before moving the template.
    } else {
      throw error;
    }
  }

  await rename(templateDir, projectDir);
}
