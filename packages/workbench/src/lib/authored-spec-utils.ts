import { defineComponentCatalog } from 'drupal-canvas/json-render-utils';

import type { Spec } from '@json-render/core';
import type {
  AuthoredSpecElement,
  AuthoredSpecElementMap,
  AuthoredSpecSlots,
} from 'drupal-canvas/json-render-utils';

export type { AuthoredSpecElement, AuthoredSpecElementMap, AuthoredSpecSlots };

export interface StructuralIssue {
  message: string;
  path: string;
}

export function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null;
}

export function isNonEmptyString(value: unknown): value is string {
  return typeof value === 'string' && value.length > 0;
}

export function parseSlotMap(
  value: unknown,
  path: string,
): {
  slots: AuthoredSpecSlots | null;
  issues: StructuralIssue[];
} {
  if (!isRecord(value)) {
    return {
      slots: null,
      issues: [
        {
          message:
            'Expected an object that maps slot names to element ID arrays.',
          path,
        },
      ],
    };
  }

  const issues: StructuralIssue[] = [];
  const slots: AuthoredSpecSlots = {};

  Object.entries(value).forEach(([slotName, slotValue]) => {
    const slotPath = `${path}.${slotName}`;
    if (!Array.isArray(slotValue)) {
      issues.push({
        message: 'Expected an array of element IDs.',
        path: slotPath,
      });
      return;
    }

    const invalidIndex = slotValue.findIndex(
      (elementId) => !isNonEmptyString(elementId),
    );
    if (invalidIndex !== -1) {
      issues.push({
        message: 'Expected every slot item to be a non-empty string.',
        path: `${slotPath}.${invalidIndex}`,
      });
      return;
    }

    slots[slotName] = [...slotValue];
  });

  return {
    slots: issues.length > 0 ? null : slots,
    issues,
  };
}

export function parseElementMap(
  value: unknown,
  path: string,
): {
  elements: AuthoredSpecElementMap | null;
  issues: StructuralIssue[];
} {
  if (!isRecord(value)) {
    return {
      elements: null,
      issues: [
        {
          message: 'Expected an object keyed by element IDs.',
          path,
        },
      ],
    };
  }

  const issues: StructuralIssue[] = [];
  const elements: AuthoredSpecElementMap = {};

  Object.entries(value).forEach(([elementId, elementValue]) => {
    const elementPath = `${path}.${elementId}`;
    if (!isNonEmptyString(elementId)) {
      issues.push({
        message: 'Expected a non-empty element ID.',
        path: elementPath,
      });
      return;
    }

    if (!isRecord(elementValue)) {
      issues.push({
        message: 'Expected an element object.',
        path: elementPath,
      });
      return;
    }

    const allowedKeys = new Set(['type', 'props', 'slots', '_provenance']);
    const unexpectedKeys = Object.keys(elementValue).filter(
      (key) => !allowedKeys.has(key),
    );
    if (unexpectedKeys.length > 0) {
      issues.push({
        message: `Unexpected element keys: ${unexpectedKeys.join(', ')}.`,
        path: elementPath,
      });
      return;
    }

    if (!isNonEmptyString(elementValue.type)) {
      issues.push({
        message: 'Expected a non-empty string type.',
        path: `${elementPath}.type`,
      });
      return;
    }

    let slots: AuthoredSpecSlots | undefined;
    if ('slots' in elementValue) {
      const parsedSlots = parseSlotMap(
        elementValue.slots,
        `${elementPath}.slots`,
      );
      issues.push(...parsedSlots.issues);
      if (parsedSlots.slots === null) {
        return;
      }
      slots = parsedSlots.slots;
    }

    elements[elementId] = {
      type: elementValue.type,
      ...('props' in elementValue ? { props: elementValue.props } : {}),
      ...(slots ? { slots } : {}),
    };
  });

  return {
    elements: issues.length > 0 ? null : elements,
    issues,
  };
}

export function validateSlotReferences(
  slots: AuthoredSpecSlots,
  elementIds: Set<string>,
  path: string,
): StructuralIssue[] {
  const issues: StructuralIssue[] = [];

  Object.entries(slots).forEach(([slotName, slotValue]) => {
    slotValue.forEach((elementId, index) => {
      if (!elementIds.has(elementId)) {
        issues.push({
          message: `Unknown element ID "${elementId}" referenced from slot "${slotName}".`,
          path: `${path}.${slotName}.${index}`,
        });
      }
    });
  });

  return issues;
}

