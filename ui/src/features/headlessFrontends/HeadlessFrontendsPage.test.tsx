import { toast } from 'sonner';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { Provider as TooltipProvider } from '@radix-ui/react-tooltip';
import { Theme } from '@radix-ui/themes';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import HeadlessFrontendsPage from './HeadlessFrontendsPage';

import type {
  CacheInvalidationTestResult,
  FrontendInput,
  StoredFrontend,
} from '@/services/headlessFrontends';

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

vi.mock('./checkFrontendConnection', () => ({
  checkFrontendConnection: vi.fn(() => new Promise(() => {})),
}));

vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}));

const STORED: StoredFrontend[] = [
  {
    url: 'https://one.example.com',
    components: ['card'],
    cacheInvalidation: true,
    cacheInvalidationStatus: {
      state: 'idle',
      lastSuccess: null,
      lastFailure: null,
      lastError: null,
      failures: 0,
    },
  },
  {
    url: 'https://two.example.com',
    components: [],
    cacheInvalidation: false,
    cacheInvalidationStatus: null,
  },
];

const TEST_RESULT: CacheInvalidationTestResult = {
  ok: true,
  announced: true,
  read: true,
  message: 'The frontend received the announcement and read the cache tags.',
};

const renderPage = () =>
  render(
    <Theme>
      <TooltipProvider>
        <HeadlessFrontendsPage />
      </TooltipProvider>
    </Theme>,
  );

describe('HeadlessFrontendsPage', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mocks.frontends = STORED;
    mocks.setFrontends.mockImplementation((frontends: FrontendInput[]) => ({
      unwrap: () => Promise.resolve(frontends),
    }));
    mocks.testCacheInvalidation.mockReturnValue({
      unwrap: () => Promise.resolve(TEST_RESULT),
    });
  });

  it('saves the list, then tests the frontend, when cache invalidation is turned on', async () => {
    const user = userEvent.setup();
    renderPage();

    await user.click(screen.getAllByRole('switch')[1]);

    await waitFor(() =>
      expect(toast.success).toHaveBeenCalledExactlyOnceWith(
        TEST_RESULT.message,
        expect.anything(),
      ),
    );
    expect(mocks.setFrontends).toHaveBeenCalledExactlyOnceWith([
      {
        url: 'https://one.example.com',
        components: ['card'],
        cacheInvalidation: true,
      },
      {
        url: 'https://two.example.com',
        components: [],
        cacheInvalidation: true,
      },
    ]);
    expect(mocks.testCacheInvalidation).toHaveBeenCalledExactlyOnceWith({
      url: 'https://two.example.com',
      expireAll: false,
    });
    expect(mocks.setFrontends.mock.invocationCallOrder[0]).toBeLessThan(
      mocks.testCacheInvalidation.mock.invocationCallOrder[0],
    );
  });

  it('reports a failed test without turning cache invalidation off again', async () => {
    const user = userEvent.setup();
    mocks.testCacheInvalidation.mockReturnValue({
      unwrap: () =>
        Promise.resolve({
          ok: false,
          announced: false,
          read: false,
          message: 'The frontend answered HTTP 404.',
        }),
    });
    renderPage();

    await user.click(screen.getAllByRole('switch')[1]);

    await waitFor(() =>
      expect(toast.error).toHaveBeenCalledExactlyOnceWith(
        'The frontend answered HTTP 404.',
        expect.anything(),
      ),
    );
    expect(mocks.setFrontends).toHaveBeenCalledOnce();
  });

  it('saves without testing when cache invalidation is turned off', async () => {
    const user = userEvent.setup();
    renderPage();

    await user.click(screen.getAllByRole('switch')[0]);

    await waitFor(() =>
      expect(mocks.setFrontends).toHaveBeenCalledExactlyOnceWith([
        {
          url: 'https://one.example.com',
          components: ['card'],
          cacheInvalidation: false,
        },
        {
          url: 'https://two.example.com',
          components: [],
          cacheInvalidation: false,
        },
      ]),
    );
    expect(mocks.testCacheInvalidation).not.toHaveBeenCalled();
  });

  it('does not test when the switch could not be saved', async () => {
    const user = userEvent.setup();
    mocks.setFrontends.mockReturnValue({
      unwrap: () => Promise.reject(new Error('Rejected')),
    });
    renderPage();

    await user.click(screen.getAllByRole('switch')[1]);

    await waitFor(() =>
      expect(toast.error).toHaveBeenCalledExactlyOnceWith(
        'The cache invalidation setting could not be saved. Please try again.',
        expect.anything(),
      ),
    );
    expect(mocks.testCacheInvalidation).not.toHaveBeenCalled();
  });

  it('expires all cached pages of a frontend', async () => {
    const user = userEvent.setup();
    renderPage();

    await user.click(
      screen.getByRole('button', { name: 'Expire all cached pages' }),
    );

    await waitFor(() =>
      expect(toast.success).toHaveBeenCalledExactlyOnceWith(
        TEST_RESULT.message,
        expect.anything(),
      ),
    );
    expect(mocks.testCacheInvalidation).toHaveBeenCalledExactlyOnceWith({
      url: 'https://one.example.com',
      expireAll: true,
    });
    expect(mocks.setFrontends).not.toHaveBeenCalled();
  });

  it('keeps the cache invalidation switches when a frontend is added or removed', async () => {
    const user = userEvent.setup();
    renderPage();

    await user.click(screen.getByTestId('canvas-headless-add-frontend'));
    await user.type(
      screen.getByLabelText('Frontend URL'),
      'https://three.example.com',
    );
    await user.click(
      screen.getAllByRole('button', { name: 'Add frontend' }).at(-1)!,
    );

    await waitFor(() => expect(mocks.setFrontends).toHaveBeenCalledOnce());
    expect(mocks.setFrontends).toHaveBeenLastCalledWith([
      {
        url: 'https://one.example.com',
        components: ['card'],
        cacheInvalidation: true,
      },
      {
        url: 'https://two.example.com',
        components: [],
        cacheInvalidation: false,
      },
      // A new frontend starts with cache invalidation off.
      {
        url: 'https://three.example.com',
        components: [],
        cacheInvalidation: false,
      },
    ]);

    await user.click(
      screen.getAllByRole('button', { name: 'Remove frontend' })[1],
    );
    await user.click(screen.getByRole('button', { name: 'Remove' }));

    await waitFor(() => expect(mocks.setFrontends).toHaveBeenCalledTimes(2));
    expect(mocks.setFrontends).toHaveBeenLastCalledWith([
      {
        url: 'https://one.example.com',
        components: ['card'],
        cacheInvalidation: true,
      },
    ]);
  });
});
