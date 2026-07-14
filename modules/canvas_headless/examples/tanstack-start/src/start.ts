import { createStart } from '@tanstack/react-start'
import { cspMiddleware } from '@drupal-canvas/headless-tanstack-start/middleware'

/**
 * Global request middleware: the SDK's CSP frame-ancestors header,
 * restricting who may embed this app to DRAFT_ALLOWED_FRAME_ANCESTORS
 * (plus 'self').
 */
export const startInstance = createStart(() => ({
  requestMiddleware: [cspMiddleware],
}))
