import { RuleTester } from 'eslint';
import yamlParser from 'yaml-eslint-parser';

import rule from '../src/rules/component-prop-example-value-no-empty-string.js';

const testRunner = new RuleTester({
  languageOptions: {
    parser: yamlParser,
  },
});

testRunner.run('component-prop-example-value-no-empty-string rule', rule, {
  valid: [
    {
      name: 'should pass when string prop examples are non-empty',
      code: `
        name: Example
        machineName: example
        props:
          properties:
            delta:
              type: string
              title: Delta
              examples:
                - Medium
      `,
      filename: '/components/example/component.yml',
    },
    {
      name: 'should pass when array string prop examples are non-empty',
      code: `
        name: Example
        machineName: example
        props:
          properties:
            tags:
              type: array
              items:
                type: string
              examples:
                - - alpha
                  - beta
      `,
      filename: '/components/example/component.yml',
    },
    {
      name: 'should ignore non-string props',
      code: `
        name: Example
        machineName: example
        props:
          properties:
            enabled:
              type: boolean
              title: Enabled
              examples:
                - false
      `,
      filename: '/components/example/component.yml',
    },
    {
      name: 'should not be applied to non-component yml files',
      code: `
        name: Example
        machineName: example
        props:
          properties:
            delta:
              type: string
              title: Delta
              examples:
                - ''
      `,
      filename: '/components/example/example.yml',
    },
  ],
  invalid: [
    {
      name: 'should fail when a string prop example is an empty string',
      code: `
        name: Example
        machineName: example
        props:
          properties:
            delta:
              type: string
              title: Delta
              examples:
                - ''
      `,
      filename: '/components/example/component.yml',
      errors: [
        {
          message:
            'Prop "delta" example values must not be empty strings. Remove the empty example or use a non-empty placeholder value.',
          line: 10,
        },
      ],
    },
    {
      name: 'should fail when an array string prop example contains an empty string',
      code: `
        name: Example
        machineName: example
        props:
          properties:
            tags:
              type: array
              items:
                type: string
              examples:
                - - alpha
                  - ''
      `,
      filename: '/components/example/component.yml',
      errors: [
        {
          message:
            'Prop "tags" example values must not be empty strings. Remove the empty example or use a non-empty placeholder value.',
          line: 12,
        },
      ],
    },
    {
      name: 'should validate named component metadata files',
      code: `
        name: Example
        machineName: example
        props:
          properties:
            delta:
              type: string
              title: Delta
              examples:
                - ''
      `,
      filename: '/components/example/example.component.yml',
      errors: [
        {
          message:
            'Prop "delta" example values must not be empty strings. Remove the empty example or use a non-empty placeholder value.',
          line: 10,
        },
      ],
    },
  ],
});
