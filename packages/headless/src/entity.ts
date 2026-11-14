/**
 * @file
 * Isomorphic rendered-entity contracts and helpers.
 */

import type {
  Cacheability,
  CanvasComponentTreeElement,
  DrupalRouteEntity,
} from './page';

/** Drupal's rendered answer for one explicit entity target. */
export interface EntityResult extends Cacheability {
  content: CanvasComponentTreeElement | null;
  managedByCanvas: boolean;
  entity: DrupalRouteEntity;
}
