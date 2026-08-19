import type { Spec } from '@json-render/core';
import type { PreviewManifest } from './preview-contract';

const CSRF_TOKEN_PATTERN = /^[A-Za-z0-9_-]+$/;

export async function fetchCsrfToken(
  signal?: AbortSignal,
): Promise<string | null> {
  try {
    const response = await fetch('/session/token', {
      credentials: 'include',
      ...(signal ? { signal } : {}),
    });
    if (!response.ok) return null;
    const token = (await response.text()).trim();
    return CSRF_TOKEN_PATTERN.test(token) ? token : null;
  } catch {
    return null;
  }
}

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

export interface PagePreviewResponse {
  spec: Spec;
  pageVariant: string | null;
}

export async function fetchPreviewPageSpec(
  slug: string,
  signal?: AbortSignal,
): Promise<PagePreviewResponse> {
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

  const data = (await response.json()) as PagePreviewResponse;
  return data;
}

export interface ContentTemplatePreviewMetadata {
  label: string;
  entityTypeId: string;
  bundle: string;
  viewMode: string;
  pageVariant: string | null;
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

export interface PageTemplatePreviewResponse {
  spec: Spec;
  label: string;
  description: string;
  status: boolean;
  isDefault: boolean;
}

export async function fetchPreviewPageTemplateSpec(
  id: string,
  signal?: AbortSignal,
): Promise<PageTemplatePreviewResponse> {
  const response = await fetch(
    `/__canvas/page-template-preview-spec?${new URLSearchParams({ id }).toString()}`,
    { signal },
  );

  if (!response.ok) {
    const errorBody = (await response.json().catch(() => null)) as {
      error?: string;
    } | null;
    throw new Error(
      errorBody?.error ??
        `Page template preview request failed with status ${response.status}.`,
    );
  }

  const data = (await response.json()) as PageTemplatePreviewResponse;
  return data;
}
