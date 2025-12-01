import { RuleTester } from 'eslint';
import { vi } from 'vitest';
import yamlParser from 'yaml-eslint-parser';

import rule from '../src/rules/component-no-hierarchy.js';

vi.mock('node:fs', () => ({
  existsSync: vi.fn(() => true),
  readdirSync: vi.fn((dir) => {
    const directories = {
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
              'All component directories must be at the same level with no nesting hierarchy. Found "input" component inside the "/src/components/form" directory.',
            line: 1,
          },
        ],
      },
    ],
  },
);
