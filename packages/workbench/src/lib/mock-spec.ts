import {
  createSyntheticRootId,
  isNonEmptyString,
  isRecord,
  parseElementMap,
  parseSlotMap,
} from './authored-spec-utils';

import type { Spec } from '@json-render/core';
import type {
  AuthoredSpecElementMap,
  AuthoredSpecSlots,
} from './authored-spec-utils';
import type { PreviewWarning } from './preview-contract';

export interface MockSpecMetadataEntry {
  name: string;
}

export interface MockSpecPropsEntry {
  name: string;
  props: unknown;
}

export interface MockSpecPropsAndSlotsEntry {
  name: string;
  props: unknown;
  slots: AuthoredSpecSlots;
  elements: AuthoredSpecElementMap;
}

export interface MockSpecAdvancedEntry {
  name: string;
  root: string;
  elements: AuthoredSpecElementMap;
}

export type AuthoredMockSpecEntry =
  | MockSpecPropsEntry
  | MockSpecPropsAndSlotsEntry
  | MockSpecAdvancedEntry;

export interface NormalizedMockSpecEntry {
  name: string;
  spec: Spec;
}

export interface MockSpecIssue {
  code: 'invalid_mock_spec_file' | 'invalid_mock_spec_entry';
  message: string;
  path: string;
}

export interface MockSpecValidationOptions {
  componentName?: string;
  componentNames?: string[];
  componentExampleProps?: Record<string, unknown>;
  componentRequiredPropNames?: string[];
}

export interface AuthoredMockSpecFile {
  $schema?: string;
  mocks: unknown[];
}

function toFileIssue(
  sourcePath: string,
  message: string,
  path = sourcePath,
): MockSpecIssue {
  return {
    code: 'invalid_mock_spec_file',
    message,
    path,
  };
}

function parseMockSpecEntries(
  value: unknown,
  sourcePath: string,
): {
  entries: unknown[] | null;
  issues: MockSpecIssue[];
} {
  if (Array.isArray(value)) {
    return {
      entries: value,
      issues: [],
    };
  }

  if (!isRecord(value)) {
    return {
      entries: null,
      issues: [
        toFileIssue(
          sourcePath,
          `Mock file must contain an object with a mocks array: ${sourcePath}`,
        ),
      ],
    };
  }

  const issues: MockSpecIssue[] = [];
  const allowedTopLevelKeys = new Set(['$schema', 'mocks']);
  const unexpectedTopLevelKeys = Object.keys(value).filter(
    (key) => !allowedTopLevelKeys.has(key),
  );

  if (unexpectedTopLevelKeys.length > 0) {
    issues.push(
      toFileIssue(
        sourcePath,
        `Mock file contains unexpected top-level keys in ${sourcePath}: ${unexpectedTopLevelKeys.join(', ')}.`,
      ),
    );
  }

  if ('$schema' in value && !isNonEmptyString(value.$schema)) {
    issues.push(
      toFileIssue(
        sourcePath,
        `Mock file must include a non-empty $schema string when provided: ${sourcePath}`,
        `${sourcePath}#$schema`,
      ),
    );
  }

  if (!Array.isArray(value.mocks)) {
    issues.push(
      toFileIssue(
        sourcePath,
        `Mock file must include a mocks array: ${sourcePath}`,
        `${sourcePath}#mocks`,
      ),
    );
  }

  if (issues.length > 0 || !Array.isArray(value.mocks)) {
    return {
      entries: null,
      issues,
    };
  }

  return {
    entries: value.mocks,
    issues: [],
  };
}

function mergeRootPropsWithExampleProps(
  props: unknown,
  options: {
    componentExampleProps?: Record<string, unknown>;
    componentRequiredPropNames?: string[];
  },
): unknown {
  if (!options.componentExampleProps || !isRecord(props)) {
    return props;
  }

  const requiredExampleProps = Object.fromEntries(
    (options.componentRequiredPropNames ?? [])
      .filter((propName) =>
        Object.hasOwn(options.componentExampleProps as object, propName),
      )
      .map((propName) => [propName, options.componentExampleProps![propName]]),
  );
  if (Object.keys(requiredExampleProps).length === 0) {
    return props;
  }

  return {
    ...requiredExampleProps,
    ...props,
  };
}

