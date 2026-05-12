import { RuleTester } from 'eslint';
import { vi } from 'vitest';
import yamlParser from 'yaml-eslint-parser';

import rule from '../src/rules/component-no-hierarchy.js';

vi.mock('node:fs', () => ({
  existsSync: vi.fn((filePath) => filePath !== '/default/canvas.config.json'),
  readFileSync: vi.fn((filePath) => {
    const configs: Record<string, string> = {
      '/valid/canvas.config.json': JSON.stringify({
        componentDir: 'src/components',
      }),
      '/invalid/canvas.config.json': JSON.stringify({
        componentDir: 'src/components',
      }),
      '/custom/canvas.config.json': JSON.stringify({
        componentDir: 'code-components',
      }),
    };
    return configs[filePath] ?? JSON.stringify({});
  }),
  readdirSync: vi.fn((dir) => {
    const directories: Record<string, string[]> = {
      '/valid': ['src'],
      '/valid/src': ['components'],
      '/valid/src/components': ['button', 'card', 'modal', 'nested'],
      '/valid/src/components/button': ['component.yml', 'index.jsx'],
      '/valid/src/components/card': ['component.yml', 'index.jsx'],
      '/valid/src/components/modal': ['component.yml', 'index.jsx'],

      '/invalid': ['src'],
      '/invalid/src': ['components'],
      '/invalid/src/components': ['button', 'form'],
      '/invalid/src/components/button': ['component.yml', 'index.jsx'],
      '/invalid/src/components/form': ['component.yml', 'index.jsx', 'input'],
      '/invalid/src/components/form/input': ['component.yml', 'index.jsx'],
      '/invalid/src/components/marketing': ['heading'],
      '/invalid/src/components/marketing/heading': [
        'component.yml',
        'index.jsx',
      ],
    };
    return directories[dir] ?? [];
  }),
}));

const cwd = vi.spyOn(process, 'cwd');

cwd.mockReturnValue('/valid');
const validTestRunner = new RuleTester({
  languageOptions: {
    parser: yamlParser,
  },
});
validTestRunner.run(
  'component-no-hierarchy rule - should pass for flat component structure',
  rule,
  {
    valid: [
      {
        name: 'components at same level - button',
        code: `
        name: Button
        machineName: button
      `,
        filename: '/valid/src/components/button/component.yml',
      },
      {
        name: 'components at same level - card',
        code: `
        name: Card
        machineName: card
      `,
        filename: '/valid/src/components/card/component.yml',
      },
      {
        name: 'components at same level - modal',
        code: `
        name: Modal
        machineName: modal
      `,
        filename: '/valid/src/components/modal/component.yml',
      },
      {
        name: 'named component directly in componentDir',
        code: `
        name: Icon
        machineName: icon
      `,
        filename: '/valid/src/components/icon.component.yml',
      },
    ],
    invalid: [],
  },
);

cwd.mockReturnValue('/invalid');
const invalidTestRunner = new RuleTester({
  languageOptions: {
    parser: yamlParser,
  },
});
invalidTestRunner.run(
  'component-no-hierarchy rule - should fail for hierarchical component structures',
  rule,
  {
    valid: [
      {
        name: 'components at same level - button',
        code: `
        name: Button
        machineName: button
      `,
        filename: '/invalid/src/components/button/component.yml',
      },
    ],
    invalid: [
      {
        name: 'nested component - form/input',
        code: `
        name: Input
        machineName: input
      `,
        filename: '/invalid/src/components/form/input/component.yml',
        errors: [
          {
            message:
              'Component directories must be direct children of configured componentDir "src/components". Found "input" inside "src/components/form".',
            line: 1,
          },
        ],
      },
      {
        name: 'component nested inside grouping folder',
        code: `
        name: Heading
        machineName: heading
      `,
        filename: '/invalid/src/components/marketing/heading/component.yml',
        errors: [
          {
            message:
              'Component directories must be direct children of configured componentDir "src/components". Found "heading" inside "src/components/marketing".',
            line: 1,
          },
        ],
      },
      {
        name: 'nested component path with Windows separators',
        code: `
        name: Field
        machineName: field
      `,
        filename: '/invalid/src/components/form/input\\field/component.yml',
        errors: [
          {
            message:
              'Component directories must be direct children of configured componentDir "src/components". Found "input\\field" inside "src/components/form".',
            line: 1,
          },
        ],
      },
    ],
  },
);

cwd.mockReturnValue('/custom');
const customComponentDirTestRunner = new RuleTester({
  languageOptions: {
    parser: yamlParser,
  },
});
customComponentDirTestRunner.run(
  'component-no-hierarchy rule - should respect configured componentDir',
  rule,
  {
    valid: [
      {
        name: 'component directly in custom componentDir',
        code: `
        name: Hero
        machineName: hero
      `,
        filename: '/custom/code-components/hero/component.yml',
      },
    ],
    invalid: [
      {
        name: 'component nested inside grouping folder in custom componentDir',
        code: `
        name: Hero
        machineName: hero
      `,
        filename: '/custom/code-components/marketing/hero/component.yml',
        errors: [
          {
            message:
              'Component directories must be direct children of configured componentDir "code-components". Found "hero" inside "code-components/marketing".',
            line: 1,
          },
        ],
      },
    ],
  },
);

cwd.mockReturnValue('/default');
const defaultConfigTestRunner = new RuleTester({
  languageOptions: {
    parser: yamlParser,
  },
});
defaultConfigTestRunner.run(
  'component-no-hierarchy rule - should use default config when no config file exists',
  rule,
  {
    valid: [
      {
        name: 'component directly in default componentDir',
        code: `
        name: Button
        machineName: button
      `,
        filename: '/default/src/components/button/component.yml',
      },
    ],
    invalid: [
      {
        name: 'component nested inside grouping folder in default componentDir',
        code: `
        name: Heading
        machineName: heading
      `,
        filename: '/default/src/components/marketing/heading/component.yml',
        errors: [
          {
            message:
              'Component directories must be direct children of configured componentDir "src/components". Found "heading" inside "src/components/marketing".',
            line: 1,
          },
        ],
      },
    ],
  },
);
