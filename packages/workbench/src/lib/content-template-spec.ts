import {
  isNonEmptyString,
  isRecord,
  parseElementMap,
  validateElementMapSlotReferences,
  validateSpecComponentTypes,
} from './authored-spec-utils';

import type { Spec } from '@json-render/core';
import type { AuthoredSpecElementMap } from './authored-spec-utils';

export interface AuthoredContentTemplateSpec {
  label: string;
  entityType: string;
  bundle: string;
  viewMode: string;
  elements: AuthoredSpecElementMap;
}

export interface ContentTemplateSpecMetadata {
  label: string;
  entityTypeId: string;
  bundle: string;
  viewMode: string;
}

export interface NormalizedContentTemplateSpec extends ContentTemplateSpecMetadata {
  spec: Spec;
}

export interface ContentTemplateSpecIssue {
  code: 'invalid_content_template_spec';
  message: string;
  path: string;
}

export interface ContentTemplateSpecValidationOptions {
  componentNames?: string[];
}

function getTopLevelElementIds(elements: AuthoredSpecElementMap): string[] {
  const referencedElementIds = new Set<string>();

  Object.values(elements).forEach((element) => {
    Object.values(element.slots ?? {}).forEach((slotItems) => {
      slotItems.forEach((elementId) => {
        referencedElementIds.add(elementId);
      });
    });
  });

  return Object.keys(elements).filter(
    (elementId) => !referencedElementIds.has(elementId),
  );
}

export function normalizeContentTemplateSpec(
  template: AuthoredContentTemplateSpec,
): NormalizedContentTemplateSpec {
  const topLevelElementIds = getTopLevelElementIds(template.elements);

  return {
    label: template.label,
    entityTypeId: template.entityType,
    bundle: template.bundle,
    viewMode: template.viewMode,
    spec: {
      root: 'canvas:component-tree',
      elements: {
        ...template.elements,
        'canvas:component-tree': {
          type: 'canvas:component-tree',
          props: {},
          children: topLevelElementIds,
        },
      } as Spec['elements'],
    },
  };
}

export function parseContentTemplateSpecMetadata(
  value: unknown,
  sourcePath: string,
): {
  template: ContentTemplateSpecMetadata | null;
  issues: ContentTemplateSpecIssue[];
} {
  if (!isRecord(value)) {
    return {
      template: null,
      issues: [
        {
          code: 'invalid_content_template_spec',
          message: `Content template file must contain an object: ${sourcePath}`,
          path: sourcePath,
        },
      ],
    };
  }

  const issues: ContentTemplateSpecIssue[] = [];
  const required: Array<[keyof ContentTemplateSpecMetadata, string, string]> = [
    ['label', 'label', 'label'],
    ['entityTypeId', 'entityType', 'entityType'],
    ['bundle', 'bundle', 'bundle'],
    ['viewMode', 'viewMode', 'viewMode'],
  ];

  const metadata: Partial<ContentTemplateSpecMetadata> = {};

  required.forEach(([key, authoredKey, pathSuffix]) => {
    const fieldValue = value[authoredKey];
    if (!isNonEmptyString(fieldValue)) {
      issues.push({
        code: 'invalid_content_template_spec',
        message: `Content template file must include a non-empty ${authoredKey}: ${sourcePath}`,
        path: `${sourcePath}#${pathSuffix}`,
      });
      return;
    }
    metadata[key] = fieldValue;
  });

  if (issues.length > 0) {
    return { template: null, issues };
  }

  return {
    template: metadata as ContentTemplateSpecMetadata,
    issues: [],
  };
}

export function parseContentTemplateSpec(
  value: unknown,
  sourcePath: string,
  options: ContentTemplateSpecValidationOptions = {},
): {
  template: NormalizedContentTemplateSpec | null;
  issues: ContentTemplateSpecIssue[];
} {
  if (!isRecord(value)) {
    return {
      template: null,
      issues: [
        {
          code: 'invalid_content_template_spec',
          message: `Content template file must contain an object: ${sourcePath}`,
          path: sourcePath,
        },
      ],
    };
  }

  const issues: ContentTemplateSpecIssue[] = [];
  const allowedTopLevelKeys = new Set([
    '$schema',
    'label',
    'entityType',
    'bundle',
    'viewMode',
    'elements',
  ]);
  const unexpectedTopLevelKeys = Object.keys(value).filter(
    (key) => !allowedTopLevelKeys.has(key),
  );
  if (unexpectedTopLevelKeys.length > 0) {
    issues.push({
      code: 'invalid_content_template_spec',
      message: `Content template file contains unexpected top-level keys in ${sourcePath}: ${unexpectedTopLevelKeys.join(', ')}.`,
      path: sourcePath,
    });
  }

  const metadataResult = parseContentTemplateSpecMetadata(value, sourcePath);
  metadataResult.issues.forEach((issue) => issues.push(issue));

  if ('$schema' in value && !isNonEmptyString(value.$schema)) {
    issues.push({
      code: 'invalid_content_template_spec',
      message: `Content template file must include a non-empty $schema string when provided: ${sourcePath}`,
      path: `${sourcePath}#$schema`,
    });
  }

  const parsedElements = parseElementMap(
    value.elements,
    `${sourcePath}#elements`,
  );
  parsedElements.issues.forEach((issue) => {
    issues.push({
      code: 'invalid_content_template_spec',
      message: issue.message,
      path: issue.path,
    });
  });

  if (
    parsedElements.elements &&
    'canvas:component-tree' in parsedElements.elements
  ) {
    issues.push({
      code: 'invalid_content_template_spec',
      message: `Content template files must not define canvas:component-tree directly: ${sourcePath}`,
      path: `${sourcePath}#elements.canvas:component-tree`,
    });
  }

  if (parsedElements.elements) {
    validateElementMapSlotReferences(
      parsedElements.elements,
      `${sourcePath}#elements`,
    ).forEach((issue) => {
      issues.push({
        code: 'invalid_content_template_spec',
        message: issue.message,
        path: issue.path,
      });
    });
  }

  if (
    issues.length > 0 ||
    !parsedElements.elements ||
    !metadataResult.template
  ) {
    return {
      template: null,
      issues,
    };
  }

  const template = normalizeContentTemplateSpec({
    label: metadataResult.template.label,
    entityType: metadataResult.template.entityTypeId,
    bundle: metadataResult.template.bundle,
    viewMode: metadataResult.template.viewMode,
    elements: parsedElements.elements,
  });

  const validationError = validateSpecComponentTypes(template.spec, {
    componentNames: options.componentNames,
    additionalComponentNames: ['canvas:component-tree'],
  });
  if (validationError) {
    return {
      template: null,
      issues: [
        {
          code: 'invalid_content_template_spec',
          message: `Content template spec is invalid in ${sourcePath}: ${validationError}`,
          path: sourcePath,
        },
      ],
    };
  }

  return {
    template,
    issues: [],
  };
}
