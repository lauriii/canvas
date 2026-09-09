/**
 * @file
 * Isomorphic rendered-entity contracts and helpers.
 */

import type { CanvasComponentTreeElement, DrupalRouteEntity } from './page';

/** Drupal's rendered answer for one explicit entity target. */
export interface EntityResult {
  content: CanvasComponentTreeElement | null;
  managedByCanvas: boolean;
  entity: DrupalRouteEntity;
}
