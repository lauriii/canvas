/**
 * @file
 * Canvas-managed style libraries as local `{id}.utilities.yml` files.
 *
 * Files use core's utilities format (styles keyed by style ID) so they can
 * move between a CLI project and a theme unchanged. The library label is kept
 * in a `# label:` comment below the schema comment, because every top-level
 * key in the format is a style ID.
 *
 * The local rules mirror what Canvas enforces on save, so a push fails before
 * any request with the file, line, and offending style or option.
 *
 * @see \Drupal\canvas\Entity\StyleLibrary
 * @see `canvas.style_library.*` in config/schema/canvas.schema.yml
 */

import fs from 'fs/promises';
import path from 'path';
import { isMap, LineCounter, parseDocument, stringify } from 'yaml';

import type { Document, Node } from 'yaml';

/** Core's JSON Schema for one style in a `*.utilities.yml` file. */
export const STYLE_SCHEMA_URL =
  'https://git.drupalcode.org/project/drupal/-/raw/HEAD/core/assets/schemas/v1/style.schema.json';

export const STYLE_LIBRARY_FILE_SUFFIX = '.utilities.yml';

export const STYLE_ID_PATTERN = /^[a-z0-9_]+$/;
export const OPTION_ID_PATTERN = /^(?!0$)[a-zA-Z0-9_ -]+$/;
export const ALLOWED_ATTRIBUTE_PATTERN =
  /^(class|style|data-[a-z0-9-]+|aria-[a-z-]+)$/;
const LIBRARY_ID_PATTERN = /^[a-z0-9_]+$/;
const STATUSES = ['experimental', 'stable', 'deprecated', 'obsolete'];
const LABEL_COMMENT_PATTERN = /^#\s*label:\s*(.*\S)\s*$/;
export const CUSTOM_PROPERTY_PATTERN = /^--[a-z0-9-]+$/;
const CUSTOM_UNIT_PATTERN = /^[a-z%]+$/;
const BOUND_KEYS = ['min', 'max', 'step'];

/** A style library as the config entity API exchanges it. */
export interface StyleLibraryPayload {
  id: string;
  label: string;
  styles: Record<string, Record<string, unknown>>;
}

export interface StyleLibraryDiagnostic {
  severity: 'error' | 'warning';
  /** Dotted path inside the file, for example `surface.options.dark`. */
  path: string;
  message: string;
  line?: number;
  column?: number;
}

export interface LocalStyleLibrary {
  library: StyleLibraryPayload;
  filePath: string;
  diagnostics: StyleLibraryDiagnostic[];
}

type KeyRule = (value: unknown) => string | null;

const isString: KeyRule = (value) =>
  typeof value === 'string' ? null : 'must be a string';
const isRequiredLabel: KeyRule = (value) =>
  typeof value === 'string' && value.trim() !== ''
    ? null
    : 'must be a non-empty string';
const isStatus: KeyRule = (value) =>
  typeof value === 'string' && STATUSES.includes(value)
    ? null
    : `must be one of ${STATUSES.join(', ')}`;
const isAttribute: KeyRule = (value) =>
  typeof value === 'string' && ALLOWED_ATTRIBUTE_PATTERN.test(value)
    ? null
    : `"${String(value)}" is not allowed. Use class, style, data-*, or aria-*`;
const isStringList: KeyRule = (value) =>
  Array.isArray(value) && value.every((item) => typeof item === 'string')
    ? null
    : 'must be a list of strings';

const STYLE_KEYS: Record<string, KeyRule> = {
  label: isRequiredLabel,
  description: isString,
  category: isString,
  weight: (value) => (Number.isInteger(value) ? null : 'must be an integer'),
  status: isStatus,
  enabled: (value) => (typeof value === 'boolean' ? null : 'must be a boolean'),
  links: isStringList,
  attribute: isAttribute,
  // Options, tokens, and custom values are validated separately.
  options: () => null,
  tokens: () => null,
  custom: () => null,
};

