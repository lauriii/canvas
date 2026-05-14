import path from 'path';

import {
  parseContentTemplateSpec,
  parseContentTemplateSpecMetadata,
} from './content-template-spec';
import { validateMockSpecArray } from './mock-spec';
import { parsePageSpec, parsePageSpecMetadata } from './page-spec';

import type { Spec } from '@json-render/core';
import type {
  ContentTemplateSpecIssue,
  ContentTemplateSpecMetadata,
} from './content-template-spec';
import type { PageSpecIssue } from './page-spec';
import type {
  PreviewManifestComponentMock,
  PreviewWarning,
} from './preview-contract';

export function toDiscoveredPageName(
  value: unknown,
  sourcePath: string,
  fallbackName: string,
): string {
  const parsed = parsePageSpecMetadata(value, sourcePath);

  return parsed.page?.title ?? fallbackName;
}

export function toPreviewManifestComponentMocks(
  value: unknown,
  options: {
    sourcePath: string;
    componentRoot: string;
    componentName: string;
    componentNames: string[];
    componentExampleProps?: Record<string, unknown>;
    componentRequiredPropNames?: string[];
  },
): {
  mocks: PreviewManifestComponentMock[];
  warnings: PreviewWarning[];
} {
  const parsed = validateMockSpecArray(value, options.sourcePath, {
    componentName: options.componentName,
    componentNames: options.componentNames,
    componentExampleProps: options.componentExampleProps,
    componentRequiredPropNames: options.componentRequiredPropNames,
  });

  return {
    mocks: parsed.mocks.map((mock, index) => ({
      id: `${path
        .relative(options.componentRoot, options.sourcePath)
        .replaceAll('\\', '/')}#${index}`,
      label: mock.name,
      sourcePath: options.sourcePath,
      spec: mock.spec,
    })),
    warnings: parsed.warnings,
  };
}

export function toPreviewPageSpec(
  value: unknown,
  options: {
    sourcePath: string;
    componentNames: string[];
  },
): {
  spec: Spec | null;
  issues: PageSpecIssue[];
} {
  const parsed = parsePageSpec(value, options.sourcePath, {
    componentNames: options.componentNames,
  });

  return {
    spec: parsed.page?.spec ?? null,
    issues: parsed.issues,
  };
}

export function toDiscoveredContentTemplateName(
  value: unknown,
  sourcePath: string,
  fallbackName: string,
): string {
  const parsed = parseContentTemplateSpecMetadata(value, sourcePath);

  return parsed.template?.label ?? fallbackName;
}

export function toPreviewContentTemplateSpec(
  value: unknown,
  options: {
    sourcePath: string;
    componentNames: string[];
  },
): {
  spec: Spec | null;
  metadata: ContentTemplateSpecMetadata | null;
  issues: ContentTemplateSpecIssue[];
} {
  const parsed = parseContentTemplateSpec(value, options.sourcePath, {
    componentNames: options.componentNames,
  });

  const metadata: ContentTemplateSpecMetadata | null = parsed.template
    ? {
        label: parsed.template.label,
        entityTypeId: parsed.template.entityTypeId,
        bundle: parsed.template.bundle,
        viewMode: parsed.template.viewMode,
      }
    : null;

  return {
    spec: parsed.template?.spec ?? null,
    metadata,
    issues: parsed.issues,
  };
}
