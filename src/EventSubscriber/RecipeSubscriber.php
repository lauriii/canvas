<?php

declare(strict_types=1);

namespace Drupal\experience_builder\EventSubscriber;

use Drupal\Component\Plugin\Discovery\CachedDiscoveryInterface;
use Drupal\Core\DefaultContent\PreImportEvent;
use Drupal\Core\Recipe\RecipeAppliedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Ensures components are generated during and after recipe application.
 */
final class RecipeSubscriber implements EventSubscriberInterface {

  /**
   * @var \Drupal\Component\Plugin\Discovery\CachedDiscoveryInterface[]
   */
  private array $componentSources = [];

  public function addComponentSource(CachedDiscoveryInterface $discovery): void {
    $this->componentSources[] = $discovery;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      PreImportEvent::class => 'ensureComponentsExist',
      RecipeAppliedEvent::class => 'ensureComponentsExist',
    ];
  }

  /**
   * Creates component entities as needed, during and after recipe application.
   */
  public function ensureComponentsExist(): void {
    foreach ($this->componentSources as $source) {
      // Ensure that all component information is fully up-to-date before
      // we import content that might be using them, and after the recipe has
      // finished applying (since it may have run config actions which affected
      // extant components).
      $source->clearCachedDefinitions();
      $source->getDefinitions();
    }
  }

}