const OPTION_KEYS: Record<string, KeyRule> = {
  label: isRequiredLabel,
  value: (value) =>
    typeof value !== 'string'
      ? 'must be a string'
      : value.length > 2048
        ? 'must be at most 2048 characters'
        : null,
  description: isString,
  attribute: isAttribute,
  status: isStatus,
  icon: isString,
  previewed_with: isStringList,
  previewed_as: isString,
};

/** Keys Canvas rejects outright, with the reason. */
const REJECTED_STYLE_KEYS: Record<string, string> = {
  library:
    '"library" is not allowed in Canvas-managed libraries. Add the classes to global CSS instead',
  target:
    '"target" is not allowed in Canvas-managed libraries: they only define component styles',
};

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

const isClassValue: KeyRule = (value) =>
  typeof value !== 'string' || value.trim() === ''
    ? 'must be a non-empty string'
    : value.length > 255
      ? 'must be at most 255 characters'
      : null;
const isCustomProperty: KeyRule = (value) =>
  typeof value === 'string' && CUSTOM_PROPERTY_PATTERN.test(value)
    ? null
    : `"${String(value)}" is not a valid custom property name. Use "--" followed by lowercase letters, numbers, and hyphens`;

const TOKENS_KEYS: Record<string, KeyRule> = {
  type: (value) => (value === 'color' ? null : 'must be color'),
  folders: (value) =>
    Array.isArray(value) &&
    value.every((item) => typeof item === 'string' && item.trim() !== '')
      ? null
      : 'must be a list of folder names',
  value: isClassValue,
  property: isCustomProperty,
};

type Issue = {
  path: string[];
  severity: 'error' | 'warning';
  message: string;
};

/**
 * Validates numeric bounds: numbers, min at most max, and a positive step.
 */
function validateBounds(
  bounds: Record<string, unknown>,
  parts: string[],
  name: string,
  error: (parts: string[], message: string) => void,
): void {
  for (const key of BOUND_KEYS) {
    if (key in bounds && typeof bounds[key] !== 'number') {
      error([...parts, key], `${name}: ${key} must be a number.`);
    }
  }
  const { min, max, step } = bounds;
  if (typeof min === 'number' && typeof max === 'number' && min > max) {
    error([...parts, 'min'], `${name}: min must not be greater than max.`);
  }
  if (typeof step === 'number' && step <= 0) {
    error([...parts, 'step'], `${name}: step must be greater than 0.`);
  }
}

/**
 * Validates an authored `custom` declaration: a units map with bounds per
 * unit, or top-level bounds for unitless numbers, plus the class and custom
 * property.
 *
 * @see `canvas.style_custom` in config/schema/canvas.schema.yml
 */
function validateCustom(
  styleId: string,
  custom: unknown,
  issues: Issue[],
): void {
  const parts = [styleId, 'custom'];
  const name = `Custom values of style "${styleId}"`;
  const error = (path: string[], message: string) =>
    issues.push({ path, severity: 'error', message });
  if (!isRecord(custom)) {
    error(parts, `${name} must be a mapping.`);
    return;
  }
  for (const key of Object.keys(custom)) {
    if (!['type', 'units', 'value', 'property', ...BOUND_KEYS].includes(key)) {
      error([...parts, key], `${name} has unknown key "${key}".`);
    }
  }
  const hasUnits = 'units' in custom;
  if ('type' in custom) {
    if (custom.type !== 'integer') {
      error([...parts, 'type'], `${name}: type must be integer.`);
    } else if (hasUnits) {
      error(
        [...parts, 'type'],
        `${name}: type integer only applies to unitless numbers. Remove units or type.`,
      );
    }
  }
  if (hasUnits) {
    const units = custom.units;
    if (!isRecord(units) || Object.keys(units).length === 0) {
      error(
        [...parts, 'units'],
        `${name}: units must be a mapping of units to bounds, for example \`rem: { min: 1, max: 10, step: 0.25 }\`.`,
      );
    } else {
      for (const [unit, bounds] of Object.entries(units)) {
        const unitPath = [...parts, 'units', unit];
        if (!CUSTOM_UNIT_PATTERN.test(unit)) {
          error(
            unitPath,
            `${name}: "${unit}" is not a valid unit. Use lowercase letters or %.`,
          );
        }
        if (bounds === null) {
          continue;
        }
        if (!isRecord(bounds)) {
          error(unitPath, `${name}: the ${unit} bounds must be a mapping.`);
          continue;
        }
        for (const key of Object.keys(bounds)) {
          if (!BOUND_KEYS.includes(key)) {
            error(
              [...unitPath, key],
              `${name}: the ${unit} bounds have unknown key "${key}".`,
            );
          }
        }
        validateBounds(bounds, unitPath, `${name} in ${unit}`, error);
      }
    }
    const topLevel = BOUND_KEYS.filter((key) => key in custom);
    if (topLevel.length > 0) {
      issues.push({
        path: [...parts, topLevel[0]],
        severity: 'warning',
        message: `${name}: top-level ${topLevel.join(', ')} ${topLevel.length === 1 ? 'is' : 'are'} ignored when units are set. Move the bounds under each unit.`,
      });
    }
  } else {
    validateBounds(custom, parts, name, error);
  }
  for (const [key, rule] of [
    ['value', isClassValue],
    ['property', isCustomProperty],
  ] as const) {
    if (!(key in custom)) {
      error(parts, `${name} must have a ${key}.`);
      continue;
    }
    const message = rule(custom[key]);
    if (message) {
      error([...parts, key], `${name}: ${key} ${message}.`);
    }
  }
}

