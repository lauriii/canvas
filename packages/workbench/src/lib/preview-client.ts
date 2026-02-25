import type { PreviewManifest } from './preview-contract';

export async function fetchPreviewManifest(): Promise<PreviewManifest> {
  const response = await fetch('/__canvas/preview-manifest');

  if (!response.ok) {
    throw new Error(
      `Preview manifest request failed with status ${response.status}.`,
    );
  }

  const data = (await response.json()) as PreviewManifest;
  return data;
}
