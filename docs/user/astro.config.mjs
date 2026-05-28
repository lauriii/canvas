// @ts-check
import { defineConfig } from 'astro/config';
import starlight from '@astrojs/starlight';

// https://astro.build/config
export default defineConfig({
  trailingSlash: 'never',
  integrations: [
    starlight({
      title: 'Drupal Canvas',
      social: [
        {
          icon: 'gitlab',
          label: 'GitLab',
          href: 'https://git.drupalcode.org/project/canvas/',
        },
        {
          icon: 'slack',
          label: 'Slack',
          href: 'https://drupal.slack.com/archives/C072JMEPUS1',
        },
        {
          icon: 'bun',
          label: 'drupal.org',
          href: 'https://www.drupal.org/project/canvas',
        },
      ],
      sidebar: [
        {
          label: 'Code Components',
          items: [
            { label: 'Introduction', slug: 'code-components' },
            { label: 'Concepts', slug: 'code-components/concepts' },
            { label: 'Local codebase', slug: 'code-components/local-codebase' },
            {
              label: 'Imports and assets',
              slug: 'code-components/imports-and-assets',
            },
            { label: 'Built-in packages', slug: 'code-components/packages' },
            { label: 'Data fetching', slug: 'code-components/data-fetching' },
            {
              label: 'Responsive images',
              slug: 'code-components/responsive-images',
            },
            { label: 'Brand Kit', slug: 'code-components/brand-kit' },
            {
              label: 'Component metadata',
              slug: 'code-components/component-metadata',
            },
            {
              label: 'Workbench',
              items: [
                { label: 'Introduction', slug: 'code-components/workbench' },
                { label: 'Mocks', slug: 'code-components/workbench/mocks' },
                { label: 'Pages', slug: 'code-components/workbench/pages' },
                {
                  label: 'Content templates',
                  slug: 'code-components/workbench/content-templates',
                },
                {
                  label: 'Global regions',
                  slug: 'code-components/workbench/regions',
                },
              ],
            },
          ],
        },
        {
          label: 'SDC components',
          items: [
            { label: 'Introduction', slug: 'sdc-components' },
            { label: 'Props', slug: 'sdc-components/props' },
            { label: 'Slots', slug: 'sdc-components/slots' },
            { label: 'Image', slug: 'sdc-components/image' },
            {
              label: 'Validations',
              slug: 'sdc-components/validations',
            },
            { label: 'Troubleshooting', slug: 'sdc-components/troubleshooting' },
          ],
        },
        {
          label: 'AI assistant',
          items: [
            { label: 'Introduction', slug: 'ai-assistant' }
          ],
        },
        {
          label: 'APIs',
          items: [
            { label: 'Introduction', slug: 'apis' },
            { label: 'Customizing forms', slug: 'apis/customizing-forms' },
            { label: 'Theme settings', slug: 'apis/theme-settings' },
          ],
        }
      ],
    }),
  ],
  base: process.env.ASTRO_BASE || undefined,
  site: process.env.ASTRO_SITE || undefined,
});
