import fs from 'fs/promises';

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

export async function directoryExists(dirPath: string): Promise<boolean> {
  return await fs
    .stat(dirPath)
    .then(() => true)
    .catch(() => false);
}
