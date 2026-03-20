import { RuleTester } from 'eslint';
import tseslint from 'typescript-eslint';
import { vi } from 'vitest';

import rule from '../src/rules/component-exports.js';

const testRunner = new RuleTester({
  languageOptions: {
    parser: tseslint.parser,
    ecmaVersion: 2022,
    sourceType: 'module',
    parserOptions: {
      ecmaFeatures: {
        jsx: true,
      },
    },
  },
});

// Mock fs to test isComponentDir used in component-exports rule.
vi.mock('node:fs', () => ({
  existsSync: vi.fn(() => true),
  readdirSync: vi.fn((dir) => {
    const dirs: Record<string, string[]> = {
      '/components/button': ['component.yml', 'index.jsx', 'index.css'],
      '/components/card': ['card.component.yml', 'index.jsx'],
      '/components/heading': ['component.yml', 'index.tsx'],
      '/components/alert': ['alert.component.yml', 'alert.tsx'],
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
      name: 'named-style: should pass when component has default export',
      code: `
        const Card = ({ title }) => {
          return <div>{title}</div>;
        };
        export default Card;
      `,
      filename: '/components/card/index.jsx',
    },
    {
      name: 'should pass when tsx component has default export',
      code: `
        const Heading = ({ title }) => {
          return <h1>{title}</h1>;
        };
        export default Heading;
      `,
      filename: '/components/heading/index.tsx',
    },
    {
      name: 'named-style: should pass when tsx component has default export',
      code: `
        interface AlertProps {
          message: string;
        }
        const Alert = ({ message }: AlertProps) => {
          return <div role="alert">{message}</div>;
        };
        export default Alert;
      `,
      filename: '/components/alert/alert.tsx',
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
    {
      name: 'should fail when tsx component has no default export',
      code: `
        export const Heading = ({ title }) => {
          return <h1>{title}</h1>;
        };
      `,
      filename: '/components/heading/index.tsx',
      errors: [
        {
          message: 'Component must have a default export',
          line: 2,
        },
      ],
    },
    {
      name: 'named-style: should fail for component with no default export',
      code: `
        export const Card = ({ title }) => {
          return <div>{title}</div>;
        };
      `,
      filename: '/components/card/card.jsx',
      errors: [
        {
          message: 'Component must have a default export',
          line: 2,
        },
      ],
    },
  ],
});