function isInferredComponentType(
  value: unknown,
  componentName?: string,
): value is string {
  if (!componentName || !isNonEmptyString(value)) {
    return false;
  }

  return value === componentName || value === `js.${componentName}`;
}

function mergeExamplePropsIntoInferredComponentElements(
  elements: AuthoredSpecElementMap,
  options: {
    componentName?: string;
    componentExampleProps?: Record<string, unknown>;
    componentRequiredPropNames?: string[];
  },
): Spec['elements'] {
  return Object.fromEntries(
    Object.entries(elements).map(([elementId, element]) => {
      if (!isInferredComponentType(element.type, options.componentName)) {
        return [elementId, element];
      }

      return [
        elementId,
        {
          ...element,
          props: mergeRootPropsWithExampleProps(element.props ?? {}, {
            componentExampleProps: options.componentExampleProps,
            componentRequiredPropNames: options.componentRequiredPropNames,
          }),
        },
      ];
    }),
  ) as Spec['elements'];
}

export function parseMockSpecMetadataArray(
  value: unknown,
  sourcePath: string,
): {
  mocks: MockSpecMetadataEntry[];
  issues: MockSpecIssue[];
} {
  const parsedFile = parseMockSpecEntries(value, sourcePath);
  if (!parsedFile.entries) {
    return {
      mocks: [],
      issues: parsedFile.issues,
    };
  }

  const mocks: MockSpecMetadataEntry[] = [];
  const issues: MockSpecIssue[] = [...parsedFile.issues];

  parsedFile.entries.forEach((entry, index) => {
    if (!isRecord(entry)) {
      issues.push(toIssue(sourcePath, index, 'Expected an object.'));
      return;
    }

    if (!isNonEmptyString(entry.name)) {
      issues.push(
        toIssue(
          sourcePath,
          index,
          'Expected a non-empty string name.',
          '.name',
        ),
      );
      return;
    }

    mocks.push({ name: entry.name });
  });

  return {
    mocks,
    issues,
  };
}

function normalizePropsEntry(
  entry: MockSpecPropsEntry,
  componentName: string,
  componentExampleProps?: Record<string, unknown>,
  componentRequiredPropNames?: string[],
): NormalizedMockSpecEntry {
  const root = createSyntheticRootId([]);

  return {
    name: entry.name,
    spec: {
      root,
      elements: {
        [root]: {
          type: componentName,
          props: mergeRootPropsWithExampleProps(entry.props, {
            componentExampleProps,
            componentRequiredPropNames,
          }),
        },
      } as Spec['elements'],
    },
  };
}

function normalizePropsAndSlotsEntry(
  entry: MockSpecPropsAndSlotsEntry,
  componentName: string,
  componentExampleProps?: Record<string, unknown>,
  componentRequiredPropNames?: string[],
): NormalizedMockSpecEntry {
  const root = createSyntheticRootId(Object.keys(entry.elements));

  return {
    name: entry.name,
    spec: {
      root,
      elements: {
        [root]: {
          type: componentName,
          props: mergeRootPropsWithExampleProps(entry.props, {
            componentExampleProps,
            componentRequiredPropNames,
          }),
          slots: entry.slots,
        },
        ...entry.elements,
      } as Spec['elements'],
    },
  };
}

export function normalizeMockSpecEntry(
  entry: AuthoredMockSpecEntry,
  options: {
    componentName?: string;
    componentExampleProps?: Record<string, unknown>;
    componentRequiredPropNames?: string[];
  } = {},
): NormalizedMockSpecEntry {
  if ('root' in entry) {
    return {
      name: entry.name,
      spec: {
        root: entry.root,
        elements: mergeExamplePropsIntoInferredComponentElements(
          entry.elements,
          options,
        ),
      },
    };
  }

  if (!options.componentName) {
    throw new Error(
      'Cannot normalize shorthand mock entries without an inferred component name.',
    );
  }

  if ('slots' in entry && 'elements' in entry) {
    return normalizePropsAndSlotsEntry(
      entry,
      options.componentName,
      options.componentExampleProps,
      options.componentRequiredPropNames,
    );
  }

  return normalizePropsEntry(
    entry,
    options.componentName,
    options.componentExampleProps,
    options.componentRequiredPropNames,
  );
}

