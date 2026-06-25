<?php

declare(strict_types=1);

namespace Drupal\canvas\EntityHandlers;

use Drupal\canvas\Entity\StagedConfigUpdate;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityHandlerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

final class StagedConfigUpdateAccessControlHandler extends EntityAccessControlHandler implements EntityHandlerInterface {

  use StagedConfigEntityAccessControlTrait;

  private const string SIMPLE_CONFIG = 'simple.config';

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    \assert($entity instanceof StagedConfigUpdate);
    return match ($operation) {
      // We allow viewing these entities if the user has access to Canvas.
      'view' => $this->canvasUiAccessCheck->access($account),
      // Any other operation (including creating, updating, deleting) is allowed
      // if the user has access update the target configuration.
      default => $this->checkTargetUpdateAccess($entity->getTarget(), $account),
    };
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    // Creating a StagedConfigUpdate is allowed as long as the user has access
    // to update the config this staged update targets.
    return $this->checkTargetUpdateAccess($context['target'], $account);
  }

  private function checkTargetUpdateAccess(string $target_config_name, AccountInterface $account): AccessResultInterface {
    [$config_type, $config_name] = $this->getConfigType($target_config_name);
    if ($config_type === self::SIMPLE_CONFIG) {
      return match ($config_name) {
        'system.site' => AccessResult::allowedIfHasPermission($account, 'administer site configuration'),
        default => AccessResult::forbidden('Unsupported simple configuration object.')
      };
    }
    return $this->checkConfigEntityUpdateAccess($target_config_name, $account);
  }

  /**
   * Gets the config type for a given config object.
   *
   * @param string $config_name
   *   Name of the config object.
   *
   * @return array{0: string, 1: string}
   *   Name of the config type. Either 'simple.config' or an entity type ID.
   */
  private function getConfigType(string $config_name): array {
    $config_name_parts = explode('.', $config_name, 3);
    $config_prefix = "$config_name_parts[0].$config_name_parts[1]";
    if (\array_key_exists($config_prefix, $this->typesByPrefix)) {
      return [$this->typesByPrefix[$config_prefix], $config_name_parts[2]];
    }

    return [self::SIMPLE_CONFIG, $config_name];
  }

}
