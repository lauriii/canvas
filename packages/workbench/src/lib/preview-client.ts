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

export interface ContentTemplatePreviewMetadata {
  label: string;
  entityTypeId: string;
  bundle: string;
  viewMode: string;
}

export interface ContentTemplatePreviewResponse {
  spec: Spec;
  metadata: ContentTemplatePreviewMetadata;
}

export async function fetchPreviewContentTemplateSpec(
  slug: string,
  signal?: AbortSignal,
): Promise<ContentTemplatePreviewResponse> {
  const response = await fetch(
    `/__canvas/content-template-preview-spec?${new URLSearchParams({ slug }).toString()}`,
    { signal },
  );

  if (!response.ok) {
    const errorBody = (await response.json().catch(() => null)) as {
      error?: string;
    } | null;
    throw new Error(
      errorBody?.error ??
        `Content template preview request failed with status ${response.status}.`,
    );
  }

  const data = (await response.json()) as ContentTemplatePreviewResponse;
  return data;
}

export interface WorkbenchConfig {
  siteUrl: string | null;
}

export async function fetchWorkbenchConfig(
  signal?: AbortSignal,
): Promise<WorkbenchConfig> {
  const response = await fetch('/__canvas/workbench-config', { signal });

  if (!response.ok) {
    throw new Error(
      `Workbench config request failed with status ${response.status}.`,
    );
  }

  const data = (await response.json()) as WorkbenchConfig;
  return data;
}

export interface RegionPreviewResponse {
  spec: Spec;
  status: boolean;
}

export async function fetchPreviewRegionSpec(
  id: string,
  signal?: AbortSignal,
): Promise<RegionPreviewResponse> {
  const response = await fetch(
    `/__canvas/region-preview-spec?${new URLSearchParams({ id }).toString()}`,
    { signal },
  );

  if (!response.ok) {
    const errorBody = (await response.json().catch(() => null)) as {
      error?: string;
    } | null;
    throw new Error(
      errorBody?.error ??
        `Region preview request failed with status ${response.status}.`,
    );
  }

  const data = (await response.json()) as RegionPreviewResponse;
  return data;
}
