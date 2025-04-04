<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityCreateAccessCheck;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\Routing\Route;

/**
 * Defines an access checker for entity creation allowing dynamic entity types.
 *
 * @todo Remove when https://www.drupal.org/project/drupal/issues/3516775 lands in Drupal core and XB requires a version that includes it.
 */
final class XbEntityCreateAccessCheck extends EntityCreateAccessCheck implements AccessInterface {

  protected $requirementsKey = '_xb_entity_create_access';

  /**
   * {@inheritdoc}
   */
  public function access(Route $route, RouteMatchInterface $route_match, AccountInterface $account): AccessResultInterface {
    [$entity_type, $bundle] = explode(':', $route->getRequirement($this->requirementsKey) . ':');

    // Allow dynamic entity types.
    $parameters = $route_match->getParameters();
    if ($parameters->has($entity_type)) {
      $entity_type = $parameters->get($entity_type);
    }

    // The bundle argument can contain request argument placeholders like
    // {name}, loop over the raw variables and attempt to replace them in the
    // bundle name. If a placeholder does not exist, it won't get replaced.
    if ($bundle && str_contains($bundle, '{')) {
      foreach ($route_match->getRawParameters()->all() as $name => $value) {
        $bundle = str_replace('{' . $name . '}', $value, $bundle);
      }
      // If we were unable to replace all placeholders, deny access.
      if (str_contains($bundle, '{')) {
        return AccessResult::neutral(sprintf("Could not find '%s' request argument, therefore cannot check create access.", $bundle));
      }
    }
    return $this->entityTypeManager->getAccessControlHandler($entity_type)->createAccess($bundle, $account, [], TRUE);
  }

}
