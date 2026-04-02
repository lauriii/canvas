import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import ActivityCenter from './ActivityCenter';

import type { Notification } from '@/services/notificationsApi';

const make = (
  overrides: Partial<Notification> & { id: string },
): Notification => ({
  type: 'info',
  key: null,
  title: 'Test',
  message: 'Test message',
  timestamp: 1000,
  hasRead: false,
  actions: null,
  ...overrides,
});

describe('ActivityCenter', () => {
  it('renders "Activity Center" header', () => {
    render(
      <ActivityCenter
        notifications={[]}
        onClose={vi.fn()}
        onMarkAllRead={vi.fn()}
        onMarkRead={vi.fn()}
      />,
    );
    expect(screen.getByText('Activity Center')).toBeInTheDocument();
  });

  it('renders notification cards', () => {
    const notifications = [
      make({ id: '1', title: 'First' }),
      make({ id: '2', title: 'Second' }),
    ];
    render(
      <ActivityCenter
        notifications={notifications}
        onClose={vi.fn()}
        onMarkAllRead={vi.fn()}
        onMarkRead={vi.fn()}
      />,
    );
    expect(screen.getByText('First')).toBeInTheDocument();
    expect(screen.getByText('Second')).toBeInTheDocument();
  });

  it('shows empty state when no notifications', () => {
    render(
      <ActivityCenter
        notifications={[]}
        onClose={vi.fn()}
        onMarkAllRead={vi.fn()}
        onMarkRead={vi.fn()}
      />,
    );
    expect(screen.getByText('No notifications yet')).toBeInTheDocument();
    expect(
      screen.getByText('New notifications will appear here'),
    ).toBeInTheDocument();
  });

  it('"Mark all as read" calls onMarkAllRead', async () => {
    const user = userEvent.setup();
    const onMarkAllRead = vi.fn();
    render(
      <ActivityCenter
        notifications={[make({ id: '1' })]}
        onClose={vi.fn()}
        onMarkAllRead={onMarkAllRead}
        onMarkRead={vi.fn()}
      />,
    );
    await user.click(screen.getByText('Mark all as read'));
    expect(onMarkAllRead).toHaveBeenCalledOnce();
  });

  it('hides "Mark all as read" when there are no notifications', () => {
    render(
      <ActivityCenter
        notifications={[]}
        onClose={vi.fn()}
        onMarkAllRead={vi.fn()}
        onMarkRead={vi.fn()}
      />,
    );
    expect(screen.queryByText('Mark all as read')).not.toBeInTheDocument();
  });
});
