<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\experience_builder\ClientSideRepresentation;

/**
 * @see \Drupal\experience_builder\Controller\ApiConfigControllers
 * @internal This interface must be implemented by any Experience Builder config
 *   entity that wants to be exposed via XB's HTTP API for config entities.
 */
interface XbHttpApiEligibleConfigEntityInterface extends ConfigEntityInterface {

  /**
   * Normalizes this config entity to the data model expected by the client.
   *
   * @return \Drupal\experience_builder\ClientSideRepresentation
   *
   * @see openapi.yml
   */
  public function normalizeForClientSide(): ClientSideRepresentation;

  /**
   * Denormalizes this config entity from the data model used by the client.
   *
   * @return array
   *
   * @see openapi.yml
   */
  public static function denormalizeFromClientSide(array $data): array;

}
