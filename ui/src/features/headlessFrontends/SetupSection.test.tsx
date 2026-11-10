import { beforeEach, describe, expect, it, vi } from 'vitest';
import { Theme } from '@radix-ui/themes';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { getSiteName, getSiteUrl } from '@/utils/drupal-globals';

import SetupSection from './SetupSection';

vi.mock('@/utils/drupal-globals', () => ({
  getSiteName: vi.fn(),
  getSiteUrl: vi.fn(),
}));

const PACKAGE_DOCS_BASE_URL =
  'https://git.drupalcode.org/project/canvas/-/tree/1.x/packages';

describe('SetupSection', () => {
  beforeEach(() => {
    vi.mocked(getSiteUrl).mockReturnValue('https://drupal.example');
    vi.mocked(getSiteName).mockReturnValue('My site');
  });

  it('lists the frameworks in order', () => {
    render(
      <Theme>
        <SetupSection packageManager="npm" onPackageManagerChange={vi.fn()} />
      </Theme>,
    );

    expect(
      within(screen.getByTestId('canvas-headless-framework-select'))
        .getAllByRole('radio')
        .map((radio) => radio.textContent),
    ).toEqual(['Next.js', 'Astro', 'Nuxt', 'TanStack Start', 'Angular']);
  });

  it('includes the site URL, site name, and template in the create command', () => {
    render(
      <Theme>
        <SetupSection packageManager="npm" onPackageManagerChange={vi.fn()} />
      </Theme>,
    );

    expect(
      screen.getByTestId('canvas-headless-create-command'),
    ).toHaveTextContent(
      'npx @drupal-canvas/create@latest --site-url https://drupal.example --site-name "My site" --template nextjs',
    );
  });

  it('uses the selected package manager for the create command', () => {
    render(
      <Theme>
        <SetupSection packageManager="pnpm" onPackageManagerChange={vi.fn()} />
      </Theme>,
    );

    expect(
      screen.getByTestId('canvas-headless-create-command'),
    ).toHaveTextContent(
      'pnpm dlx @drupal-canvas/create@latest --site-url https://drupal.example --site-name "My site" --template nextjs',
    );
  });

  it.each([
    ['Astro', 'astro'],
    ['Nuxt', 'nuxt'],
    ['TanStack Start', 'tanstack-start'],
    ['Angular', 'angular'],
  ])(
    'uses the selected %s framework for the create command',
    async (framework, template) => {
      const user = userEvent.setup();

      render(
        <Theme>
          <SetupSection packageManager="npm" onPackageManagerChange={vi.fn()} />
        </Theme>,
      );

      await user.click(screen.getByRole('radio', { name: framework }));

      expect(
        screen.getByTestId('canvas-headless-create-command'),
      ).toHaveTextContent(`--template ${template}`);
    },
  );

  it('escapes shell-expanded characters in the site name', () => {
    vi.mocked(getSiteName).mockReturnValue('The "best" $ite `here`');

    render(
      <Theme>
        <SetupSection packageManager="npm" onPackageManagerChange={vi.fn()} />
      </Theme>,
    );

    expect(
      screen.getByTestId('canvas-headless-create-command'),
    ).toHaveTextContent('--site-name "The \\"best\\" \\$ite \\`here\\`"');
  });

  it('omits the site name flag when no site name is available', () => {
    vi.mocked(getSiteName).mockReturnValue(undefined);

    render(
      <Theme>
        <SetupSection packageManager="npm" onPackageManagerChange={vi.fn()} />
      </Theme>,
    );

    expect(
      screen.getByTestId('canvas-headless-create-command'),
    ).toHaveTextContent(
      'npx @drupal-canvas/create@latest --site-url https://drupal.example --template nextjs',
    );
    expect(
      screen.getByTestId('canvas-headless-create-command'),
    ).not.toHaveTextContent('--site-name');
  });

  it('renders the base command when no site information is available', () => {
    vi.mocked(getSiteUrl).mockReturnValue(undefined);
    vi.mocked(getSiteName).mockReturnValue(undefined);

    render(
      <Theme>
        <SetupSection packageManager="npm" onPackageManagerChange={vi.fn()} />
      </Theme>,
    );

    expect(
      screen.getByTestId('canvas-headless-create-command'),
    ).toHaveTextContent(
      /^npx @drupal-canvas\/create@latest --template nextjs$/,
    );
  });

  it.each([
    ['Next.js', 'headless-next', true],
    ['Nuxt', 'headless-nuxt', true],
    ['Astro', 'headless-astro', true],
    ['TanStack Start', 'headless-tanstack-start', true],
    // The Angular adapter README has no caching section.
    ['Angular', 'headless-angular', false],
  ])(
    'shows the %s adapter install command and links its guides',
    async (framework, path, hasCachingDocs) => {
      const user = userEvent.setup();

      render(
        <Theme>
          <SetupSection packageManager="npm" onPackageManagerChange={vi.fn()} />
        </Theme>,
      );

      await user.click(screen.getByRole('radio', { name: framework }));
      await user.click(
        screen.getByTestId('canvas-headless-setup-existing-tab-select'),
      );

      expect(
        screen.getByTestId('canvas-headless-install-command'),
      ).toHaveTextContent(`npm install @drupal-canvas/${path}`);
      expect(
        screen.getByTestId('canvas-headless-adapter-docs-link'),
      ).toHaveAttribute('href', `${PACKAGE_DOCS_BASE_URL}/${path}`);

      if (hasCachingDocs) {
        expect(screen.getByText('Cache published pages')).toBeVisible();
        expect(
          screen.getByTestId('canvas-headless-caching-docs-link'),
        ).toHaveAttribute(
          'href',
          `${PACKAGE_DOCS_BASE_URL}/${path}#caching-published-pages`,
        );
      } else {
        expect(
          screen.queryByText('Cache published pages'),
        ).not.toBeInTheDocument();
      }
    },
  );
});