/** Validates an authored `tokens` declaration (brand kit colors). */
function validateTokens(
  styleId: string,
  tokens: unknown,
  issues: Issue[],
): void {
  const parts = [styleId, 'tokens'];
  const name = `Brand kit colors of style "${styleId}"`;
  const error = (path: string[], message: string) =>
    issues.push({ path, severity: 'error', message });
  if (!isRecord(tokens)) {
    error(parts, `${name} must be a mapping.`);
    return;
  }
  for (const [key, value] of Object.entries(tokens)) {
    const rule = TOKENS_KEYS[key];
    if (!rule) {
      error([...parts, key], `${name} has unknown key "${key}".`);
      continue;
    }
    const message = rule(value);
    if (message) {
      error([...parts, key], `${name}: ${key} ${message}.`);
    }
  }
  for (const key of ['type', 'value', 'property']) {
    if (!(key in tokens)) {
      error(parts, `${name} must have a ${key}.`);
    }
  }
}

export function styleLibraryIdFromPath(filePath: string): string {
  return path.basename(filePath).slice(0, -STYLE_LIBRARY_FILE_SUFFIX.length);
}

/**
 * Serializes a library to file contents: the schema comment, the label
 * comment, then the styles in core's format.
 */
export function serializeStyleLibrary(library: StyleLibraryPayload): string {
  const header = [
    `# yaml-language-server: $schema=${STYLE_SCHEMA_URL}`,
    `# label: ${library.label}`,
  ].join('\n');
  const body =
    Object.keys(library.styles).length > 0
      ? stringify(library.styles, { singleQuote: true })
      : '{}\n';
  return `${header}\n${body}`;
}

function readLabelComment(source: string): string | null {
  for (const line of source.split('\n')) {
    if (!line.startsWith('#')) {
      break;
    }
    const match = LABEL_COMMENT_PATTERN.exec(line);
    if (match) {
      return match[1];
    }
  }
  return null;
}

function locate(
  document: Document.Parsed,
  lineCounter: LineCounter,
  parts: string[],
): { line?: number; column?: number } {
  let current = parts;
  while (current.length > 0) {
    const node = document.getIn(current, true) as Node | undefined;
    if (node && typeof node === 'object' && 'range' in node && node.range) {
      const position = lineCounter.linePos(node.range[0]);
      return { line: position.line, column: position.col };
    }
    current = current.slice(0, -1);
  }
  return {};
}

/**
 * Validates library contents against the rules Canvas applies on save, and
 * warns about options whose class would fall back to the option ID.
 */
