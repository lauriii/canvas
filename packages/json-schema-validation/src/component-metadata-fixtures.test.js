import { readdir, readFile } from 'node:fs/promises';
import { load as parseYaml } from 'js-yaml';
import { describe, expect, it } from 'vitest';

import componentMetadataSchema from '../../../component-metadata.schema.json';
import { createCanvasAjv } from './index';

const fixtureRoot = new URL(
  '../../../tests/fixtures/component-metadata/',
  import.meta.url,
);

async function loadFixtures(verdict) {
  const directory = new URL(`${verdict}/`, fixtureRoot);
  return Promise.all(
    (await readdir(directory)).map(async (fileName) => ({
      fileName,
      document: parseYaml(await readFile(new URL(fileName, directory), 'utf8')),
    })),
  );
}

const validFixtures = await loadFixtures('valid');
const invalidFixtures = await loadFixtures('invalid');

const expectedViolations = {
  'invalid-json-schema-type.yml': [
    {
      instancePath: '/props/properties/published/type',
      keyword: 'anyOf',
      params: {},
    },
    {
      instancePath: '/props/properties/published/type',
      keyword: 'enum',
      params: {
        allowedValues: [
          'array',
          'boolean',
          'integer',
          'null',
          'number',
          'object',
          'string',
        ],
      },
    },
    {
      instancePath: '/props/properties/published/type',
      keyword: 'type',
      params: { type: 'array' },
    },
  ],
  'invalid-slots-and-dependencies.yml': [
    {
      instancePath: '/dataDependencies/entityFields/reference',
      keyword: 'minItems',
      params: { limit: 1 },
    },
    {
      instancePath: '/slots/body',
      keyword: 'additionalProperties',
      params: { additionalProperty: 'extra' },
    },
    {
      instancePath: '/slots/body/title',
      keyword: 'minLength',
      params: { limit: 1 },
    },
  ],
  'missing-machine-name.yml': [
    {
      instancePath: '',
      keyword: 'required',
      params: { missingProperty: 'machineName' },
    },
  ],
  'missing-prop-title.yml': [
    {
      instancePath: '/props/properties/missingTitle',
      keyword: 'required',
      params: { missingProperty: 'title' },
    },
  ],
  'projected-prop-key.yml': [
    {
      instancePath: '/props/properties/reference/x-allowed-entity-type-id',
      keyword: 'not',
      params: {},
    },
    {
      instancePath: '/props/properties/reference/x-allowed-bundle',
      keyword: 'not',
      params: {},
    },
  ],
  'unknown-and-derived-keys.yml': [
    {
      instancePath: '',
      keyword: 'additionalProperties',
      params: { additionalProperty: 'type' },
    },
    {
      instancePath: '',
      keyword: 'additionalProperties',
      params: { additionalProperty: 'unknown' },
    },
    {
      instancePath: '/dataDependencies',
      keyword: 'additionalProperties',
      params: { additionalProperty: 'drupalSettings' },
    },
    {
      instancePath: '/dataDependencies',
      keyword: 'additionalProperties',
      params: { additionalProperty: 'urls' },
    },
  ],
};

function toViolationSignatures(errors) {
  return (errors ?? [])
    .map(({ instancePath, keyword, params }) => ({
      instancePath,
      keyword,
      params,
    }))
    .sort((a, b) => JSON.stringify(a).localeCompare(JSON.stringify(b)));
}

describe('component metadata fixtures', () => {
  const validate = createCanvasAjv({ allErrors: true }).compile(
    componentMetadataSchema,
  );

  it.each(validFixtures)('accepts $fileName', ({ document }) => {
    expect(validate(document), JSON.stringify(validate.errors, null, 2)).toBe(
      true,
    );
  });

  it.each(invalidFixtures)('rejects $fileName', ({ document, fileName }) => {
    expect(validate(document), fileName).toBe(false);
    expect(toViolationSignatures(validate.errors)).toEqual(
      expectedViolations[fileName].sort((a, b) =>
        JSON.stringify(a).localeCompare(JSON.stringify(b)),
      ),
    );
  });
});
