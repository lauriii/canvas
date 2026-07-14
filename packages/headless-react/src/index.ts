/**
 * @file
 * Shared React binding for the Drupal Canvas Headless SDK: the
 * <DraftSession> client component around the framework-free state machine
 * in @drupal-canvas/headless/client. Framework adapter packages
 * (@drupal-canvas/headless-next, @drupal-canvas/headless-tanstack-start)
 * wrap it with their router wiring; apps on a React framework without an
 * adapter can use it directly.
 */

export {
  DraftSession,
  type DraftSessionProps,
  type DraftSessionSnapshot,
} from './draft-session';
