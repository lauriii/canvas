'use client';

import { usePathname, useRouter } from 'next/navigation';
import { DraftSession as ReactDraftSession } from '@drupal-canvas/headless-react';

import type { ReactNode } from 'react';
import type { DraftSessionProps as ReactDraftSessionProps } from '@drupal-canvas/headless-react';

export type { DraftSessionSnapshot } from '@drupal-canvas/headless-react';

/**
 * The Next.js wiring is exactly what the shared component leaves open:
 * the router's pathname and its server-data refresh.
 */
export type DraftSessionProps = Omit<
  ReactDraftSessionProps,
  'path' | 'refreshData'
>;

/**
 * The Next.js <DraftSession>: the shared React component from
 * @drupal-canvas/headless-react bound to the App Router — usePathname()
 * keeps the host's status reports and the renew link on the current page,
 * and router.refresh() re-renders the server tree after a renewal, so the
 * renewed session arrives as new props (new cookie, new tokenExpiresAt,
 * re-armed machine).
 */
export function DraftSession(props: DraftSessionProps): ReactNode {
  const router = useRouter();
  const pathname = usePathname();
  return (
    <ReactDraftSession
      {...props}
      path={pathname}
      refreshData={() => router.refresh()}
    />
  );
}
