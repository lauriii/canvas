import type { CanvasGeometry } from './types';

const MAX_GEOMETRY_ITEMS = 20_000;
const MAX_IDENTITY_LENGTH = 4_096;
const MAX_ABSOLUTE_RECT_VALUE = 100_000_000;
const CANVAS_BOUNDARY_TYPES = new Set(['component', 'slot', 'region']);
const CANVAS_MARKER_FORMATS = new Set(['comment', 'template']);
const CANVAS_STACK_DIRECTIONS = new Set([
  'vertical',
  'vertical-grid',
  'vertical-flex',
  'horizontal-flex',
  'horizontal-grid',
]);

/** Validates a serialized Canvas geometry protocol snapshot. */
export function isCanvasGeometrySnapshot(
  value: unknown,
): value is CanvasGeometry[] {
  if (!Array.isArray(value) || value.length > MAX_GEOMETRY_ITEMS) {
    return false;
  }

  for (let index = 0; index < value.length; index += 1) {
    if (!Object.hasOwn(value, index) || !isCanvasGeometry(value[index])) {
      return false;
    }
  }

  return true;
}

function isCanvasGeometry(value: unknown): value is CanvasGeometry {
  if (!isRecord(value) || !isRecord(value.rect)) {
    return false;
  }

  return (
    isNonEmptyIdentity(value.id) &&
    typeof value.type === 'string' &&
    CANVAS_BOUNDARY_TYPES.has(value.type) &&
    typeof value.markerFormat === 'string' &&
    CANVAS_MARKER_FORMATS.has(value.markerFormat) &&
    isCanvasRect(value.rect) &&
    isOptionalNonEmptyString(value.componentUuid) &&
    isOptionalNonEmptyString(value.slotName) &&
    (value.stackDirection === undefined ||
      (typeof value.stackDirection === 'string' &&
        CANVAS_STACK_DIRECTIONS.has(value.stackDirection)))
  );
}

function isCanvasRect(value: Record<string, unknown>): boolean {
  return (
    isFiniteNumber(value.top) &&
    isFiniteNumber(value.right) &&
    isFiniteNumber(value.bottom) &&
    isFiniteNumber(value.left) &&
    isFiniteNumber(value.width) &&
    isFiniteNumber(value.height) &&
    value.width >= 0 &&
    value.height >= 0 &&
    value.right >= value.left &&
    value.bottom >= value.top
  );
}

function isFiniteNumber(value: unknown): value is number {
  return (
    typeof value === 'number' &&
    Number.isFinite(value) &&
    Math.abs(value) <= MAX_ABSOLUTE_RECT_VALUE
  );
}

function isOptionalNonEmptyString(value: unknown): boolean {
  return value === undefined || isNonEmptyIdentity(value);
}

function isNonEmptyIdentity(value: unknown): value is string {
  return (
    typeof value === 'string' &&
    value.length > 0 &&
    value.length <= MAX_IDENTITY_LENGTH
  );
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}
