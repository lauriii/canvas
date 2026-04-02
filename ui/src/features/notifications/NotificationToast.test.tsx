import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import NotificationToast from './NotificationToast';

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

describe('NotificationToast', () => {
  it('renders title and message', () => {
    render(
      <NotificationToast
        notification={make({ title: 'Hello', message: 'World' })}
        onDismiss={vi.fn()}
        onAction={vi.fn()}
      />,
    );
    expect(screen.getByText('Hello')).toBeInTheDocument();
    expect(screen.getByText('World')).toBeInTheDocument();
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
      const { container, unmount } = render(
        <NotificationToast
          notification={make({ type })}
          onDismiss={vi.fn()}
          onAction={vi.fn()}
        />,
      );
      const card = container.firstElementChild as HTMLElement;
      expect(card.dataset.type).toBe(type);
      unmount();
    }
  });

  it('renders action links without pipe separator', () => {
    render(
      <NotificationToast
        notification={make({
          actions: [
            { label: 'View', href: '/view' },
            { label: 'Retry', href: '/retry' },
          ],
        })}
        onDismiss={vi.fn()}
        onAction={vi.fn()}
      />,
    );
    expect(screen.getByText('View')).toBeInTheDocument();
    expect(screen.getByText('Retry')).toBeInTheDocument();
    expect(screen.queryByText('|')).not.toBeInTheDocument();
  });

  it('clicking dismiss calls onDismiss with notification id', async () => {
    const user = userEvent.setup();
    const onDismiss = vi.fn();
    render(
      <NotificationToast
        notification={make({ id: 'abc' })}
        onDismiss={onDismiss}
        onAction={vi.fn()}
      />,
    );
    await user.click(
      screen.getByRole('button', { name: 'Dismiss notification' }),
    );
    expect(onDismiss).toHaveBeenCalledWith('abc');
  });

  it('clicking action calls onAction with id and href', async () => {
    const user = userEvent.setup();
    const onAction = vi.fn();
    render(
      <NotificationToast
        notification={make({
          id: 'xyz',
          actions: [{ label: 'View', href: '/view' }],
        })}
        onDismiss={vi.fn()}
        onAction={onAction}
      />,
    );
    await user.click(screen.getByText('View'));
    expect(onAction).toHaveBeenCalledWith('xyz', '/view');
  });

  it('applies spin class on processing icon', () => {
    const { container } = render(
      <NotificationToast
        notification={make({ type: 'processing' })}
        onDismiss={vi.fn()}
        onAction={vi.fn()}
      />,
    );
    const card = container.firstElementChild as HTMLElement;
    const iconWrapper = card.querySelector('[data-type="processing"]');
    expect(iconWrapper?.className).toContain('spin');
  });
});