export function validateElementMapSlotReferences(
  elements: AuthoredSpecElementMap,
  path: string,
): StructuralIssue[] {
  const elementIds = new Set(Object.keys(elements));
  const issues: StructuralIssue[] = [];

  Object.entries(elements).forEach(([elementId, element]) => {
    if (!element.slots) {
      return;
    }

    issues.push(
      ...validateSlotReferences(
        element.slots,
        elementIds,
        `${path}.${elementId}.slots`,
      ),
    );
  });

  return issues;
}

export function createSyntheticRootId(
  existingElementIds: Iterable<string>,
): string {
  const ids = new Set(existingElementIds);
  const baseId = 'canvas:mock-root';

  if (!ids.has(baseId)) {
    return baseId;
  }

  let suffix = 2;
  while (ids.has(`${baseId}-${suffix}`)) {
    suffix += 1;
  }

  return `${baseId}-${suffix}`;
}

function toComponentId(componentName: string): string {
  return `js.${componentName}`;
}

function buildComponentAliasMap(componentNames: string[]): Map<string, string> {
  const aliasMap = new Map<string, string>();

  for (const componentName of componentNames) {
    const canonicalId = toComponentId(componentName);
    aliasMap.set(componentName, canonicalId);
    aliasMap.set(canonicalId, canonicalId);
  }

  return aliasMap;
}

function normalizeSpecForCatalogValidation(
  spec: Spec,
  aliasMap: Map<string, string>,
): Spec {
  const normalizedElements = Object.fromEntries(
    Object.entries(spec.elements).map(([key, element]) => {
      if (!isRecord(element)) {
        return [key, element];
      }

      const normalizedType =
        typeof element.type === 'string'
          ? (aliasMap.get(element.type) ?? element.type)
          : element.type;

      return [
        key,
        {
          ...element,
          type: normalizedType,
          props: element.props ?? {},
          children: Array.isArray(element.children) ? element.children : [],
          slots: isRecord(element.slots) ? element.slots : {},
        },
      ];
    }),
  );

  return {
    ...spec,
    elements: normalizedElements as Spec['elements'],
  };
}

function getValueAtIssuePath(spec: Spec, issuePath: string): unknown {
  return issuePath.split('.').reduce<unknown>((currentValue, segment) => {
    if (!isRecord(currentValue)) {
      return undefined;
    }

    return currentValue[segment];
  }, spec as unknown);
}

function toValidationErrorMessage(
  validationResult: {
    error?: { issues?: Array<{ path?: unknown[]; message?: string }> };
  },
  spec: Spec,
): string {
  const firstIssue = validationResult.error?.issues?.[0];
  if (!firstIssue) {
    return 'Invalid @json-render/react-compatible spec.';
  }

  const issuePath = Array.isArray(firstIssue.path)
    ? firstIssue.path.join('.')
    : '';
  if (!issuePath) {
    return firstIssue.message ?? 'Invalid @json-render/react-compatible spec.';
  }

  const issueValue = getValueAtIssuePath(spec, issuePath);
  const issueValueSuffix =
    issueValue === undefined ? '' : ` = ${JSON.stringify(issueValue)}`;

  return `${firstIssue.message ?? 'Invalid value.'} (${issuePath}${issueValueSuffix})`;
}

export function validateSpecComponentTypes(
  spec: Spec,
  options: {
    componentNames?: string[];
    additionalComponentNames?: string[];
  } = {},
): string | null {
  const catalogComponentNames = [
    ...(options.componentNames ?? []),
    ...(options.additionalComponentNames ?? []),
  ];

  if (catalogComponentNames.length === 0) {
    return null;
  }

  const aliasMap = buildComponentAliasMap(catalogComponentNames);
  const validationCatalog = defineComponentCatalog(
    catalogComponentNames.map((componentName) => ({
      name: componentName,
      machineName: componentName,
      status: true,
      props: {
        properties: {},
      },
      required: [],
      slots: {},
    })),
  );

  const normalizedSpec = normalizeSpecForCatalogValidation(spec, aliasMap);
  const validationResult = validationCatalog.validate(normalizedSpec);

  if (validationResult.success) {
    return null;
  }

  return toValidationErrorMessage(validationResult, normalizedSpec);
}
