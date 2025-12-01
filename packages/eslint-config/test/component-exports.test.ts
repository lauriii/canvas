import { RuleTester } from 'eslint';
import { vi } from 'vitest';

import rule from '../src/rules/component-exports.js';

const testRunner = new RuleTester({
  languageOptions: {
    ecmaVersion: 2022,
    sourceType: 'module',
    parserOptions: {
      ecmaFeatures: {
        jsx: true,
      },
    },
  },
});

// Mock fs to test isInComponentDir used in component-exports rule.
vi.mock('node:fs', () => ({
  existsSync: vi.fn(() => true),
  readdirSync: vi.fn((dir) => {
    const dirs = {
      '/components/button': ['component.yml', 'index.jsx', 'index.css'],
      '/src/utils': ['utils.js'],
    };
    return dirs[dir] ?? [];
  }),
}));

testRunner.run('component-exports rule', rule, {
  valid: [
    {
      name: 'should pass when component has default export',
      code: `
        const Button = ({ title }) => {
          return <button>{title}</button>;
        };
        export default Button;
      `,
      filename: '/components/button/index.jsx',
    },
    {
      name: 'should pass when component has inline default export',
      code: `
        export default function Button({ title }) {
          return <button>{title}</button>;
        }
      `,
      filename: '/components/button/index.jsx',
    },
    {
      name: 'should pass when component has arrow function default export',
      code: `
        export default ({ title }) => {
          return <button>{title}</button>;
        };
      `,
      filename: '/components/button/index.jsx',
    },
    {
      name: 'should pass when component has default export and named exports',
      code: `
        const Button = ({ title }) => {
          return <button>{title}</button>;
        };
        export default Button;
        export { Button };
      `,
      filename: '/components/button/index.jsx',
    },
    {
      name: 'should not apply to scripts outside components',
      code: `
        import { clsx } from "clsx";
        import { twMerge } from "tailwind-merge";

        export function cn(...inputs) {
          return twMerge(clsx(inputs));
        }
      `,
      filename: '/src/lib/utils.js',
    },
  ],
  invalid: [
    {
      name: 'should fail for component with only named export',
      code: `
        const Button = ({ title }) => {
          return <button>{title}</button>;
        };
        export { Button };
      `,
      filename: '/components/button/index.jsx',
      errors: [
        {
          message: 'Component must have a default export',
          line: 2,
        },
      ],
    },
    {
      name: 'should fail for component with no exports',
      code: `
        const Button = ({ title }) => {
          return <button>{title}</button>;
        };
      `,
      filename: '/components/button/index.jsx',
      errors: [
        {
          message: 'Component must have a default export',
          line: 2,
        },
      ],
    },
  ],
});
