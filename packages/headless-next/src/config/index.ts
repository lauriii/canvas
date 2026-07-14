/**
 * @file
 * The next.config entry. Deliberately separate from the package root: this
 * module is loaded by Next's config loader outside any request scope, so it
 * must never import next/headers or next/navigation (both bind to
 * request-scoped async storage), which the root entry's server modules do.
 */

export { withCanvas, type WithCanvasOptions } from '../with-canvas';
