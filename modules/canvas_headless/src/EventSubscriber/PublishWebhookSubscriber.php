<?php

declare(strict_types=1);

namespace Drupal\canvas_headless\EventSubscriber;

use Drupal\canvas\Event\PublishedEvent;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Utility\Error;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Posts a webhook when Canvas content is published.
 *
 * The payload references the published entities so a consumer can trigger a
 * full rebuild or revalidate just the affected paths (mapping references to
 * paths via the route inventory). Delivery is best effort: a failure is
 * logged and never blocks or fails the publish, which has already committed
 * by the time the event fires.
 */
final class PublishWebhookSubscriber implements EventSubscriberInterface {

  /**
   * The payload signature header, present only when a secret is configured.
   */
  private const SIGNATURE_HEADER = 'X-Canvas-Signature';

  /**
   * The State key holding the webhook signing secret.
   */
  public const SECRET_STATE_KEY = 'canvas_headless.publish_webhook_secret';

  /**
   * The settings.php key that overrides the State-held secret.
   */
  public const SECRET_SETTINGS_KEY = 'canvas_headless_publish_webhook_secret';

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly StateInterface $state,
    #[Autowire(service: 'http_client')]
    private readonly ClientInterface $httpClient,
    #[Autowire(service: 'logger.channel.canvas')]
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [PublishedEvent::class => 'onPublished'];
  }

  /**
   * Delivers the publish payload to the configured webhook, if any.
   */
  public function onPublished(PublishedEvent $event): void {
    $webhook = $this->configFactory->get('canvas_headless.settings')->get('publish_webhook');
    $url = \is_array($webhook) ? ($webhook['url'] ?? '') : '';
    if (!\is_string($url) || $url === '') {
      return;
    }

    $body = (string) \json_encode([
      'event' => 'publish',
      'entities' => $event->getEntityReferences(),
      // The invalidated cache tags let a consumer revalidate exactly the
      // pages that depended on the changed content, indirect dependencies
      // included, by matching them against the per-page cacheability tags.
      'tags' => $event->getCacheTags(),
    ]);

    $headers = ['Content-Type' => 'application/json'];
    // The signing secret is never stored in config, so a config export cannot
    // carry it into version control. It lives in State (set it with
    // `drush state:set` or a deploy step, which works where settings.php is
    // not editable), with a settings.php override for locked-down
    // environments that prefer an immutable secret.
    $secret = Settings::get(self::SECRET_SETTINGS_KEY);
    if (!\is_string($secret) || $secret === '') {
      $secret = $this->state->get(self::SECRET_STATE_KEY, '');
    }
    if (\is_string($secret) && $secret !== '') {
      // An HMAC over the exact body lets the receiver verify the payload came
      // from this site and was not tampered with in transit.
      $headers[self::SIGNATURE_HEADER] = 'sha256=' . \hash_hmac('sha256', $body, $secret);
    }

    try {
      // HTTP errors stay enabled (Guzzle throws on 4xx/5xx) so an
      // unsuccessful delivery is logged through the catch below rather than
      // silently treated as success.
      $this->httpClient->request('POST', $url, [
        RequestOptions::HEADERS => $headers,
        RequestOptions::BODY => $body,
        // A slow or unreachable consumer must not hang the editor's publish
        // response, so the delivery is time-boxed.
        RequestOptions::TIMEOUT => 5,
      ]);
    }
    catch (\Throwable $e) {
      Error::logException($this->logger, $e, 'Canvas Headless publish webhook delivery failed.');
    }
  }

}
