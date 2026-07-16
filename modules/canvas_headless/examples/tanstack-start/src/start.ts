import { createStart } from '@tanstack/react-start'
import { cspMiddleware } from '@drupal-canvas/headless-tanstack-start/middleware'

/**
 * Global request middleware: the SDK's session-aware CSP frame-ancestors
 * header.
 */
export const startInstance = createStart(() => ({
  requestMiddleware: [cspMiddleware],
}))