export function validateStyleLibraryContents(
  id: string,
  styles: unknown,
): Issue[] {
  const issues: Issue[] = [];
  const error = (parts: string[], message: string) =>
    issues.push({ path: parts, severity: 'error', message });

  if (!LIBRARY_ID_PATTERN.test(id)) {
    error(
      [],
      `"${id}" is not a valid library ID. Rename the file so the part before ${STYLE_LIBRARY_FILE_SUFFIX} contains only lowercase letters, numbers, and underscores.`,
    );
  }
  if (!isRecord(styles)) {
    error([], 'The file must be a mapping of style IDs to styles.');
    return issues;
  }

  for (const [styleId, style] of Object.entries(styles)) {
    if (!STYLE_ID_PATTERN.test(styleId)) {
      error(
        [styleId],
        `"${styleId}" is not a valid style ID. Style IDs contain only lowercase letters, numbers, and underscores.`,
      );
    }
    if (!isRecord(style)) {
      error([styleId], `Style "${styleId}" must be a mapping.`);
      continue;
    }
    for (const [key, value] of Object.entries(style)) {
      if (key in REJECTED_STYLE_KEYS) {
        error([styleId, key], `${REJECTED_STYLE_KEYS[key]}.`);
        continue;
      }
      const rule = STYLE_KEYS[key];
      if (!rule) {
        error([styleId, key], `Style "${styleId}" has unknown key "${key}".`);
        continue;
      }
      const message = rule(value);
      if (message) {
        error([styleId, key], `Style "${styleId}" ${key} ${message}.`);
      }
    }
    if (!('label' in style)) {
      error([styleId], `Style "${styleId}" must have a label.`);
    }
    if ('custom' in style) {
      validateCustom(styleId, style.custom, issues);
    }
    if ('tokens' in style) {
      validateTokens(styleId, style.tokens, issues);
    }
    const options = style.options;
    // Options are optional when brand kit colors or custom values supply
    // the choices.
    if (options === undefined && ('custom' in style || 'tokens' in style)) {
      continue;
    }
    if (!isRecord(options) || Object.keys(options).length === 0) {
      error(
        [styleId, 'options'],
        `Style "${styleId}" must have at least one option.`,
      );
      continue;
    }
    for (const [optionId, option] of Object.entries(options)) {
      const optionPath = [styleId, 'options', optionId];
      const name = `Option "${optionId}" of style "${styleId}"`;
      if (!OPTION_ID_PATTERN.test(optionId)) {
        error(
          optionPath,
          `"${optionId}" is not a valid option ID for style "${styleId}".`,
        );
      }
      if (!isRecord(option)) {
        error(optionPath, `${name} must be a mapping.`);
        continue;
      }
      for (const [key, value] of Object.entries(option)) {
        const rule = OPTION_KEYS[key];
        if (!rule) {
          error([...optionPath, key], `${name} has unknown key "${key}".`);
          continue;
        }
        const message = rule(value);
        if (message) {
          error([...optionPath, key], `${name}: ${key} ${message}.`);
        }
      }
      if (!('label' in option)) {
        error(optionPath, `${name} must have a label.`);
      }
      if (!('value' in option)) {
        const attribute =
          (typeof option.attribute === 'string' && option.attribute) ||
          (typeof style.attribute === 'string' && style.attribute) ||
          'class';
        issues.push({
          path: optionPath,
          severity: 'warning',
          message: `${name} has no value, so it sets ${attribute}="${optionId}". Set \`value: ''\` for an option that only drives component logic.`,
        });
      }
    }
  }
  return issues;
}

/**
 * Parses and validates one library file. YAML syntax errors are returned as
 * diagnostics with an empty library.
 */
