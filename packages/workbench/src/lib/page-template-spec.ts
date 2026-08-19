import {
  isNonEmptyString,
  isRecord,
  normalizeElementMapProps,
  parseElementMap,
  validateElementMapSlotReferences,
  validateSpecComponentTypes,
} from './authored-spec-utils';

import type { Spec } from '@json-render/core';
import type { AuthoredSpecElementMap } from './authored-spec-utils';
import type { PageSpecIssue } from './page-spec';

export interface AuthoredPageTemplateSpec {
  label?: string;
  description?: string;
  status?: boolean;
  default?: boolean;
  elements: AuthoredSpecElementMap;
}

export interface NormalizedPageTemplateSpec {
  label: string;
  description: string;
  spec: Spec;
  status: boolean;
  isDefault: boolean;
}

function getTopLevelElementIds(elements: AuthoredSpecElementMap): string[] {
  const referenced = new Set<string>();
  Object.values(elements).forEach((element) => {
    Object.values(element.slots ?? {}).forEach((slotItems) => {
      slotItems.forEach((id) => referenced.add(id));
    });
  });
  return Object.keys(elements).filter((id) => !referenced.has(id));
}

export function normalizePageTemplateSpec(
  pageTemplate: AuthoredPageTemplateSpec,
): NormalizedPageTemplateSpec {
  const topLevelElementIds = getTopLevelElementIds(pageTemplate.elements);
  const elements = normalizeElementMapProps(pageTemplate.elements);

  return {
    label: pageTemplate.label ?? '',
    description: pageTemplate.description ?? '',
    status: pageTemplate.status ?? true,
    isDefault: pageTemplate.default ?? false,
    spec: {
      root: 'canvas:component-tree',
      elements: {
        ...elements,
        'canvas:component-tree': {
          type: 'canvas:component-tree',
          props: {},
          children: topLevelElementIds,
        },
      } as Spec['elements'],
    },
  };
}

export function parsePageTemplateSpec(
  value: unknown,
  sourcePath: string,
  options: { componentNames?: string[] } = {},
): {
  pageTemplate: NormalizedPageTemplateSpec | null;
  issues: PageSpecIssue[];
} {
  if (!isRecord(value)) {
    return {
      pageTemplate: null,
      issues: [
        {
          code: 'invalid_page_spec',
          message: `Page template file must contain an object: ${sourcePath}`,
          path: sourcePath,
        },
      ],
    };
  }

  const issues: PageSpecIssue[] = [];
  const allowedTopLevelKeys = new Set([
    '$schema',
    'label',
    'description',
    'status',
    'default',
    'elements',
  ]);
  const unexpectedTopLevelKeys = Object.keys(value).filter(
    (key) => !allowedTopLevelKeys.has(key),
  );
  if (unexpectedTopLevelKeys.length > 0) {
    issues.push({
      code: 'invalid_page_spec',
      message: `Page template file contains unexpected top-level keys in ${sourcePath}: ${unexpectedTopLevelKeys.join(', ')}.`,
      path: sourcePath,
    });
  }

  if ('status' in value && typeof value.status !== 'boolean') {
    issues.push({
      code: 'invalid_page_spec',
      message: `Page template "status" must be a boolean in ${sourcePath}.`,
      path: `${sourcePath}#status`,
    });
  }

  if (!isNonEmptyString(value.label)) {
    issues.push({
      code: 'invalid_page_spec',
      message: `Page template file must include a non-empty label: ${sourcePath}`,
      path: `${sourcePath}#label`,
    });
  }

  if ('description' in value && typeof value.description !== 'string') {
    issues.push({
      code: 'invalid_page_spec',
      message: `Page template "description" must be a string in ${sourcePath}.`,
      path: `${sourcePath}#description`,
    });
  }

  if ('default' in value && typeof value.default !== 'boolean') {
    issues.push({
      code: 'invalid_page_spec',
      message: `Page template "default" must be a boolean in ${sourcePath}.`,
      path: `${sourcePath}#default`,
    });
  }

  if (value.default === true && value.status === false) {
    issues.push({
      code: 'invalid_page_spec',
      message: `A default page template cannot be disabled in ${sourcePath}.`,
      path: `${sourcePath}#status`,
    });
  }

  const parsedElements = parseElementMap(
    value.elements,
    `${sourcePath}#elements`,
  );
  parsedElements.issues.forEach((issue) => {
    issues.push({
      code: 'invalid_page_spec',
      message: issue.message,
      path: issue.path,
    });
  });

  if (
    parsedElements.elements &&
    'canvas:component-tree' in parsedElements.elements
  ) {
    issues.push({
      code: 'invalid_page_spec',
      message: `Page template files must not define canvas:component-tree directly: ${sourcePath}`,
      path: `${sourcePath}#elements.canvas:component-tree`,
    });
  }

  if (parsedElements.elements) {
    validateElementMapSlotReferences(
      parsedElements.elements,
      `${sourcePath}#elements`,
    ).forEach((issue) => {
      issues.push({
        code: 'invalid_page_spec',
        message: issue.message,
        path: issue.path,
      });
    });

    const contentMarkerCount = Object.values(parsedElements.elements).filter(
      (element) => element.type === 'marker.page_content',
    ).length;
    if (contentMarkerCount !== 1) {
      issues.push({
        code: 'invalid_page_spec',
        message: `Page template files must contain exactly one marker.page_content element, found ${contentMarkerCount}: ${sourcePath}`,
        path: `${sourcePath}#elements`,
      });
    }
  }

  if (issues.length > 0 || !parsedElements.elements) {
    return { pageTemplate: null, issues };
  }

  const pageTemplate = normalizePageTemplateSpec({
    label: isNonEmptyString(value.label) ? value.label : undefined,
    description:
      typeof value.description === 'string' ? value.description : undefined,
    elements: parsedElements.elements,
    status: typeof value.status === 'boolean' ? value.status : undefined,
    default: typeof value.default === 'boolean' ? value.default : undefined,
  });

  const validationError = validateSpecComponentTypes(pageTemplate.spec, {
    componentNames: options.componentNames,
    additionalComponentNames: ['canvas:component-tree', 'marker.page_content'],
  });
  if (validationError) {
    issues.push({
      code: 'invalid_page_spec',
      message: `Page template spec is invalid in ${sourcePath}: ${validationError}`,
      path: sourcePath,
    });
    return { pageTemplate: null, issues };
  }

  return { pageTemplate, issues };
}
