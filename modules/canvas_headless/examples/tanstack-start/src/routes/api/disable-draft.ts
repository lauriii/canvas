import { createFileRoute } from '@tanstack/react-router'
import { createDraftRouteHandlers } from '@drupal-canvas/headless-tanstack-start'

const { disableDraft } = createDraftRouteHandlers()

/**
 * Draft-mode exit. POST, not GET: exiting changes state (it clears the
 * session cookies), and a GET endpoint reached by links would be eligible
 * for prefetching — a framework or browser prefetch could silently end
 * the session.
 */
export const Route = createFileRoute('/api/disable-draft')({
  server: { handlers: { POST: disableDraft.POST } },
})
