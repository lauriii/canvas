import { Provider } from 'react-redux';
import { MemoryRouter, useLocation } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { makeStore } from '@/app/store';

import NotificationToastManager from './NotificationToastManager';

import type { Notification } from '@/services/notificationsApi';
import type {
  PendingChange,
  PendingChanges,
} from '@/services/pendingChangesApi';

vi.mock('@/utils/drupal-globals', async () => {
  const actual = await vi.importActual('@/utils/drupal-globals');
  return {
    ...actual,
    getCanvasSettings: vi.fn().mockReturnValue({ devMode: true }),
    getBaseUrl: vi.fn().mockReturnValue('/'),
    getDrupal: vi.fn().mockReturnValue({}),
    getDrupalSettings: vi.fn().mockReturnValue({ canvas: {} }),
  };
});

vi.mock('@/hooks/useDocumentVisibility', () => ({
  useDocumentVisibility: vi.fn().mockReturnValue(true),
}));

let mockNotifications: Notification[] = [];
let mockPendingChanges: PendingChanges | undefined;
const mockMarkRead = vi.fn();

vi.mock('@/services/notificationsApi', async () => {
  const actual = await vi.importActual('@/services/notificationsApi');
  return {
    ...actual,
    useGetNotificationsQuery: () => ({
      data: { data: { notifications: mockNotifications } },
    }),
    useMarkNotificationsReadMutation: () => [mockMarkRead],
  };
});

vi.mock('@/services/pendingChangesApi', async () => {
  const actual = await vi.importActual('@/services/pendingChangesApi');
  return {
    ...actual,
    useGetAllPendingChangesQuery: () => ({
      data: mockPendingChanges,
    }),
  };
});

const now = Date.now();

function makeNotification(overrides: Partial<Notification> = {}): Notification {
  return {
    id: '1',
    type: 'info',
    key: null,
    title: 'Test',
    message: 'Test message',
    timestamp: now + 5000,
    hasRead: false,
    actions: null,
    ...overrides,
  };
}

function LocationDisplay() {
  const location = useLocation();
  return <div data-testid="current-location">{location.pathname}</div>;
}

function renderManager(initialEntries = ['/editor']) {
  const store = makeStore();
  return {
    store,
    ...render(
      <Provider store={store}>
        <MemoryRouter initialEntries={initialEntries}>
          <NotificationToastManager />
          <LocationDisplay />
        </MemoryRouter>
      </Provider>,
    ),
  };
}

const conflictChange: PendingChange = {
  owner: {
    name: 'Editor',
    avatar: null,
    uri: '/user/2',
    id: 2,
  },
  entity_type: 'canvas_page',
  entity_id: '2',
  data_hash: 'hash-2',
  langcode: 'en',
  label: 'Page 2',
  updated: Math.floor(now / 1000),
  hasConflict: true,
  conflict_id: '17',
};

