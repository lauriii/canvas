import fs from 'fs/promises';
import path from 'path';

export function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

export async function fileExists(filePath: string): Promise<boolean> {
  try {
    await fs.access(filePath);
    return true;
  } catch {
    return false;
  }
}

export function formatFilePathForOutput(
  filePath: string,
  projectRoot = process.cwd(),
): string {
  const relativePath = path.isAbsolute(filePath)
    ? path.relative(projectRoot, filePath)
    : filePath;

  return (relativePath || '.').split(path.sep).join(path.posix.sep);
}
