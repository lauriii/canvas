import { createFileRoute } from '@tanstack/react-router'
import { createComponentMetadataHandlers } from '@drupal-canvas/headless-tanstack-start'

const { GET } = createComponentMetadataHandlers()

/**
 * The component metadata endpoint: answers this codebase's component
 * registry (every component.yml under components/canvas/) to the embedding
 * Drupal Canvas site, protected by proof-by-redemption.
 */
export const Route = createFileRoute('/api/canvas/components')({
  server: { handlers: { GET } },
})
