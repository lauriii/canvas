import { createFileRoute } from '@tanstack/react-router'
import { createDraftRouteHandlers } from '@drupal-canvas/headless-tanstack-start'

const { draftRenew } = createDraftRouteHandlers()

/**
 * In-place session renewal: POST `{assertion}`, answered with
 * `{tokenExpiresAt}`.
 */
export const Route = createFileRoute('/api/draft/renew')({
  server: { handlers: { POST: draftRenew.POST } },
})
