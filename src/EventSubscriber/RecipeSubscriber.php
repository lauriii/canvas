<?php

declare(strict_types=1);

namespace Drupal\canvas\EventSubscriber;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\Core\Config\Action\ConfigActionManager;
use Drupal\Core\DefaultContent\PreImportEvent;
use Drupal\Core\Recipe\RecipeAppliedEvent;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Ensures components are generated during and after recipe application.
 */
final class RecipeSubscriber implements EventSubscriberInterface {

  public function __construct(
    #[Autowire(service: 'plugin.manager.config_action')]
    private readonly ConfigActionManager $configActionManager,
    private readonly ComponentSourceManager $componentSourceManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      PreImportEvent::class => 'ensureComponentsExist',
      RecipeAppliedEvent::class => 'onApply',
    ];
  }

  /**
   * Generates Component config entities, during and after recipe application.
   */
  public function ensureComponentsExist(): void {
    $this->componentSourceManager->generateComponents();
  }

  /**
   * Reacts when a recipe is applied.
   *
   * @param \Drupal\Core\Recipe\RecipeAppliedEvent $event
   *   The event object.
   */
  public function onApply(RecipeAppliedEvent $event): void {
    $this->ensureComponentsExist();

    // Re-run any config actions that target Component entities.
    $items = array_filter(
      $event->recipe->config->config['actions'] ?? [],
      // @see \Drupal\canvas\Entity\Component
      fn (string $name): bool => str_starts_with($name, 'canvas.component.'),
      ARRAY_FILTER_USE_KEY,
    );
    foreach ($items as $name => $actions) {
      foreach ($actions as $action_id => $data) {
        $this->configActionManager->applyAction($action_id, $name, $data);
      }
    }
  }

}
