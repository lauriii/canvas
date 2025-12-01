import { RuleTester } from 'eslint';
import yamlParser from 'yaml-eslint-parser';

import rule from '../src/rules/component-dir-name.js';

const testRunner = new RuleTester({
  languageOptions: {
    parser: yamlParser,
  },
});

testRunner.run('component-dir-name rule', rule, {
  valid: [
    {
      name: 'should pass when directory name matches machineName',
      code: `
        name: Button
        machineName: button
      `,
      filename: '/components/button/component.yml',
    },
    {
      name: 'should only be applied to component.yml files',
      code: `
        name: Button
        machineName: button
      `,
      filename: '/components/button/button.component.yml',
    },
    {
      name: 'should only be applied to component.yml files',
      code: `
        name: Button
        machineName: button
      `,
      filename: '/components/button/button.yml',
    },
  ],
  invalid: [
    {
      name: 'should fail when directory name does not match machineName',
      code: `
        name: Button
        machineName: button
        `,
      filename: '/components/card/component.yml',
      errors: [
        {
          message:
            'Component directory name "card" does not match machineName "button" from component.yml.',
          line: 3,
        },
      ],
    },
    {
      name: 'should fail when directory name casing does not match machineName',
      code: `
        name: Button
        machineName: Button
      `,
      filename: '/components/button/component.yml',
      errors: [
        {
          message:
            'Component directory name "button" does not match machineName "Button" from component.yml.',
          line: 3,
        },
      ],
    },
    {
      name: 'should fail when machineName value is missing',
      code: `
        name: Button
        machineName:
      `,
      filename: '/components/button/component.yml',
      errors: [
        {
          message: 'machineName must be a string.',
          line: 3,
        },
      ],
    },
    {
      name: 'should fail when machineName key is missing',
      code: `name: Button`,
      filename: '/components/button/component.yml',
      errors: [
        {
          message:
            'machineName key is missing. Its value should match the directory name: "button".',
          line: 1,
        },
      ],
    },
    {
      name: 'should fail when machineName value is an empty string',
      code: `
        name: Button
        machineName: ""
      `,
      filename: '/components/button/component.yml',
      errors: [
        {
          message: 'machineName must be a string.',
          line: 3,
        },
      ],
    },
    {
      name: 'should fail when machineName value is a number',
      code: `
        name: Button
        machineName: 123
      `,
      filename: '/components/button/component.yml',
      errors: [
        {
          message: 'machineName must be a string.',
          line: 3,
        },
      ],
    },
    {
      name: 'should fail when machineName value is a boolean',
      code: `
        name: Button
        machineName: true
      `,
      filename: '/components/button/component.yml',
      errors: [
        {
          message: 'machineName must be a string.',
          line: 3,
        },
      ],
    },
    {
      name: 'should fail when machineName value is an object',
      code: `
        name: Button
        machineName:
          key: value
      `,
      filename: '/components/button/component.yml',
      errors: [
        {
          message: 'machineName must be a string.',
          line: 3,
        },
      ],
    },
    {
      name: 'should fail when machineName value is an array',
      code: `
        name: Button
        machineName:
          - button
      `,
      filename: '/components/button/component.yml',
      errors: [
        {
          message: 'machineName must be a string.',
          line: 3,
        },
      ],
    },
  ],
});
