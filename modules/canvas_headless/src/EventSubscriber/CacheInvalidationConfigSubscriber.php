<?php

declare(strict_types=1);

namespace Drupal\canvas_headless\EventSubscriber;

use Drupal\canvas_headless\Cache\FrontendCacheTagInvalidator;
use Drupal\canvas_headless\FrontendUrl;
use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Keeps the cache invalidation state in step with the frontend list.
 *
 * However the list changes (the Canvas UI, a config import, drush), a
 * frontend whose cache invalidation is turned on starts without a state, so
 * that its first announcement expires what changed while it was off. The
 * state of a frontend that no longer receives announcements is deleted.
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
    if ($config->getName() !== 'canvas_headless.settings' || !$event->isChanged('frontends')) {
      return;
    }
    $previous = self::enabled($config->getOriginal('frontends'));
    foreach (array_diff_key(self::enabled($config->get('frontends')), $previous) as $url) {
      $this->frontendCacheTagInvalidator->forget($url);
    }
    $this->frontendCacheTagInvalidator->deleteUnusedStates();
  }

  /**
   * Returns the frontends with cache invalidation enabled.
   *
   * @param mixed $frontends
   *   The `frontends` of canvas_headless.settings.
   *
   * @return array<string, string>
   *   The canonical base URLs, keyed by themselves.
   */
  private static function enabled(mixed $frontends): array {
    $urls = [];
    foreach (\is_array($frontends) ? $frontends : [] as $frontend) {
      if (\is_array($frontend) && ($frontend['cache_invalidation'] ?? FALSE) === TRUE) {
        $url = FrontendUrl::fromConfig((string) ($frontend['url'] ?? ''))?->baseUrl;
        if ($url !== NULL) {
          $urls[$url] = $url;
        }
      }
    }
    return $urls;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [ConfigEvents::SAVE => 'onSave'];
  }

}
