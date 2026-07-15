import { describe, expect, it, vi } from 'vitest';
import { Theme } from '@radix-ui/themes';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import SetupSection from './SetupSection';

const PACKAGE_DOCS_BASE_URL =
  'https://git.drupalcode.org/project/canvas/-/tree/1.x/packages';

describe('SetupSection', () => {
  it.each([
    ['Next.js', 'headless-next'],
    ['Nuxt', 'headless-nuxt'],
    ['Astro', 'headless-astro'],
    ['TanStack Start', 'headless-tanstack-start'],
  ])('links the %s guide to its package README', async (framework, path) => {
    const user = userEvent.setup();

    render(
      <Theme>
        <SetupSection packageManager="npm" onPackageManagerChange={vi.fn()} />
      </Theme>,
    );

    await user.click(
      screen.getByTestId('canvas-headless-setup-existing-tab-select'),
    );
    await user.click(screen.getByRole('combobox', { name: 'Framework' }));
    await user.click(await screen.findByRole('option', { name: framework }));

    expect(
      screen.getByTestId('canvas-headless-adapter-docs-link'),
    ).toHaveAttribute('href', `${PACKAGE_DOCS_BASE_URL}/${path}`);
  });
});
