import { describe, expect, it } from 'vitest';

import componentMetadataSchema from '../../../component-metadata.schema.json';
import componentMocksSchema from '../../workbench/src/lib/schemas/component-mocks.schema.json';
import contentTemplateSpecSchema from '../../workbench/src/lib/schemas/content-template-spec.schema.json';
import pageSpecSchema from '../../workbench/src/lib/schemas/page-spec.schema.json';
import pageTemplateSpecSchema from '../../workbench/src/lib/schemas/page-template-spec.schema.json';
import { createCanvasAjv } from './index';

const schemas = {
  'component-metadata.schema.json': componentMetadataSchema,
  'packages/workbench/src/lib/schemas/component-mocks.schema.json':
    componentMocksSchema,
  'packages/workbench/src/lib/schemas/content-template-spec.schema.json':
    contentTemplateSpecSchema,
  'packages/workbench/src/lib/schemas/page-spec.schema.json': pageSpecSchema,
  'packages/workbench/src/lib/schemas/page-template-spec.schema.json':
    pageTemplateSpecSchema,
};

describe('createCanvasAjv', () => {
  it.each(Object.entries(schemas))('strictly compiles %s', (_path, schema) => {
    expect(() => createCanvasAjv().compile(schema)).not.toThrow();
  });

  it('uses strict schema checks by default but permits explicit overrides', () => {
    const schema = { type: 'string', 'x-target-annotation': true };

    expect(() => createCanvasAjv().compile(schema)).toThrow('strict mode');
    expect(() =>
      createCanvasAjv({ strictSchema: false }).compile(schema),
    ).not.toThrow();
  });

  it('supports draft 2019 string formats without changing the dialect', () => {
    const validate = createCanvasAjv().compile({
      $schema: 'http://json-schema.org/draft-07/schema#',
      type: 'string',
      format: 'idn-email',
    });

    // cspell:ignore δοκιμή παράδειγμα
    expect(validate('δοκιμή@παράδειγμα.δοκιμή')).toBe(true);
    expect(validate('not an email')).toBe(false);
  });
});
