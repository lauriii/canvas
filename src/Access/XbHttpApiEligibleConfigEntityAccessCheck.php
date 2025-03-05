<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\experience_builder\Entity\XbHttpApiEligibleConfigEntityInterface;

/**
 * Defines access check ensuring XB config entity is eligible for API usage.
 */
final class XbHttpApiEligibleConfigEntityAccessCheck implements AccessInterface {

  public function __construct(private readonly EntityTypeManagerInterface $entityTypeManager) {}

  /**
   * Checks that XB config entity is eligible for internal HTTP API usage.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The parametrized route.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(RouteMatchInterface $route_match) {
    $xb_config_entity_type_id = $route_match->getParameter('xb_config_entity_type_id');
    $xb_config_entity_type = $this->entityTypeManager->getDefinition($xb_config_entity_type_id);
    assert($xb_config_entity_type instanceof ConfigEntityTypeInterface);

    return AccessResult::allowedIf(is_a($xb_config_entity_type->getClass(), XbHttpApiEligibleConfigEntityInterface::class, TRUE));
  }

}
