'use client';

import { DraftSession as ReactDraftSession } from '@drupal-canvas/headless-react';
import { useLocation } from '@tanstack/react-router';

import type { ReactNode } from 'react';
import type { DraftSessionProps as ReactDraftSessionProps } from '@drupal-canvas/headless-react';

export type { DraftSessionSnapshot } from '@drupal-canvas/headless-react';

/**
 * The TanStack Start wiring is the router's pathname; data refresh is
 * deliberately left to the shared component's in-place re-arm.
 */
export type DraftSessionProps = Omit<
  ReactDraftSessionProps,
  'path' | 'refreshData'
>;

/**
 * The TanStack Start <DraftSession>: the shared React component from
 * @drupal-canvas/headless-react bound to TanStack Router — useLocation()
 * keeps the host's status reports and the renew link on the current page.
 *
 * No refreshData is wired: after a renewal the component re-arms in place
 * from the renew response's tokenExpiresAt, independent of the app's
 * loader structure and caching. Route loaders re-run naturally on the
 * next navigation, and the session cookie they read already carries the
 * renewed token.
 */
export function DraftSession(props: DraftSessionProps): ReactNode {
  const pathname = useLocation({ select: (location) => location.pathname });
  return <ReactDraftSession {...props} path={pathname} />;
}
