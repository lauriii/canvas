import type { Spec } from '@json-render/core';
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

export async function fetchPreviewPageSpec(
  slug: string,
  signal?: AbortSignal,
): Promise<Spec> {
  const response = await fetch(
    `/__canvas/page-preview-spec?${new URLSearchParams({ slug }).toString()}`,
    { signal },
  );

  if (!response.ok) {
    const errorBody = (await response.json().catch(() => null)) as {
      error?: string;
    } | null;
    throw new Error(
      errorBody?.error ??
        `Page preview request failed with status ${response.status}.`,
    );
  }

  const data = (await response.json()) as Spec;
  return data;
}
