<?php

declare(strict_types=1);

namespace Drupal\canvas\EntityHandlers;

use Drupal\canvas\Access\CanvasUiAccessCheck;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shared access-control logic for staged config entity access control handlers.
 *
 * Provides the constructor, factory, prefix-to-entity-type map, and the shared
 * config-entity update-access check. Concrete handlers keep only their
 * entity-type-specific checkAccess() and checkCreateAccess() methods.
 *
 * @phpstan-require-extends \Drupal\Core\Entity\EntityAccessControlHandler
 *
 * @see \Drupal\canvas\EntityHandlers\StagedConfigUpdateAccessControlHandler
 * @see \Drupal\canvas\EntityHandlers\StagedLanguageConfigOverrideAccessControlHandler
 */
trait StagedConfigEntityAccessControlTrait {

  /**
   * Maps config prefixes to entity type IDs (e.g. "node.type" → "node_type").
   *
   * @var array<string, string>
   */
  private array $typesByPrefix = [];

  public function __construct(
    EntityTypeInterface $entity_type,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CanvasUiAccessCheck $canvasUiAccessCheck,
  ) {
    parent::__construct($entity_type);
    foreach ($this->entityTypeManager->getDefinitions() as $definition) {
      if ($definition->entityClassImplements(ConfigEntityInterface::class)) {
        /** @var \Drupal\Core\Config\Entity\ConfigEntityTypeInterface $definition */
        $prefix = $definition->getConfigPrefix();
        $this->typesByPrefix[$prefix] = $definition->id();
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): self {
    return new self(
      $entity_type,
      $container->get(EntityTypeManagerInterface::class),
      $container->get(CanvasUiAccessCheck::class),
    );
  }

  /**
   * Checks whether $account can update the config entity named $config_name.
   *
   * Parses the config prefix to resolve the entity type, loads the entity, and
   * delegates to its access control handler. Returns forbidden if the prefix
   * does not map to a known config entity type or if the entity does not exist.
   */
  protected function checkConfigEntityUpdateAccess(string $config_name, AccountInterface $account): AccessResultInterface {
    $config_name_parts = \explode('.', $config_name, 3);
    $config_prefix = "$config_name_parts[0].$config_name_parts[1]";
    if (!\array_key_exists($config_prefix, $this->typesByPrefix)) {
      return AccessResult::forbidden("Unsupported configuration object '$config_name'.");
    }
    $entity_type_id = $this->typesByPrefix[$config_prefix];
    $entity_id = $config_name_parts[2];
    $loaded_target = $this->entityTypeManager->getStorage($entity_type_id)->load($entity_id);
    if ($loaded_target === NULL) {
      return AccessResult::forbidden("Target configuration entity '$entity_id' of type '$entity_type_id' does not exist.");
    }
    return $this->entityTypeManager->getAccessControlHandler($entity_type_id)
      ->access($loaded_target, 'update', $account, TRUE);
  }

}
