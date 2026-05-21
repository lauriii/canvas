import Ajv from 'ajv';
import addFormats from 'ajv-formats';
import addDraft2019 from 'ajv-formats-draft2019';

// JSON Schema draft-07 is used for both client and server validation; the
// PHP-side justinrainbow/json-schema factory is configured to the same
// dialect in CanvasServiceProvider. `defaultMeta` makes draft-07 the
// explicit `$schema` used when a compiled schema doesn't declare one.
//
// `addDraft2019` is layered on top: it registers string `format` validators
// introduced in draft 2019-09 (idn-email, iri, duration, ...) without
// changing the dialect itself.
//
// @see src/CanvasServiceProvider.php
const DRAFT_07_META = 'http://json-schema.org/draft-07/schema#';

export function createAjv(): Ajv {
  const ajv = new Ajv({ defaultMeta: DRAFT_07_META });
  addFormats(ajv);
  addDraft2019(ajv);
  return ajv;
}
