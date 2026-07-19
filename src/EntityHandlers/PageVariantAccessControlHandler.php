<?php

declare(strict_types=1);

namespace Drupal\canvas\EntityHandlers;

use Drupal\canvas\Entity\PageVariant;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Denies deleting the site default page variant.
 *
 * Content and content templates without an explicit variant selection resolve
 * to the site default, so it must always exist. Denying `delete` access makes
 * the HTTP API respond with a 403 instead of tripping the last-resort guard in
 * PageVariant::preDelete() (a 500).
 *
 * @see \Drupal\canvas\Entity\PageVariant::preDelete()
 * @see \Drupal\canvas\PageVariantResolver
 */
final class PageVariantAccessControlHandler extends CanvasConfigEntityAccessControlHandler {

  public function __construct(
    EntityTypeInterface $entity_type,
    ConfigManagerInterface $config_manager,
    EntityTypeManagerInterface $entity_type_manager,
    private readonly ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct($entity_type, $config_manager, $entity_type_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): self {
    return new self(
      $entity_type,
      $container->get(ConfigManagerInterface::class),
      $container->get(EntityTypeManagerInterface::class),
      $container->get(ConfigFactoryInterface::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    \assert($entity instanceof PageVariant);
    if ($operation === 'delete') {
      $settings = $this->configFactory->get('canvas.settings');
      return AccessResult::forbiddenIf(
        $settings->get(PageVariant::DEFAULT_SETTING) === $entity->id(),
        'The site default page variant cannot be deleted. Set another variant as the default first.'
      )
        ->addCacheableDependency($settings)
        ->orIf(parent::checkAccess($entity, $operation, $account));
    }
    return parent::checkAccess($entity, $operation, $account);
  }

}
