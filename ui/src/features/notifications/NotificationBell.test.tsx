import { Provider } from 'react-redux';
import { MemoryRouter, useLocation } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { makeStore } from '@/app/store';
import { getCanvasSettings } from '@/utils/drupal-globals';

import NotificationBell from './NotificationBell';

import type { Notification } from '@/services/notificationsApi';
import type {
  PendingChange,
  PendingChanges,
} from '@/services/pendingChangesApi';

vi.mock('@assets/icons/bell.svg?react', () => ({
  default: (props: any) => <svg data-testid="bell-icon" {...props} />,
}));

vi.mock('@/utils/drupal-globals', async () => {
  const actual = await vi.importActual('@/utils/drupal-globals');
  return {
    ...actual,
    getCanvasSettings: vi.fn(),
    getBaseUrl: vi.fn().mockReturnValue('/'),
    getDrupal: vi.fn().mockReturnValue({}),
    getDrupalSettings: vi.fn().mockReturnValue({ canvas: {} }),
  };
});

vi.mock('@/hooks/useDocumentVisibility', () => ({
  useDocumentVisibility: vi.fn().mockReturnValue(true),
}));

const mockGetCanvasSettings = vi.mocked(getCanvasSettings);
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
  updated: Math.floor(Date.now() / 1000),
  hasConflict: true,
  conflict_id: '17',
};

const renderWithStore = () => {
  const store = makeStore();
  return {
    store,
    ...render(
      <Provider store={store}>
        <MemoryRouter initialEntries={['/editor']}>
          <NotificationBell />
          <LocationDisplay />
        </MemoryRouter>
      </Provider>,
    ),
  };
};

function LocationDisplay() {
  const location = useLocation();
  return <div data-testid="current-location">{location.pathname}</div>;
}

describe('NotificationBell', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    window.localStorage.clear();
    mockNotifications = [];
    mockPendingChanges = undefined;
  });

  it('renders bell icon when devMode is true', () => {
    mockGetCanvasSettings.mockReturnValue({ devMode: true } as any);
    renderWithStore();
    expect(screen.getByLabelText('Notifications')).toBeInTheDocument();
  });

  it('does not render when devMode is false', () => {
    mockGetCanvasSettings.mockReturnValue({ devMode: false } as any);
    renderWithStore();
    expect(screen.queryByLabelText('Notifications')).not.toBeInTheDocument();
  });

  it('does not render when canvasSettings is undefined', () => {
    mockGetCanvasSettings.mockReturnValue(undefined as any);
    renderWithStore();
    expect(screen.queryByLabelText('Notifications')).not.toBeInTheDocument();
  });

  it('shows conflict notification in the badge and Activity Center', async () => {
    const user = userEvent.setup();
    mockGetCanvasSettings.mockReturnValue({ devMode: true } as any);
    mockPendingChanges = {
      'canvas_page:2:en': conflictChange,
    };
    renderWithStore();

    expect(screen.getByText('1')).toBeInTheDocument();

    await user.click(screen.getByLabelText('Notifications'));

    expect(screen.getByText('Conflict detected')).toBeInTheDocument();
    expect(screen.getByText('Resolve conflicts')).toBeInTheDocument();

    await user.click(screen.getByText('Resolve conflicts'));

    expect(screen.getByTestId('current-location')).toHaveTextContent(
      '/conflict/canvas_page/2',
    );
    expect(mockMarkRead).not.toHaveBeenCalled();
  });
});
