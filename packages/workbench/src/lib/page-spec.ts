import {
  isNonEmptyString,
  isRecord,
  parseElementMap,
  validateElementMapSlotReferences,
  validateSpecComponentTypes,
} from './authored-spec-utils';

import type { Spec } from '@json-render/core';
import type { AuthoredSpecElementMap } from './authored-spec-utils';

export interface AuthoredPageSpec {
  uuid?: string;
  title: string;
  elements: AuthoredSpecElementMap;
}

export interface NormalizedPageSpec {
  title: string;
  spec: Spec;
}

export interface PageSpecMetadata {
  title: string;
}

export interface PageSpecIssue {
  code: 'invalid_page_spec';
  message: string;
  path: string;
}

export interface PageSpecValidationOptions {
  componentNames?: string[];
}

function getTopLevelPageElementIds(elements: AuthoredSpecElementMap): string[] {
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

export function normalizePageSpec(page: AuthoredPageSpec): NormalizedPageSpec {
  const topLevelElementIds = getTopLevelPageElementIds(page.elements);

  return {
    title: page.title,
    spec: {
      root: 'canvas:component-tree',
      elements: {
        ...page.elements,
        'canvas:component-tree': {
          type: 'canvas:component-tree',
          props: {},
          children: topLevelElementIds,
        },
      } as Spec['elements'],
    },
  };
}

export function parsePageSpecMetadata(
  value: unknown,
  sourcePath: string,
): {
  page: PageSpecMetadata | null;
  issues: PageSpecIssue[];
} {
  if (!isRecord(value)) {
    return {
      page: null,
      issues: [
        {
          code: 'invalid_page_spec',
          message: `Page file must contain an object: ${sourcePath}`,
          path: sourcePath,
        },
      ],
    };
  }

  if (!isNonEmptyString(value.title)) {
    return {
      page: null,
      issues: [
        {
          code: 'invalid_page_spec',
          message: `Page file must include a non-empty title: ${sourcePath}`,
          path: `${sourcePath}#title`,
        },
      ],
    };
  }

  return {
    page: {
      title: value.title,
    },
    issues: [],
  };
}

export function parsePageSpec(
  value: unknown,
  sourcePath: string,
  options: PageSpecValidationOptions = {},
): {
  page: NormalizedPageSpec | null;
  issues: PageSpecIssue[];
} {
  if (!isRecord(value)) {
    return {
      page: null,
      issues: [
        {
          code: 'invalid_page_spec',
          message: `Page file must contain an object: ${sourcePath}`,
          path: sourcePath,
        },
      ],
    };
  }

  const issues: PageSpecIssue[] = [];
  const allowedTopLevelKeys = new Set(['$schema', 'uuid', 'title', 'elements']);
  const unexpectedTopLevelKeys = Object.keys(value).filter(
    (key) => !allowedTopLevelKeys.has(key),
  );
  if (unexpectedTopLevelKeys.length > 0) {
    issues.push({
      code: 'invalid_page_spec',
      message: `Page file contains unexpected top-level keys in ${sourcePath}: ${unexpectedTopLevelKeys.join(', ')}.`,
      path: sourcePath,
    });
  }

  if (!isNonEmptyString(value.title)) {
    issues.push({
      code: 'invalid_page_spec',
      message: `Page file must include a non-empty title: ${sourcePath}`,
      path: `${sourcePath}#title`,
    });
  }

  if ('$schema' in value && !isNonEmptyString(value.$schema)) {
    issues.push({
      code: 'invalid_page_spec',
      message: `Page file must include a non-empty $schema string when provided: ${sourcePath}`,
      path: `${sourcePath}#$schema`,
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
      message: `Page files must not define canvas:component-tree directly: ${sourcePath}`,
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
  }

  if (
    issues.length > 0 ||
    !parsedElements.elements ||
    !isNonEmptyString(value.title)
  ) {
    return {
      page: null,
      issues,
    };
  }

  const page = normalizePageSpec({
    title: value.title,
    elements: parsedElements.elements,
  });

  const validationError = validateSpecComponentTypes(page.spec, {
    componentNames: options.componentNames,
    additionalComponentNames: ['canvas:component-tree'],
  });
  if (validationError) {
    return {
      page: null,
      issues: [
        {
          code: 'invalid_page_spec',
          message: `Page spec is invalid in ${sourcePath}: ${validationError}`,
          path: sourcePath,
        },
      ],
    };
  }

  return {
    page,
    issues: [],
  };
}
