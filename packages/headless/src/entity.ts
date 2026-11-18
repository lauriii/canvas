/**
 * @file
 * Isomorphic rendered-entity contracts and helpers.
 */

import type { CanvasComponentTree, DrupalRouteEntity } from './page';

/** Drupal's rendered answer for one explicit entity target. */
export interface EntityResult {
  content: CanvasComponentTree | null;
  managedByCanvas: boolean;
  entity: DrupalRouteEntity;
  /** Whether content was fetched through a live Canvas preview session. */
  isPreview: boolean;
}
