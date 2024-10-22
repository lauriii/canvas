<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * @see \Drupal\experience_builder\Controller\ApiConfigControllers
 * @internal This interface must be implemented by any Experience Builder config
 *   entity that wants to be exposed via XB's HTTP API for config entities.
 */
interface XbHttpApiEligibleConfigEntityInterface extends ConfigEntityInterface {}
