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
            "Relative component imports are not supported. Use '@/components/[component-name]' instead of '../icon'.",
          line: 2,
        },
      ],
    },
    {
      name: 'should fail for component importing third party packages',
      code: `
        import { FiArrowRight } from "react-icons/fi";
        const Button = ({ title }) => {
          return <button>{title} <FiArrowRight /></button>;
        };
      `,
      filename: '/components/button/index.jsx',
      errors: [
        {
          message:
            'Importing "react-icons/fi" is not supported. If this is a local import via a path alias, use the "@/components/" alias instead. If you are importing a third-party package, see the list of supported packages at https://project.pages.drupalcode.org/canvas/code-components/packages. (The status of supporting any third-party package can be tracked at https://drupal.org/i/3560197.)',
          line: 2,
        },
      ],
    },
    {
      name: 'should fail for component importing non-whitelisted @/lib/ packages',
      code: `
        import { someFn } from "@/lib/some-lib";
        export default ({ title }) => {
          return <button>{title}</button>;
        };
      `,
      filename: '/components/button/index.jsx',
      errors: [
        {
          message:
            'Importing "@/lib/some-lib" is not supported. If this is a local import via a path alias, use the "@/components/" alias instead. If you are importing a third-party package, see the list of supported packages at https://project.pages.drupalcode.org/canvas/code-components/packages. (The status of supporting any third-party package can be tracked at https://drupal.org/i/3560197.)',
          line: 2,
        },
      ],
    },
    {
      name: 'should fail for component importing deprecated packages',
      code: `
        import FormattedText from '@/lib/FormattedText';
        import Text from '@/lib/FormattedText';
        import { JsonApiClient } from '@drupal-api-client/json-api-client';
        import Image from 'next-image-standalone';
        import NextImage from 'next-image-standalone';
        import { getNodePath, sortMenu } from '@/lib/jsonapi-utils';
        import { cn } from '@/lib/utils';
        import { getPageData, getSiteData } from '@/lib/drupal-utils';
        export default ({ title }) => {
          return <button>{title}</button>;
        };
      `,
      filename: '/components/button/index.jsx',
      errors: [
        {
          message:
            'The `FormattedText` component was moved into the `drupal-canvas` package.',
          line: 2,
        },
        {
          message:
            'The `FormattedText` component was moved into the `drupal-canvas` package.',
          line: 3,
        },
        {
          message:
            'The preconfigured `JsonApiClient` was moved into the `drupal-canvas` package.',
          line: 4,
        },
        {
          message:
            'Using `next-image-standalone` directly is deprecated. Use the `Image` component from the `drupal-canvas` package instead.',
          line: 5,
        },
        {
          message:
            'Using `next-image-standalone` directly is deprecated. Use the `Image` component from the `drupal-canvas` package instead.',
          line: 6,
        },
        {
          message:
            'JSON:API utilities were moved into the `drupal-canvas` package.',
          line: 7,
        },
        {
          message: 'Utilities were moved into the `drupal-canvas` package.',
          line: 8,
        },
        {
          message:
            'Drupal utilities were moved into the `drupal-canvas` package.',
          line: 9,
        },
      ],
      output: `
        import { FormattedText } from 'drupal-canvas';
        import Text from '@/lib/FormattedText';
        import { JsonApiClient } from 'drupal-canvas';
        import { Image } from 'drupal-canvas';
        import NextImage from 'next-image-standalone';
        import { getNodePath, sortMenu } from 'drupal-canvas';
        import { cn } from 'drupal-canvas';
        import { getPageData, getSiteData } from 'drupal-canvas';
        export default ({ title }) => {
          return <button>{title}</button>;
        };
      `,
    },
    {
      name: 'should not automatically change @/lib/drupal-utils to drupal-canvas if sortMenu is imported',
      code: `
        import { getPageData, getSiteData, sortMenu } from '@/lib/drupal-utils';
        import { sortMenu as sortLinksetMenu } from '@/lib/drupal-utils';
        export default ({ title }) => {
          return <button>{title}</button>;
        };
      `,
      filename: '/components/button/index.jsx',
      errors: [
        {
          message:
            'Drupal utilities were moved into the `drupal-canvas` package.',
          line: 2,
        },
        {
          message:
            'Drupal utilities were moved into the `drupal-canvas` package.',
          line: 3,
        },
      ],
    },
  ],
});
