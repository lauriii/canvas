<?php

declare(strict_types=1);

namespace Drupal\canvas_headless\EventSubscriber;

use Drupal\canvas_headless\Cache\FrontendCacheTagInvalidator;
use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Forgets the cache invalidation state of removed and added frontends.
 *
 * However the list changes (the Canvas UI, a config import, drush). A
 * frontend that is added, also again, starts without a state, so that its
 * first announcement expires everything.
 *
 * @see \Drupal\canvas_headless\Cache\FrontendCacheTagInvalidator
 */
final class CacheInvalidationConfigSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly FrontendCacheTagInvalidator $frontendCacheTagInvalidator,
  ) {}

  /**
   * Reacts to saving the module's settings.
   */
  public function onSave(ConfigCrudEvent $event): void {
    $config = $event->getConfig();
    if ($config->getName() === 'canvas_headless.settings' && $event->isChanged('frontends')) {
      $this->frontendCacheTagInvalidator->deleteUnusedStates($config->getOriginal('frontends'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [ConfigEvents::SAVE => 'onSave'];
  }

}
