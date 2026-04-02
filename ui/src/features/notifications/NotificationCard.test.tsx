import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import NotificationCard from './NotificationCard';

import type { Notification } from '@/services/notificationsApi';

const baseNotification: Notification = {
  id: '1',
  type: 'info',
  key: null,
  title: 'Test title',
  message: 'Test message',
  timestamp: Date.now() - 60000,
  hasRead: false,
  actions: null,
};

const make = (overrides: Partial<Notification> = {}): Notification => ({
  ...baseNotification,
  ...overrides,
});

describe('NotificationCard', () => {
  it('renders correct background for warning', () => {
    const { container } = render(
      <NotificationCard
        notification={make({ type: 'warning' })}
        onMarkRead={vi.fn()}
      />,
    );
    const card = container.firstElementChild as HTMLElement;
    expect(card.dataset.type).toBe('warning');
  });

  it('renders correct background for error', () => {
    const { container } = render(
      <NotificationCard
        notification={make({ type: 'error' })}
        onMarkRead={vi.fn()}
      />,
    );
    const card = container.firstElementChild as HTMLElement;
    expect(card.dataset.type).toBe('error');
  });

  it('renders correct data-type for each notification type', () => {
    const types: Notification['type'][] = [
      'info',
      'warning',
      'error',
      'success',
      'processing',
    ];
    for (const type of types) {
      const { container } = render(
        <NotificationCard notification={make({ type })} onMarkRead={vi.fn()} />,
      );
      const card = container.firstElementChild as HTMLElement;
      expect(card.dataset.type).toBe(type);
    }
  });

  it('shows unread dot for unread info, warning, and error', () => {
    for (const type of ['info', 'warning', 'error'] as const) {
      const { unmount } = render(
        <NotificationCard
          notification={make({ type, hasRead: false })}
          onMarkRead={vi.fn()}
        />,
      );
      expect(
        screen.getByRole('button', { name: 'Mark as read' }),
      ).toBeInTheDocument();
      unmount();
    }
  });

  it('hides dot for read notifications', () => {
    for (const type of ['info', 'warning', 'error'] as const) {
      const { unmount } = render(
        <NotificationCard
          notification={make({ type, hasRead: true })}
          onMarkRead={vi.fn()}
        />,
      );
      expect(
        screen.queryByRole('button', { name: 'Mark as read' }),
      ).not.toBeInTheDocument();
      unmount();
    }
  });

  it('hides dot for success and processing', () => {
    for (const type of ['success', 'processing'] as const) {
      const { unmount } = render(
        <NotificationCard notification={make({ type })} onMarkRead={vi.fn()} />,
      );
      expect(
        screen.queryByRole('button', { name: 'Mark as read' }),
      ).not.toBeInTheDocument();
      unmount();
    }
  });

  it('calls onMarkRead when clicking the dot', async () => {
    const user = userEvent.setup();
    const onMarkRead = vi.fn();
    render(
      <NotificationCard
        notification={make({ id: 'abc' })}
        onMarkRead={onMarkRead}
      />,
    );
    await user.click(screen.getByRole('button', { name: /mark as/i }));
    expect(onMarkRead).toHaveBeenCalledWith('abc');
  });

  it('renders action links separated by pipe', () => {
    render(
      <NotificationCard
        notification={make({
          actions: [
            { label: 'View', href: '/view' },
            { label: 'Retry', href: '/retry' },
          ],
        })}
        onMarkRead={vi.fn()}
      />,
    );
    expect(screen.getByText('View')).toBeInTheDocument();
    expect(screen.getByText('Retry')).toBeInTheDocument();
    expect(screen.getByText('|')).toBeInTheDocument();
  });

  it('renders timestamp', () => {
    render(
      <NotificationCard
        notification={make({ timestamp: Date.now() - 120000 })}
        onMarkRead={vi.fn()}
      />,
    );
    expect(screen.getByText('2m ago')).toBeInTheDocument();
  });

  it('applies spin class on processing icon', () => {
    const { container } = render(
      <NotificationCard
        notification={make({ type: 'processing' })}
        onMarkRead={vi.fn()}
      />,
    );
    // The card and the icon div both have data-type. The icon is nested inside the card.
    const card = container.firstElementChild as HTMLElement;
    const iconWrapper = card.querySelector('[data-type="processing"]');
    expect(iconWrapper?.className).toContain('spin');
  });
});