function toEntryPath(sourcePath: string, index: number): string {
  return `${sourcePath}#${index}`;
}

function toIssue(
  sourcePath: string,
  index: number,
  message: string,
  pathSuffix = '',
): MockSpecIssue {
  return {
    code: 'invalid_mock_spec_entry',
    message: `Mock entry at index ${index} is invalid in ${sourcePath}: ${message}`,
    path: pathSuffix
      ? `${toEntryPath(sourcePath, index)}${pathSuffix}`
      : toEntryPath(sourcePath, index),
  };
}

function parseEntry(
  value: unknown,
  sourcePath: string,
  index: number,
  options: MockSpecValidationOptions,
): {
  mock: NormalizedMockSpecEntry | null;
  issues: MockSpecIssue[];
} {
  if (!isRecord(value)) {
    return {
      mock: null,
      issues: [toIssue(sourcePath, index, 'Expected an object.')],
    };
  }

  const issues: MockSpecIssue[] = [];
  const entryName = isNonEmptyString(value.name) ? value.name : '';
  const rootElementId = isNonEmptyString(value.root) ? value.root : '';
  if (!isNonEmptyString(value.name)) {
    issues.push(
      toIssue(sourcePath, index, 'Expected a non-empty string name.', '.name'),
    );
  }

  const hasProps = 'props' in value;
  const hasSlots = 'slots' in value;
  const hasElements = 'elements' in value;
  const hasRoot = 'root' in value;

  const isPropsFormat = hasProps && !hasSlots && !hasElements && !hasRoot;
  const isPropsAndSlotsFormat = hasProps && hasSlots && hasElements && !hasRoot;
  const isAdvancedFormat = hasRoot && hasElements && !hasProps && !hasSlots;

  if (!isPropsFormat && !isPropsAndSlotsFormat && !isAdvancedFormat) {
    issues.push(
      toIssue(
        sourcePath,
        index,
        'Expected one of these shapes: { name, props }, { name, props, slots, elements }, or { name, root, elements }.',
      ),
    );
    return {
      mock: null,
      issues,
    };
  }

  if (isPropsFormat || isPropsAndSlotsFormat) {
    if (!isNonEmptyString(options.componentName)) {
      issues.push(
        toIssue(
          sourcePath,
          index,
          'Shorthand mock entries require an inferred component name.',
        ),
      );
      return {
        mock: null,
        issues,
      };
    }
  }

  if (isPropsFormat) {
    const allowedKeys = new Set(['name', 'props']);
    const unexpectedKeys = Object.keys(value).filter(
      (key) => !allowedKeys.has(key),
    );
    if (unexpectedKeys.length > 0) {
      issues.push(
        toIssue(
          sourcePath,
          index,
          `Unexpected keys for props format: ${unexpectedKeys.join(', ')}.`,
        ),
      );
      return {
        mock: null,
        issues,
      };
    }

    if (issues.length > 0) {
      return {
        mock: null,
        issues,
      };
    }

    const normalized = normalizeMockSpecEntry(
      {
        name: entryName,
        props: value.props,
      },
      {
        componentName: options.componentName,
        componentExampleProps: options.componentExampleProps,
        componentRequiredPropNames: options.componentRequiredPropNames,
      },
    );

    return {
      mock: normalized,
      issues: [],
    };
  }

  if (isPropsAndSlotsFormat) {
    const allowedKeys = new Set(['name', 'props', 'slots', 'elements']);
    const unexpectedKeys = Object.keys(value).filter(
      (key) => !allowedKeys.has(key),
    );
    if (unexpectedKeys.length > 0) {
      issues.push(
        toIssue(
          sourcePath,
          index,
          `Unexpected keys for props and slots format: ${unexpectedKeys.join(', ')}.`,
        ),
      );
      return {
        mock: null,
        issues,
      };
    }

    const parsedSlots = parseSlotMap(
      value.slots,
      `${toEntryPath(sourcePath, index)}.slots`,
    );
    parsedSlots.issues.forEach((issue) => {
      issues.push(
        toIssue(
          sourcePath,
          index,
          issue.message,
          issue.path.replace(toEntryPath(sourcePath, index), ''),
        ),
      );
    });

    const parsedElements = parseElementMap(
      value.elements,
      `${toEntryPath(sourcePath, index)}.elements`,
    );
    parsedElements.issues.forEach((issue) => {
      issues.push(
        toIssue(
          sourcePath,
          index,
          issue.message,
          issue.path.replace(toEntryPath(sourcePath, index), ''),
        ),
      );
    });

    if (issues.length > 0 || !parsedSlots.slots || !parsedElements.elements) {
      return {
        mock: null,
        issues,
      };
    }

    return {
      mock: normalizeMockSpecEntry(
        {
          name: entryName,
          props: value.props,
          slots: parsedSlots.slots,
          elements: parsedElements.elements,
        },
        {
          componentName: options.componentName,
          componentExampleProps: options.componentExampleProps,
          componentRequiredPropNames: options.componentRequiredPropNames,
        },
      ),
      issues: [],
    };
  }

  const allowedKeys = new Set(['name', 'root', 'elements']);
  const unexpectedKeys = Object.keys(value).filter(
    (key) => !allowedKeys.has(key),
  );
  if (unexpectedKeys.length > 0) {
    issues.push(
      toIssue(
        sourcePath,
        index,
        `Unexpected keys for advanced format: ${unexpectedKeys.join(', ')}.`,
      ),
    );
  }

  if (!isNonEmptyString(value.root)) {
    issues.push(
      toIssue(sourcePath, index, 'Expected a non-empty string root.', '.root'),
    );
  }

  const parsedElements = parseElementMap(
    value.elements,
    `${toEntryPath(sourcePath, index)}.elements`,
  );
  parsedElements.issues.forEach((issue) => {
    issues.push(
      toIssue(
        sourcePath,
        index,
        issue.message,
        issue.path.replace(toEntryPath(sourcePath, index), ''),
      ),
    );
  });

  if (
    parsedElements.elements &&
    rootElementId &&
    !(rootElementId in parsedElements.elements)
  ) {
    issues.push(
      toIssue(
        sourcePath,
        index,
        `Root element ID "${rootElementId}" is not defined in elements.`,
        '.root',
      ),
    );
  }

  if (
    issues.length > 0 ||
    !parsedElements.elements ||
    !isNonEmptyString(value.root)
  ) {
    return {
      mock: null,
      issues,
    };
  }

  return {
    mock: normalizeMockSpecEntry(
      {
        name: entryName,
        root: rootElementId,
        elements: parsedElements.elements,
      },
      {
        componentName: options.componentName,
        componentExampleProps: options.componentExampleProps,
        componentRequiredPropNames: options.componentRequiredPropNames,
      },
    ),
    issues: [],
  };
}

