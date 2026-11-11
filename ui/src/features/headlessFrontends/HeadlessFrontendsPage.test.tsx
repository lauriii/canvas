import { toast } from 'sonner';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { Provider as TooltipProvider } from '@radix-ui/react-tooltip';
import { Theme } from '@radix-ui/themes';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import HeadlessFrontendsPage from './HeadlessFrontendsPage';

import type { StoredFrontend } from '@/services/headlessFrontends';

const mocks = vi.hoisted(() => ({
  frontends: [] as unknown[],
  setFrontends: vi.fn(),
  testCacheInvalidation: vi.fn(),
}));

vi.mock('@/services/headlessFrontends', () => ({
  useGetFrontendsQuery: () => ({
    data: mocks.frontends,
    isLoading: false,
    error: undefined,
  }),
  useSetFrontendsMutation: () => [mocks.setFrontends, { isLoading: false }],
  useTestCacheInvalidationMutation: () => [
    mocks.testCacheInvalidation,
    { isLoading: false },
  ],
}));

vi.mock('@/utils/drupal-globals', () => ({
  setCanvasHeadlessFrontends: vi.fn(),
}));

// The browser reaches every frontend.
vi.mock('./checkFrontendConnection', () => ({
  checkFrontendConnection: vi.fn(async () => 'ready'),
}));

vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}));

const STORED: StoredFrontend[] = [
  {
    url: 'https://one.example.com',
    components: ['card'],
    cacheInvalidationStatus: {
      state: 'failing',
      lastSuccess: 1_777_000_000,
      lastFailure: 1_777_000_600,
      lastError: 'HTTP 500',
    },
  },
  {
    url: 'https://two.example.com',
    components: [],
    cacheInvalidationStatus: null,
  },
];

const renderPage = () =>
  render(
    <Theme>
      <TooltipProvider>
        <HeadlessFrontendsPage />
      </TooltipProvider>
    </Theme>,
  );

const retry = () =>
  screen.findByRole('button', { name: 'Not receiving content updates' });

describe('HeadlessFrontendsPage', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mocks.frontends = STORED;
    mocks.setFrontends.mockImplementation((frontends: unknown[]) => ({
      unwrap: () => Promise.resolve(frontends),
    }));
  });

  it('retries a frontend that stopped receiving content updates', async () => {
    const user = userEvent.setup();
    mocks.testCacheInvalidation.mockReturnValue({
      unwrap: () => Promise.resolve({ ok: true, message: 'Read.' }),
    });
    renderPage();

    await user.click(await retry());

    expect(mocks.testCacheInvalidation).toHaveBeenCalledExactlyOnceWith({
      url: 'https://one.example.com',
      expireAll: false,
    });
    await waitFor(() =>
      expect(toast.success).toHaveBeenCalledExactlyOnceWith(
        'The frontend receives content updates again.',
        expect.anything(),
      ),
    );
  });

  it('tells a frontend to expire its cached pages', async () => {
    const user = userEvent.setup();
    mocks.testCacheInvalidation.mockReturnValue({
      unwrap: () => Promise.resolve({ ok: true, message: 'Read.' }),
    });
    renderPage();

    await user.click(
      (await screen.findAllByRole('button', { name: 'Frontend actions' }))[0],
    );
    await user.click(
      await screen.findByRole('menuitem', { name: 'Expire cached pages' }),
    );

    expect(mocks.testCacheInvalidation).toHaveBeenCalledExactlyOnceWith({
      url: 'https://one.example.com',
      expireAll: true,
    });
    await waitFor(() =>
      expect(toast.success).toHaveBeenCalledExactlyOnceWith(
        'The frontend was told to expire its cached pages.',
        expect.anything(),
      ),
    );
  });

  it('says why a retry failed', async () => {
    const user = userEvent.setup();
    mocks.testCacheInvalidation.mockReturnValue({
      unwrap: () =>
        Promise.resolve({
          ok: false,
          message: 'The frontend answered HTTP 404.',
        }),
    });
    renderPage();

    await user.click(await retry());

    await waitFor(() =>
      expect(toast.error).toHaveBeenCalledExactlyOnceWith(
        'The frontend answered HTTP 404.',
        expect.anything(),
      ),
    );
  });

  it('reports a retry that could not be sent', async () => {
    const user = userEvent.setup();
    mocks.testCacheInvalidation.mockReturnValue({
      unwrap: () => Promise.reject(new Error('Network error')),
    });
    renderPage();

    await user.click(await retry());

    await waitFor(() =>
      expect(toast.error).toHaveBeenCalledExactlyOnceWith(
        'The frontend could not be contacted. Please try again.',
        expect.anything(),
      ),
    );
  });

  it('saves the list without the status Drupal reports', async () => {
    const user = userEvent.setup();
    renderPage();

    await user.click(
      screen.getAllByRole('button', { name: 'Frontend actions' })[1],
    );
    await user.click(await screen.findByRole('menuitem', { name: 'Remove' }));
    await user.click(await screen.findByRole('button', { name: 'Remove' }));

    await waitFor(() =>
      expect(mocks.setFrontends).toHaveBeenCalledExactlyOnceWith([
        { url: 'https://one.example.com', components: ['card'] },
      ]),
    );
  });
});
