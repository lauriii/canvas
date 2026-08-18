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
  readFileSync: vi.fn((filePath) => {
    // Only the headless project declares the Canvas Headless SDK.
    if (filePath === '/headless/package.json') {
      return JSON.stringify({
        dependencies: { '@drupal-canvas/headless-next': '^0.1.0' },
      });
    }

    return JSON.stringify({});
  }),
  readdirSync: vi.fn((dir) => {
    const dirs: Record<string, string[]> = {
      '/components/button': ['component.yml', 'index.jsx', 'index.css'],
      '/components/card': ['card.component.yml', 'index.jsx'],
      '/components/heading': ['component.yml', 'index.tsx'],
      '/components/alert': ['alert.component.yml', 'alert.tsx'],
      '/components/flat': [
        'button.component.yml',
        'button.tsx',
        'pricing-table.component.yml',
        'pricing-table.tsx',
      ],
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
    {
      name: 'flat named-style: should fail for every component entrypoint in a shared directory',
      code: `
        export const PricingTable = ({ title }) => {
          return <div>{title}</div>;
        };
      `,
      filename: '/components/flat/pricing-table.tsx',
      errors: [
        {
          message: 'Component must have a default export',
          line: 2,
        },
      ],
    },
  ],
});

// The rule is ignored when the Canvas Headless SDK is installed.
const cwd = vi.spyOn(process, 'cwd');
cwd.mockReturnValue('/headless');

const headlessTestRunner = new RuleTester({
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

headlessTestRunner.run(
  'component-exports rule ignored when headless SDK detected',
  rule,
  {
    valid: [
      {
        name: 'should pass when a component has only a named export',
        code: `
        export const Button = ({ title }) => {
          return <button>{title}</button>;
        };
      `,
        filename: '/headless/components/button/index.jsx',
      },
      {
        name: 'should pass when a component has no exports',
        code: `
        const Button = ({ title }) => {
          return <button>{title}</button>;
        };
      `,
        filename: '/headless/components/button/index.jsx',
      },
    ],
    invalid: [],
  },
);