describe('NotificationToastManager', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    window.localStorage.clear();
    mockNotifications = [];
    mockPendingChanges = undefined;
    vi.useFakeTimers({ shouldAdvanceTime: true });
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('renders toasts for new notifications arriving after pageOpenedAt', () => {
    mockNotifications = [
      makeNotification({
        id: 'new1',
        title: 'New toast',
        timestamp: now + 5000,
      }),
    ];
    renderManager();
    expect(screen.getByText('New toast')).toBeInTheDocument();
  });

  it('does not render toasts for notifications older than pageOpenedAt', () => {
    mockNotifications = [
      makeNotification({
        id: 'old1',
        title: 'Old toast',
        timestamp: now - 60000,
      }),
    ];
    renderManager();
    expect(screen.queryByText('Old toast')).not.toBeInTheDocument();
  });

  it('does not re-render a previously shown notification', async () => {
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    mockNotifications = [
      makeNotification({
        id: 'dup1',
        title: 'Dup toast',
        timestamp: now + 5000,
      }),
    ];
    const { rerender } = renderManager();

    expect(screen.getByText('Dup toast')).toBeInTheDocument();

    // Dismiss it.
    await user.click(
      screen.getByRole('button', { name: 'Dismiss notification' }),
    );

    expect(screen.queryByText('Dup toast')).not.toBeInTheDocument();

    // Re-render with same notifications — should not re-appear.
    const store = makeStore();
    rerender(
      <Provider store={store}>
        <MemoryRouter initialEntries={['/editor']}>
          <NotificationToastManager />
          <LocationDisplay />
        </MemoryRouter>
      </Provider>,
    );

    expect(screen.queryByText('Dup toast')).not.toBeInTheDocument();
  });

  it('does not toast component synchronization progress', () => {
    mockNotifications = [
      makeNotification({
        id: 'component-sync-processing',
        type: 'processing',
        key: 'headless-component-sync',
        title: 'Component sync in progress',
      }),
    ];

    renderManager();

    expect(
      screen.queryByText('Component sync in progress'),
    ).not.toBeInTheDocument();
  });

  it('replaces a keyed processing toast with its result', () => {
    mockNotifications = [
      makeNotification({
        id: 'sync-processing',
        type: 'processing',
        key: 'component-sync',
        title: 'Sync in progress',
      }),
    ];
    const { rerender } = renderManager();
    expect(screen.getByText('Sync in progress')).toBeInTheDocument();

    mockNotifications = [
      makeNotification({
        id: 'sync-complete',
        type: 'success',
        key: 'component-sync',
        title: 'Sync completed',
      }),
    ];
    const store = makeStore();
    rerender(
      <Provider store={store}>
        <MemoryRouter initialEntries={['/editor']}>
          <NotificationToastManager />
          <LocationDisplay />
        </MemoryRouter>
      </Provider>,
    );

    expect(screen.queryByText('Sync in progress')).not.toBeInTheDocument();
    expect(screen.getByText('Sync completed')).toBeInTheDocument();
  });

  it('auto-dismisses after 15 seconds', () => {
    mockNotifications = [
      makeNotification({
        id: 'auto1',
        title: 'Auto toast',
        timestamp: now + 5000,
      }),
    ];
    renderManager();
    expect(screen.getByText('Auto toast')).toBeInTheDocument();

    act(() => {
      vi.advanceTimersByTime(15000);
    });

    expect(screen.queryByText('Auto toast')).not.toBeInTheDocument();
  });

  it('auto-dismisses success notifications after 5 seconds', () => {
    mockNotifications = [
      makeNotification({
        id: 'success1',
        type: 'success',
        title: 'Success toast',
        timestamp: now + 5000,
      }),
    ];
    renderManager();
    expect(screen.getByText('Success toast')).toBeInTheDocument();

    act(() => {
      vi.advanceTimersByTime(5000);
    });

    expect(screen.queryByText('Success toast')).not.toBeInTheDocument();
  });

  it('calls markRead when dismiss button is clicked', async () => {
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    mockNotifications = [
      makeNotification({
        id: 'mark1',
        title: 'Mark toast',
        timestamp: now + 5000,
      }),
    ];
    renderManager();

    await user.click(
      screen.getByRole('button', { name: 'Dismiss notification' }),
    );
    expect(mockMarkRead).toHaveBeenCalledWith({ ids: ['mark1'] });
  });

  it('renders a conflict toast from conflicted pending changes', () => {
    mockPendingChanges = {
      'canvas_page:2:en': conflictChange,
    };
    renderManager();

    expect(screen.getByText('Conflict detected')).toBeInTheDocument();
    expect(screen.getByText('Resolve conflicts')).toBeInTheDocument();
  });

  it('does not repeat the same conflict toast after it has been shown', () => {
    mockPendingChanges = {
      'canvas_page:2:en': conflictChange,
    };
    const { rerender } = renderManager();
    expect(screen.getByText('Conflict detected')).toBeInTheDocument();

    const store = makeStore();
    rerender(
      <Provider store={store}>
        <MemoryRouter initialEntries={['/editor']}>
          <NotificationToastManager />
          <LocationDisplay />
        </MemoryRouter>
      </Provider>,
    );

    expect(screen.getAllByText('Conflict detected')).toHaveLength(1);
  });

  it('does not show a conflict toast while already on the conflict resolver', () => {
    mockPendingChanges = {
      'canvas_page:2:en': conflictChange,
    };
    renderManager(['/conflict/canvas_page/2']);

    expect(screen.queryByText('Conflict detected')).not.toBeInTheDocument();
  });

  it('does not show the same conflict after leaving the conflict resolver', () => {
    mockPendingChanges = {
      'canvas_page:2:en': conflictChange,
    };
    const { rerender } = renderManager(['/conflict/canvas_page/2']);

    const store = makeStore();
    rerender(
      <Provider store={store}>
        <MemoryRouter initialEntries={['/editor']}>
          <NotificationToastManager />
          <LocationDisplay />
        </MemoryRouter>
      </Provider>,
    );

    expect(screen.queryByText('Conflict detected')).not.toBeInTheDocument();
  });

  it('opens the conflict resolver when the conflict toast action is clicked', async () => {
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    mockPendingChanges = {
      'canvas_page:2:en': conflictChange,
    };
    renderManager();

    await user.click(screen.getByText('Resolve conflicts'));

    expect(screen.getByTestId('current-location')).toHaveTextContent(
      '/conflict/canvas_page/2',
    );
    expect(screen.queryByText('Conflict detected')).not.toBeInTheDocument();
    expect(mockMarkRead).not.toHaveBeenCalled();
  });
});
