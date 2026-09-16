import { RuleTester } from 'eslint';
import yamlParser from 'yaml-eslint-parser';

import rule from '../src/rules/component-required-props.js';

const testRunner = new RuleTester({
  languageOptions: {
    parser: yamlParser,
  },
});

testRunner.run('component-required-props rule', rule, {
  valid: [
    {
      name: 'should pass when required props are defined with default examples',
      code: `
        name: Example
        machineName: example
        required:
          - enabled
        props:
          properties:
            enabled:
              title: Enabled
              type: boolean
              examples:
                - false
      `,
      filename: '/components/example/component.yml',
    },
    {
      name: 'should pass when no props are required',
      code: `
        name: Example
        machineName: example
      `,
      filename: '/components/example/component.yml',
    },
    {
      name: 'should not be applied to non-component yml files',
      code: `
        name: Example
        machineName: example
        required:
          - missing
      `,
      filename: '/components/example/example.yml',
    },
  ],
  invalid: [
    {
      name: 'should fail when a required prop is not defined',
      code: `
        name: Example
        machineName: example
        required:
          - missing
      `,
      filename: '/components/example/component.yml',
      errors: [
        {
          message:
            'Required prop "missing" is not defined in props.properties.',
          line: 5,
        },
      ],
    },
    {
      name: 'should fail when a required prop has no default example',
      code: `
        name: Example
        machineName: example
        required:
          - text
        props:
          properties:
            text:
              title: Text
              type: string
      `,
      filename: '/components/example/component.yml',
      errors: [
        {
          message: 'Required prop "text" must have an example value.',
          line: 8,
        },
      ],
    },
    {
      name: 'should reject a null first example in named metadata files',
      code: `
        name: Example
        machineName: example
        required:
          - text
        props:
          properties:
            text:
              title: Text
              type: string
              examples:
                - null
      `,
      filename: '/components/example/example.component.yml',
      errors: [
        {
          message: 'Required prop "text" must have an example value.',
          line: 8,
        },
      ],
    },
  ],
});
