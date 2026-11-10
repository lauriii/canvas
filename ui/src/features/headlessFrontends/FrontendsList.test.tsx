import { describe, expect, it, vi } from 'vitest';
import { Provider as TooltipProvider } from '@radix-ui/react-tooltip';
import { Theme } from '@radix-ui/themes';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import FrontendsList from './FrontendsList';

import type { ComponentProps } from 'react';
import type { CacheInvalidationStatus } from '@/services/headlessFrontends';
import type { HeadlessFrontend } from './types';

vi.mock('./checkFrontendConnection', () => ({
  checkFrontendConnection: vi.fn(() => new Promise(() => {})),
}));

const URL = 'https://frontend.example';

const STATUS: CacheInvalidationStatus = {
  state: 'idle',
  lastSuccess: null,
  lastFailure: null,
  lastError: null,
  failures: 0,
};

const renderList = (
  frontend: Partial<HeadlessFrontend> = {},
  props: Partial<ComponentProps<typeof FrontendsList>> = {},
) => {
  const handlers = {
    onAdd: vi.fn(),
    onReorder: vi.fn(),
    onRemove: vi.fn(),
    onStatusChange: vi.fn(),
    onCacheInvalidationChange: vi.fn(),
    onTestCacheInvalidation: vi.fn(),
    onOpenSetupGuide: vi.fn(),
  };
  render(
    <Theme>
      <TooltipProvider delayDuration={0}>
        <FrontendsList
          frontends={[
            {
              id: URL,
              url: URL,
              status: 'ready',
              cacheInvalidation: false,
              cacheInvalidationStatus: null,
              ...frontend,
            },
          ]}
          {...handlers}
          disabled={false}
          isTesting={false}
          {...props}
        />
      </TooltipProvider>
    </Theme>,
  );
  return handlers;
};

describe('FrontendsList', () => {
  it('opens the setup guide from the setup-needed tooltip', async () => {
    const user = userEvent.setup();
    const { onOpenSetupGuide } = renderList({ status: 'setup-needed' });

    await user.hover(screen.getByText('Setup needed'));
    await user.click(
      await screen.findByRole('link', {
        name: 'setup guide',
      }),
    );

    expect(onOpenSetupGuide).toHaveBeenCalledOnce();
  });

  it('turns cache invalidation on from the switch', async () => {
    const user = userEvent.setup();
    const { onCacheInvalidationChange } = renderList();

    const toggle = screen.getByRole('switch', {
      name: 'Cache invalidation',
    });
    expect(toggle).not.toBeChecked();
    expect(screen.getByText('Cache invalidation')).toBeVisible();
    // Nothing to report or test while cache invalidation is off.
    expect(screen.queryByText('Waiting for changes')).not.toBeInTheDocument();
    expect(
      screen.queryByRole('button', { name: 'Test cache invalidation' }),
    ).not.toBeInTheDocument();

    await user.click(toggle);

    expect(onCacheInvalidationChange).toHaveBeenCalledExactlyOnceWith(
      URL,
      true,
    );
  });

  it('turns cache invalidation off from the switch', async () => {
    const user = userEvent.setup();
    const { onCacheInvalidationChange } = renderList({
      cacheInvalidation: true,
      cacheInvalidationStatus: STATUS,
    });

    await user.click(screen.getByRole('switch'));

    expect(onCacheInvalidationChange).toHaveBeenCalledExactlyOnceWith(
      URL,
      false,
    );
  });

  it('tests and expires all from an enabled frontend', async () => {
    const user = userEvent.setup();
    const { onTestCacheInvalidation } = renderList({
      cacheInvalidation: true,
      cacheInvalidationStatus: STATUS,
    });

    expect(screen.getByRole('switch')).toBeChecked();

    const test = screen.getByRole('button', {
      name: 'Test cache invalidation',
    });
    expect(test).toHaveTextContent('Test');
    await user.click(test);
    expect(onTestCacheInvalidation).toHaveBeenLastCalledWith(URL, false);

    const expireAll = screen.getByRole('button', {
      name: 'Expire all cached pages',
    });
    expect(expireAll).toHaveTextContent('Expire all');
    await user.click(expireAll);
    expect(onTestCacheInvalidation).toHaveBeenLastCalledWith(URL, true);
    expect(onTestCacheInvalidation).toHaveBeenCalledTimes(2);
  });

  it('does not start a second test while one is running', () => {
    renderList(
      { cacheInvalidation: true, cacheInvalidationStatus: STATUS },
      { isTesting: true },
    );

    expect(
      screen.getByRole('button', { name: 'Test cache invalidation' }),
    ).toBeDisabled();
    expect(
      screen.getByRole('button', { name: 'Expire all cached pages' }),
    ).toBeDisabled();
    expect(screen.getByRole('switch')).toBeEnabled();
  });

  it.each([
    ['an idle frontend', STATUS],
    // Drupal has not reported a status yet, right after the switch is turned on.
    ['a frontend without a status', null],
  ])('shows %s as waiting for changes', (_, cacheInvalidationStatus) => {
    renderList({ cacheInvalidation: true, cacheInvalidationStatus });

    expect(screen.getByText('Waiting for changes')).toBeVisible();
  });

  it('shows when an active frontend last read an update', async () => {
    const user = userEvent.setup();
    renderList({
      cacheInvalidation: true,
      cacheInvalidationStatus: {
        ...STATUS,
        state: 'ok',
        lastSuccess: Math.floor(Date.now() / 1000) - 300,
      },
    });

    await user.hover(screen.getByText('Active'));

    expect(await screen.findByRole('tooltip')).toHaveTextContent(
      'Last update read 5 minutes ago',
    );
  });

  it('never shows a time in the future', async () => {
    const user = userEvent.setup();
    renderList({
      cacheInvalidation: true,
      cacheInvalidationStatus: {
        ...STATUS,
        state: 'ok',
        // Drupal's clock runs ahead of the browser's.
        lastSuccess: Math.floor(Date.now() / 1000) + 60,
      },
    });

    await user.hover(screen.getByText('Active'));

    expect(await screen.findByRole('tooltip')).toHaveTextContent(
      'Last update read less than a minute ago',
    );
  });

  it('explains why a frontend is failing', async () => {
    const user = userEvent.setup();
    renderList({
      cacheInvalidation: true,
      cacheInvalidationStatus: {
        state: 'failing',
        lastSuccess: null,
        lastFailure: Math.floor(Date.now() / 1000) - 7200,
        lastError: 'HTTP 404',
        failures: 3,
      },
    });

    // The tooltip also opens from the keyboard.
    await user.tab();
    await user.tab();
    await user.tab();
    expect(screen.getByText('Failing')).toHaveFocus();

    expect(await screen.findByRole('tooltip')).toHaveTextContent(
      'HTTP 404. Last failure: about 2 hours ago. The frontend is told to expire everything once it is reachable.',
    );
  });
});
