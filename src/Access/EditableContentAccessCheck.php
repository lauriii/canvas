<?php

declare(strict_types=1);

namespace Drupal\canvas\Access;

use Drupal\canvas\EditableContentDiscovery;
use Drupal\canvas\Entity\Page;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\Routing\Route;

/**
 * Route access check for content routes parameterized over `{entity_type}`.
 *
 * The `_canvas_editable_content` requirement value names the operation the
 * route performs (`list` or `create`). For `canvas_page` the historical gates
 * are preserved unchanged: `edit canvas_page` for listing, entity create
 * access for creating. For every other entity type, access is granted when
 * the type has at least one Canvas-editable bundle per the discovery service;
 * per-entity and per-bundle access is enforced by the controllers themselves
 * (access-checked entity queries and explicit create access checks on the
 * bundle in the request body), never by permission-string heuristics.
 *
 * @see \Drupal\canvas\EditableContentDiscovery
 * @internal
 */
final class EditableContentAccessCheck implements AccessInterface {

  public function __construct(
    private readonly EditableContentDiscovery $editableContentDiscovery,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public function access(Route $route, RouteMatchInterface $route_match, AccountInterface $account): AccessResultInterface {
    $entity_type_id = $route_match->getParameter('entity_type');
    if (!\is_string($entity_type_id) || $entity_type_id === '') {
      return AccessResult::forbidden('The route has no `entity_type` parameter.');
    }
    if ($entity_type_id === Page::ENTITY_TYPE_ID) {
      if ($route->getRequirement('_canvas_editable_content') === 'create') {
        $access = $this->entityTypeManager
          ->getAccessControlHandler(Page::ENTITY_TYPE_ID)
          ->createAccess(Page::ENTITY_TYPE_ID, $account, return_as_object: TRUE);
        \assert($access instanceof AccessResultInterface);
        return $access;
      }
      return AccessResult::allowedIfHasPermission($account, 'edit canvas_page');
    }
    $access = AccessResult::allowedIf($this->editableContentDiscovery->isEditableEntityType($entity_type_id));
    \assert($access instanceof AccessResult);
    if (!$access->isAllowed()) {
      $access->setReason('This entity type has no Canvas-editable bundles: only `canvas_page` and bundles with an enabled `full` view mode content template are supported.');
    }
    return $access->addCacheableDependency($this->editableContentDiscovery->getCacheability());
  }

}
