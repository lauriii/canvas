import { describe, expect, it, vi } from 'vitest';
import { Provider as TooltipProvider } from '@radix-ui/react-tooltip';
import { Theme } from '@radix-ui/themes';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import FrontendsList from './FrontendsList';

import type { HeadlessFrontend } from './types';

vi.mock('./checkFrontendConnection', () => ({
  checkFrontendConnection: vi.fn(() => new Promise(() => {})),
}));

const NOW = Math.floor(Date.now() / 1000);

const FAILING = {
  state: 'failing',
  lastSuccess: NOW - 3600,
  lastFailure: NOW - 300,
  lastError: 'HTTP 500',
} as const;

const renderList = (
  frontend: Partial<HeadlessFrontend>,
  retryingCacheInvalidation: string | null = null,
  more: HeadlessFrontend[] = [],
) => {
  const props = {
    onOpenSetupGuide: vi.fn(),
    onRetryCacheInvalidation: vi.fn(),
    onExpireCachedPages: vi.fn(),
    onRemove: vi.fn(),
  };
  render(
    <Theme>
      <TooltipProvider delayDuration={0}>
        <FrontendsList
          frontends={[
            {
              id: 'https://frontend.example',
              url: 'https://frontend.example',
              status: 'ready',
              cacheInvalidationStatus: null,
              ...frontend,
            },
            ...more,
          ]}
          onAdd={vi.fn()}
          onReorder={vi.fn()}
          onStatusChange={vi.fn()}
          retryingCacheInvalidation={retryingCacheInvalidation}
          disabled={false}
          {...props}
        />
      </TooltipProvider>
    </Theme>,
  );
  return props;
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

  it('shows nothing about content updates for a frontend that never received one', () => {
    renderList({});

    expect(screen.getByText('Ready')).toBeVisible();
    expect(
      screen.queryByRole('button', { name: /content updates/ }),
    ).toBeNull();
  });

  it('confirms on the ready status that content updates arrive, also from the keyboard', async () => {
    const user = userEvent.setup();
    renderList({
      cacheInvalidationStatus: {
        state: 'ok',
        // Drupal's clock runs ahead of the browser's.
        lastSuccess: NOW + 60,
        lastFailure: null,
        lastError: null,
      },
    });

    await user.tab();
    await user.tab();
    expect(screen.getByText('Ready')).toHaveFocus();

    expect(await screen.findByRole('tooltip')).toHaveTextContent(
      'Cached pages expire when content changes. Last update received less than a minute ago.',
    );
  });

  it('replaces the ready status of a frontend that stopped receiving content updates, and retries', async () => {
    const user = userEvent.setup();
    const { onRetryCacheInvalidation } = renderList({
      cacheInvalidationStatus: FAILING,
    });

    expect(screen.queryByText('Ready')).toBeNull();
    const retry = screen.getByRole('button', {
      name: 'Not receiving content updates',
    });
    await user.hover(retry);
    expect(await screen.findByRole('tooltip')).toHaveTextContent(
      'This frontend stopped receiving content updates, so it may show outdated pages. Canvas keeps trying, and tells it to expire its cached pages once it responds. Click to try now. Last failure: 5 minutes ago. HTTP 500.',
    );

    await user.click(retry);
    expect(onRetryCacheInvalidation).toHaveBeenCalledExactlyOnceWith(
      'https://frontend.example',
    );
  });

  it('says when updates stopped arriving if no error is known', async () => {
    const user = userEvent.setup();
    renderList({ cacheInvalidationStatus: { ...FAILING, lastError: null } });

    await user.hover(screen.getByRole('button', { name: /content updates/ }));

    const tooltip = await screen.findByRole('tooltip');
    expect(tooltip).toHaveTextContent(/Last failure: 5 minutes ago\.$/);
  });

  it('offers to expire the cached pages of a frontend that receives content updates', async () => {
    const user = userEvent.setup();
    const { onExpireCachedPages, onRemove } = renderList({
      cacheInvalidationStatus: { ...FAILING, state: 'ok' },
    });

    await user.click(screen.getByRole('button', { name: 'Frontend actions' }));
    await user.click(
      await screen.findByRole('menuitem', { name: 'Expire cached pages' }),
    );
    expect(onExpireCachedPages).toHaveBeenCalledExactlyOnceWith(
      'https://frontend.example',
    );
    expect(onRemove).not.toHaveBeenCalled();
  });

  it('only offers to remove a frontend that never received a content update', async () => {
    const user = userEvent.setup();
    const { onRemove } = renderList({});

    await user.click(screen.getByRole('button', { name: 'Frontend actions' }));
    expect(
      (await screen.findAllByRole('menuitem')).map((item) => item.textContent),
    ).toEqual(['Remove']);
    await user.click(screen.getByRole('menuitem', { name: 'Remove' }));
    expect(onRemove).toHaveBeenCalledExactlyOnceWith(
      'https://frontend.example',
    );
  });

  it('retries one frontend at a time', () => {
    renderList({ cacheInvalidationStatus: FAILING }, 'https://other.example', [
      {
        id: 'https://other.example',
        url: 'https://other.example',
        status: 'ready',
        cacheInvalidationStatus: FAILING,
      },
    ]);

    // The row being retried shows a spinner; the other keeps its label, and
    // waits.
    const [waiting, retrying] = screen.getAllByRole('button', {
      name: /content updates/,
    });
    expect(waiting).toBeDisabled();
    expect(retrying).toBeDisabled();
    expect(waiting.querySelector('.rt-Spinner')).toBeNull();
    expect(retrying.querySelector('.rt-Spinner')).not.toBeNull();
  });

  it.each([
    ['checking', 'Checking…'],
    ['unreachable', 'Unreachable'],
    ['setup-needed', 'Setup needed'],
  ] as const)(
    'keeps the %s status of a frontend the browser does not find ready',
    (status, label) => {
      renderList({ status, cacheInvalidationStatus: FAILING });

      expect(screen.getByText(label)).toBeVisible();
      expect(
        screen.queryByRole('button', { name: /content updates/ }),
      ).toBeNull();
    },
  );
});
