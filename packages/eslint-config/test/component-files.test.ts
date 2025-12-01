import { RuleTester } from 'eslint';
import { describe, vi } from 'vitest';
import yamlParser from 'yaml-eslint-parser';

import rule from '../src/rules/component-files.js';

const testRunner = new RuleTester({
  languageOptions: {
    parser: yamlParser,
  },
});

vi.mock('node:fs', () => ({
  existsSync: vi.fn(() => true),
  readdirSync: vi.fn((dir) => {
    const dirs = {
      '/components/button': ['component.yml', 'index.jsx', 'index.css'],
      '/components/button2': ['component.yml', 'index.jsx'],
      '/components/button3': ['component.yml'],
      '/components/button4': ['component.yml', 'index.jsx', 'dist'],
      '/components/button5': ['component.yml', 'index.jsx', 'disallowed.js'],
      '/components/button6': ['component.yml', 'index.jsx', 'disallowed.js'],
      '/components/button7': [
        'component.yml',
        'index.jsx',
        'test.js',
        'README.md',
        'package.json',
      ],
      '/components/button8': [
        'component.yml',
        'index.jsx',
        '.gitignore',
        '.DS_Store',
      ],
      '/components/button9': ['component.yml', 'index.jsx', 'dist', 'build.js'],
      '/components/button10': ['component.yml', 'index.jsx', 'assets', 'utils'],
    };
    return dirs[dir] ?? [];
  }),
}));

describe('component-files rule', () => {
  testRunner.run(
    'should pass when component directory contains only allowed files',
    rule,
    {
      valid: [
        {
          name: 'Button 1',
          code: `
              name: Button 1
              machineName: button
            `,
          filename: '/components/button/component.yml',
        },
      ],
      invalid: [],
    },
  );

  testRunner.run(
    'should pass when component directory contains only required files',
    rule,
    {
      valid: [
        {
          name: 'Button 2',
          code: `
          name: Button 2
          machineName: button2
        `,
          filename: '/components/button2/component.yml',
        },
      ],
      invalid: [],
    },
  );

  testRunner.run(
    'should fail when component directory does not contain required index.jsx file',
    rule,
    {
      valid: [],
      invalid: [
        {
          name: 'Button 3',
          code: `
            name: Button 3
            machineName: button3
          `,
          filename: '/components/button3/component.yml',
          errors: [
            {
              message: 'Missing required component file: index.jsx.',
              line: 1,
            },
          ],
        },
      ],
    },
  );

  testRunner.run('should ignore dist directory', rule, {
    valid: [
      {
        name: 'Button 4',
        code: `
          name: Button 4
          machineName: button4
        `,
        filename: '/components/button4/component.yml',
      },
    ],
    invalid: [],
  });

  testRunner.run(
    'should not apply to directories that do not contain component.yml',
    rule,
    {
      valid: [
        {
          name: 'Button 5',
          code: `
            name: Button 5
            machineName: button5
          `,
          filename: '/components/button5/button.yml',
        },
        {
          name: 'Button 6',
          code: `
            name: Button 6
            machineName: button6
          `,
          filename: '/components/button6/button.component.yml',
        },
      ],
      invalid: [],
    },
  );

  testRunner.run(
    'should fail when component directory contains disallowed files',
    rule,
    {
      valid: [],
      invalid: [
        {
          name: 'Button 7',
          code: `
            name: Button 7
            machineName: button7
          `,
          filename: '/components/button7/component.yml',
          errors: [
            {
              message:
                'Component directory contains disallowed files: test.js, README.md, package.json. ' +
                'Only the following files are allowed: component.yml, index.jsx, index.css. ' +
                'Other files will be overwritten by the "canvas download" command.',
              line: 1,
            },
          ],
        },
      ],
    },
  );

  testRunner.run(
    'should fail when component directory contains hidden file',
    rule,
    {
      valid: [],
      invalid: [
        {
          name: 'Button 8',
          code: `
            name: Button 8
            machineName: button8
          `,
          filename: '/components/button8/component.yml',
          errors: [
            {
              message:
                'Component directory contains disallowed files: .gitignore, .DS_Store. ' +
                'Only the following files are allowed: component.yml, index.jsx, index.css. ' +
                'Other files will be overwritten by the "canvas download" command.',
              line: 1,
            },
          ],
        },
      ],
    },
  );

  testRunner.run(
    'should ignore dist directory but report other disallowed files',
    rule,
    {
      valid: [],
      invalid: [
        {
          name: 'Button 9',
          code: `
            name: Button 9
            machineName: button9
          `,
          filename: '/components/button9/component.yml',
          errors: [
            {
              message:
                'Component directory contains disallowed files: build.js. ' +
                'Only the following files are allowed: component.yml, index.jsx, index.css. ' +
                'Other files will be overwritten by the "canvas download" command.',
              line: 1,
            },
          ],
        },
      ],
    },
  );

  testRunner.run(
    'should fail when component directory contains subdirectories (other than dist)',
    rule,
    {
      valid: [],
      invalid: [
        {
          name: 'Button 10',
          code: `
            name: Button 10
            machineName: button10
          `,
          filename: '/components/button10/component.yml',
          errors: [
            {
              message:
                'Component directory contains disallowed files: assets, utils. ' +
                'Only the following files are allowed: component.yml, index.jsx, index.css. ' +
                'Other files will be overwritten by the "canvas download" command.',
              line: 1,
            },
          ],
        },
      ],
    },
  );
});
