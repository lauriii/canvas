import Ajv from 'ajv';
import addFormats from 'ajv-formats';
// @ts-expect-error The package does not publish TypeScript declarations.
import addDraft2019Formats from 'ajv-formats-draft2019';

import type { Options } from 'ajv';

export { MissingRefError } from 'ajv';
export type { AnySchema, ErrorObject, Options, ValidateFunction } from 'ajv';

const DRAFT_07_META_SCHEMA = 'http://json-schema.org/draft-07/schema#';

type CanvasAjvOptions = Omit<Options, 'defaultMeta'>;

export function createCanvasAjv(options: CanvasAjvOptions = {}): Ajv {
  const ajv = new Ajv({
    defaultMeta: DRAFT_07_META_SCHEMA,
    strict: true,
    ...options,
  });
  addFormats(ajv);
  addDraft2019Formats(ajv);
  return ajv;
}
