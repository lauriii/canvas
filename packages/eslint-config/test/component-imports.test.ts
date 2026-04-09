import { resolve } from 'path';
import { RuleTester } from 'eslint';
import { vi } from 'vitest';

import rule from '../src/rules/component-imports.js';

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

// Resolved path for @/ imports: resolve(cwd, 'src', suffix).
const componentCardDir = resolve(process.cwd(), 'src/components/card');

// Mock fs to test isComponentDir used in component-imports rule.
vi.mock('node:fs', () => ({
  existsSync: vi.fn(() => true),
  readdirSync: vi.fn((dir) => {
    const dirs: Record<string, string[]> = {
      '/components/button': ['component.yml', 'index.jsx', 'index.css'],
      [componentCardDir]: ['component.yml', 'index.tsx', 'index.css'],
      '/src/utils': ['utils.js'],
    };
    return dirs[dir] ?? [];
  }),
}));

testRunner.run('component-imports rule', rule, {
  valid: [
    {
      name: 'should pass when component imports other components using @/components/ alias',
      code: `
        import Icon from '@/components/icon';
        const Button = ({ title }) => {
          return <button>{title} <Icon icon="icon-name" /></button>;
        };
        export default Button;
      `,
      filename: '/components/button/index.jsx',
    },
    {
      name: 'should pass when component imports third party packages using full urls',
      code: `
        import { FiArrowRight } from "https://esm.sh/react-icons/fi";
        const Button = ({ title }) => {
          return <button>{title} <FiArrowRight /></button>;
        };
      `,
      filename: '/components/button/index.jsx',
    },
    {
      name: 'should pass when component imports packages bundled with Drupal Canvas',
      code: `
        import { clsx } from "clsx";
        const Button = ({ title }) => {
          return <button>{title}</button>;
        };
        export default Button;
      `,
      filename: '/components/button/index.jsx',
    },
    {
      name: 'should pass when component imports third party packages',
      code: `
        import { FiArrowRight } from "react-icons/fi";
        const Button = ({ title }) => {
          return <button>{title} <FiArrowRight /></button>;
        };
      `,
      filename: '/components/button/index.jsx',
    },
    {
      name: 'should pass when component imports @/ utils from non-component directory',
      code: `
        import { someFn } from "@/lib/some-lib";
        export default ({ title }) => {
          return <button>{title}</button>;
        };
      `,
      filename: '/components/button/index.jsx',
    },
    {
      name: 'should not apply to scripts outside components',
      code: `
        import { getComponentExamples } from "./lib/get-examples";
        const exampleSectionArgs = await getComponentExamples("section");
        const exampleHeadingArgs = await getComponentExamples("heading");
        const exampleParagraphArgs = await getComponentExamples("paragraph");
        export const Default = {
          args: {
            content: (
              <>
                <Heading {...exampleHeadingArgs[0]} />
                <Paragraph {...exampleParagraphArgs[0]} />
              </>
            ),
            ...exampleSectionArgs[0],
            backgroundColor: "base",
          },
        };
      `,
      filename: '/src/stories/section.stories.jsx',
    },
  ],
  invalid: [
    {
      name: 'should fail for component with relative path imports',
      code: `
        import Icon from '../icon';
        const Button = ({ title }) => {
          return <button>{title} <Icon icon="icon-name" /></button>;
        };
        export default Button;
      `,
      filename: '/components/button/index.jsx',
      errors: [
        {
          message:
            "Relative imports are not supported. Use '@/...' alias instead of '../icon' to import other components or helpers/utilities from shared locations outside component dir.",
          line: 2,
        },
      ],
    },
    {
      name: 'should fail for component importing @fontsource packages',
      code: `
        import "@fontsource/roboto";
        import "@fontsource-variable/open-sans";
        export default ({ title }) => {
          return <button>{title}</button>;
        };
      `,
      filename: '/components/button/index.jsx',
      errors: [
        {
          message:
            'Importing font packages ("@fontsource/roboto") is not supported in components.',
          line: 2,
        },
        {
          message:
            'Importing font packages ("@fontsource-variable/open-sans") is not supported in components.',
          line: 3,
        },
      ],
    },
    {
      name: 'should fail for component importing asset files',
      code: `
        import logo from '@/assets/logo.png';
        export default ({ title }) => {
          return <button>{title}</button>;
        };
      `,
      filename: '/components/button/index.jsx',
      errors: [
        {
          message:
            'Importing asset files ("@/assets/logo.png") is not supported in components.',
          line: 2,
        },
      ],
    },
    {
      name: 'should fail for component importing CSS files',
      code: `
        import "some-lib/css";
        export default ({ title }) => {
          return <button>{title}</button>;
        };
      `,
      filename: '/components/button/index.jsx',
      errors: [
        {
          message:
            'CSS side-effect imports are not supported in components. Remove "some-lib/css" and use the component\'s CSS file instead.',
          line: 2,
        },
      ],
    },
    {
      name: 'should fail for component importing deprecated FormattedText',
      code: `
        import FormattedText from '@/lib/FormattedText';
        import Text from '@/lib/FormattedText';
        export default ({ title }) => {
          return <button>{title}</button>;
        };
      `,
      filename: '/components/button/index.jsx',
      errors: [
        {
          message:
            'The `FormattedText` component was moved into the `drupal-canvas` package. The `@/lib/FormattedText` path is provided by Canvas and cannot be used for local files.',
          line: 2,
        },
        {
          message:
            'The `FormattedText` component was moved into the `drupal-canvas` package. The `@/lib/FormattedText` path is provided by Canvas and cannot be used for local files.',
          line: 3,
        },
      ],
      output: `
        import { FormattedText } from 'drupal-canvas';
        import Text from '@/lib/FormattedText';
        export default ({ title }) => {
          return <button>{title}</button>;
        };
      `,
    },
    {
      name: 'should fail for component importing deprecated next-image-standalone',
      code: `
        import Image from 'next-image-standalone';
        import NextImage from 'next-image-standalone';
        export default ({ title }) => {
          return <button>{title}</button>;
        };
      `,
      filename: '/components/button/index.jsx',
      errors: [
        {
          message:
            'Using `next-image-standalone` directly is deprecated. Use the `Image` component from the `drupal-canvas` package instead.',
          line: 2,
        },
        {
          message:
            'Using `next-image-standalone` directly is deprecated. Use the `Image` component from the `drupal-canvas` package instead.',
          line: 3,
        },
      ],
      output: `
        import { Image } from 'drupal-canvas';
        import NextImage from 'next-image-standalone';
        export default ({ title }) => {
          return <button>{title}</button>;
        };
      `,
    },
    {
      name: 'should fail for component importing deprecated JsonApiClient',
      code: `
        import { JsonApiClient } from '@drupal-api-client/json-api-client';
        export default ({ title }) => {
          return <button>{title}</button>;
        };
      `,
      filename: '/components/button/index.jsx',
      errors: [
        {
          message:
            'The preconfigured `JsonApiClient` was moved into the `drupal-canvas` package.',
          line: 2,
        },
      ],
      output: `
        import { JsonApiClient } from 'drupal-canvas';
        export default ({ title }) => {
          return <button>{title}</button>;
        };
      `,
    },
    {
      name: 'should fail for component importing deprecated @/lib/utils',
      code: `
        import { cn } from '@/lib/utils';
        export default ({ title }) => {
          return <button>{title}</button>;
        };
      `,
      filename: '/components/button/index.jsx',
      errors: [
        {
          message:
            'Utilities were moved into the `drupal-canvas` package. The `@/lib/utils` path is provided by Canvas and cannot be used for local files.',
          line: 2,
        },
      ],
      output: `
        import { cn } from 'drupal-canvas';
        export default ({ title }) => {
          return <button>{title}</button>;
        };
      `,
    },
    {
      name: 'should fail for component importing deprecated @/lib/jsonapi-utils',
      code: `
        import { deserialize } from '@/lib/jsonapi-utils';
        export default ({ title }) => {
          return <button>{title}</button>;
        };
      `,
      filename: '/components/button/index.jsx',
      errors: [
        {
          message:
            'JSON:API utilities were moved into the `drupal-canvas` package. The `@/lib/jsonapi-utils` path is provided by Canvas and cannot be used for local files.',
          line: 2,
        },
      ],
      output: `
        import { deserialize } from 'drupal-canvas';
        export default ({ title }) => {
          return <button>{title}</button>;
        };
      `,
    },
    {
      name: 'should fail for component importing deprecated @/lib/drupal-utils',
      code: `
        import { absoluteUrl } from '@/lib/drupal-utils';
        export default ({ title }) => {
          return <button>{title}</button>;
        };
      `,
      filename: '/components/button/index.jsx',
      errors: [
        {
          message:
            'Drupal utilities were moved into the `drupal-canvas` package. The `@/lib/drupal-utils` path is provided by Canvas and cannot be used for local files.',
          line: 2,
        },
      ],
      output: `
        import { absoluteUrl } from 'drupal-canvas';
        export default ({ title }) => {
          return <button>{title}</button>;
        };
      `,
    },
    {
      name: 'should not auto-fix @/lib/drupal-utils when sortMenu is imported',
      code: `
        import { sortMenu } from '@/lib/drupal-utils';
        export default ({ title }) => {
          return <button>{title}</button>;
        };
      `,
      filename: '/components/button/index.jsx',
      errors: [
        {
          message:
            'Drupal utilities were moved into the `drupal-canvas` package. The `@/lib/drupal-utils` path is provided by Canvas and cannot be used for local files.',
          line: 2,
        },
      ],
    },
    {
      name: 'should fail when importing util/helper from a component directory',
      code: `
        import { helper } from "@/components/card/utils";
        export default ({ title }) => {
          return <button>{title}</button>;
        };
      `,
      filename: '/components/button/index.jsx',
      errors: [
        {
          message:
            'Importing "@/components/card/utils" from a component directory is not supported. ' +
            'Use "@/" alias to import other components or helpers/utilities from shared locations outside component directories.',
          line: 2,
        },
      ],
    },
    {
      name: 'should fail when importing from a subdirectory nested inside a component directory',
      code: `
        import { helper } from "@/components/card/utils/helper";
        export default ({ title }) => {
          return <button>{title}</button>;
        };
      `,
      filename: '/components/button/index.jsx',
      errors: [
        {
          message:
            'Importing "@/components/card/utils/helper" from a component directory is not supported. ' +
            'Use "@/" alias to import other components or helpers/utilities from shared locations outside component directories.',
          line: 2,
        },
      ],
    },
  ],
});