export function parseMockSpecArray(
  value: unknown,
  sourcePath: string,
  options: MockSpecValidationOptions = {},
): {
  mocks: NormalizedMockSpecEntry[];
  issues: MockSpecIssue[];
} {
  const parsedFile = parseMockSpecEntries(value, sourcePath);
  if (!parsedFile.entries) {
    return {
      mocks: [],
      issues: parsedFile.issues,
    };
  }

  const mocks: NormalizedMockSpecEntry[] = [];
  const issues: MockSpecIssue[] = [...parsedFile.issues];

  parsedFile.entries.forEach((entry, index) => {
    const parsedEntry = parseEntry(entry, sourcePath, index, options);
    issues.push(...parsedEntry.issues);

    if (!parsedEntry.mock) {
      return;
    }

    mocks.push(parsedEntry.mock);
  });

  return {
    mocks,
    issues,
  };
}

export function validateMockSpecArray(
  value: unknown,
  sourcePath: string,
  options: MockSpecValidationOptions = {},
): {
  mocks: NormalizedMockSpecEntry[];
  specs: Spec[];
  warnings: PreviewWarning[];
} {
  const parsed = parseMockSpecArray(value, sourcePath, options);

  return {
    mocks: parsed.mocks,
    specs: parsed.mocks.map((mock) => mock.spec),
    warnings: parsed.issues.map((issue) => ({
      code: issue.code,
      message: issue.message,
      path: issue.path,
    })),
  };
}
