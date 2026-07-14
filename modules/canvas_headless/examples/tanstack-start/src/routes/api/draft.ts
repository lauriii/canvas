import { createFileRoute } from '@tanstack/react-router'
import { createDraftRouteHandlers } from '@drupal-canvas/headless-tanstack-start'

const { draft } = createDraftRouteHandlers()

/**
 * Draft-mode activation: redeems the `?assertion=` preview URL Drupal
 * minted and redirects to the signed entry path.
 */
export const Route = createFileRoute('/api/draft')({
  server: { handlers: { GET: draft.GET } },
})