export function parseStyleLibrary(
  filePath: string,
  source: string,
): LocalStyleLibrary {
  const id = styleLibraryIdFromPath(filePath);
  const lineCounter = new LineCounter();
  const document = parseDocument(source, {
    lineCounter,
    prettyErrors: false,
    uniqueKeys: true,
  });
  const label = readLabelComment(source) ?? id;
  if (document.errors.length > 0) {
    return {
      filePath,
      library: { id, label, styles: {} },
      diagnostics: document.errors.map((error) => {
        const position =
          error.pos[0] !== undefined
            ? lineCounter.linePos(error.pos[0])
            : undefined;
        return {
          severity: 'error',
          path: '',
          message: error.message,
          line: position?.line,
          column: position?.col,
        };
      }),
    };
  }
  // An empty file is an empty library.
  const value: unknown =
    document.contents === null ? {} : document.toJS({ maxAliasCount: 0 });
  const diagnostics = validateStyleLibraryContents(id, value).map((issue) => ({
    severity: issue.severity,
    path: issue.path.join('.'),
    message: issue.message,
    ...locate(document, lineCounter, issue.path),
  }));
  return {
    filePath,
    library: {
      id,
      label,
      styles:
        isMap(document.contents) && isRecord(value)
          ? (value as StyleLibraryPayload['styles'])
          : {},
    },
    diagnostics,
  };
}

/**
 * Reads every `*.utilities.yml` file in the styles directory. Returns null
 * when the directory does not exist, which leaves site libraries unmanaged.
 */
export async function readLocalStyleLibraries(
  stylesDir: string,
): Promise<LocalStyleLibrary[] | null> {
  let entries: string[];
  try {
    entries = await fs.readdir(stylesDir);
  } catch (error) {
    if ((error as NodeJS.ErrnoException)?.code === 'ENOENT') {
      return null;
    }
    throw error;
  }
  const files = entries
    .filter((entry) => entry.endsWith(STYLE_LIBRARY_FILE_SUFFIX))
    .sort();
  return Promise.all(
    files.map(async (file) => {
      const filePath = path.join(stylesDir, file);
      return parseStyleLibrary(filePath, await fs.readFile(filePath, 'utf-8'));
    }),
  );
}

/** Formats a diagnostic as `Line 3, Column 5: message`. */
export function formatStyleLibraryDiagnostic(
  diagnostic: StyleLibraryDiagnostic,
): string {
  const location = diagnostic.line
    ? `Line ${diagnostic.line}, Column ${diagnostic.column}: `
    : '';
  return `${location}${diagnostic.message}`;
}

/**
 * Reports style IDs defined by more than one local library, or already
 * provided by a theme or module according to the site's style definitions.
 */
export function findStyleLibraryCollisions(
  libraries: LocalStyleLibrary[],
  definitions: { id: string; provider?: string; providerType?: string }[],
): Map<LocalStyleLibrary, string[]> {
  const external = new Map(
    definitions
      .filter((definition) => definition.providerType !== 'site')
      .map((definition) => [
        definition.id,
        definition.provider ?? 'an extension',
      ]),
  );
  const firstLibrary = new Map<string, LocalStyleLibrary>();
  const collisions = new Map<LocalStyleLibrary, string[]>();
  const add = (library: LocalStyleLibrary, message: string) =>
    collisions.set(library, [...(collisions.get(library) ?? []), message]);
  for (const local of libraries) {
    for (const styleId of Object.keys(local.library.styles)) {
      const provider = external.get(styleId);
      if (provider !== undefined) {
        add(
          local,
          `The style "${styleId}" is already provided by ${provider}. Rename it in ${path.basename(local.filePath)}.`,
        );
      }
      const other = firstLibrary.get(styleId);
      if (other) {
        add(
          local,
          `The style "${styleId}" is also defined in ${path.basename(other.filePath)}.`,
        );
      } else {
        firstLibrary.set(styleId, local);
      }
    }
  }
  return collisions;
}

/**
 * Stable JSON for comparison: object keys are sorted, except option keys,
 * whose order is the order editors see.
 */
function canonical(value: unknown, keepOrder = false): unknown {
  if (Array.isArray(value)) {
    return value.map((item) => canonical(item));
  }
  if (!isRecord(value)) {
    return value;
  }
  const keys = keepOrder ? Object.keys(value) : Object.keys(value).sort();
  return keys.map((key) => [key, canonical(value[key], key === 'options')]);
}

/** Whether pushing the local library would change the site's library. */
export function styleLibrariesEqual(
  a: StyleLibraryPayload,
  b: StyleLibraryPayload,
): boolean {
  return (
    a.label === b.label &&
    JSON.stringify(canonical(a.styles)) === JSON.stringify(canonical(b.styles))
  );
}
