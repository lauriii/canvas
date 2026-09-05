<?php

declare(strict_types=1);

namespace Drupal\multi_frontend\EventSubscriber;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\EventSubscriber\MainContentViewSubscriber;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\multi_frontend\Envelope\PageEnvelope;
use Drupal\multi_frontend\Render\EnvelopeMainContentRenderer;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Answers errors on the envelope format in the envelope format.
 *
 * Without this a 404 through /page-api arrives as text/plain, so the first
 * missing page a real integration hits makes `await res.json()` throw. The
 * status stays the status -- a 403 is a 403, not a 200 with an error key --
 * and the body is a well-formed envelope carrying an additional `error`
 * object, so one parse handles both outcomes and the published schema still
 * describes what arrived.
 *
 * Only exceptions that already carry an HTTP status are converted. A
 * programming, database or service failure keeps core's own handling rather
 * than being reshaped into a tidy response that publishes its message.
 */
final class EnvelopeExceptionSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly LanguageManagerInterface $languageManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Below ExceptionLoggingSubscriber's 50, so a converted error is still
    // logged, and above the priority 1 that HttpExceptionSubscriberBase
    // defaults to, so core's HTML subscriber does not answer first. The
    // request format stays "html" here -- the envelope is selected by wrapper
    // format -- so core would otherwise treat this as an ordinary HTML error.
    return [KernelEvents::EXCEPTION => [['onException', 10]]];
  }

  /**
   * Converts an HTTP exception into an envelope response.
   */
  public function onException(ExceptionEvent $event): void {
    $request = $event->getRequest();
    if (!self::wantsEnvelope($request)) {
      return;
    }
    $exception = $event->getThrowable();
    if (!$exception instanceof HttpExceptionInterface) {
      return;
    }

    $status = $exception->getStatusCode();
    $cacheability = new CacheableMetadata();
    if ($exception instanceof CacheableDependencyInterface) {
      $cacheability->addCacheableDependency($exception);
    }
    else {
      // An error whose cacheability nobody described is not one to store.
      $cacheability->setCacheMaxAge(0);
    }
    // The body carries the negotiated content language, and a cacheable
    // exception carries no language context of its own, so without this one
    // language's error envelope can be served for another.
    $cacheability->addCacheContexts(['languages:' . LanguageInterface::TYPE_CONTENT]);

    $envelope = new PageEnvelope(
      [
        'title' => Response::$statusTexts[$status] ?? 'Error',
        'langcode' => $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId(),
        'layout' => 'default',
      ],
      // No content region: the error is the whole answer, and inventing a
      // node for it would put markup into a contract that promises data.
      ['content' => []],
      $cacheability,
    );

    $body = $envelope->toArray() + [
      'error' => [
        'status' => $status,
        // The same string core already serves on this path in text/plain, so
        // this changes the content type rather than what is disclosed.
        'message' => $exception->getMessage() !== '' ? $exception->getMessage() : (Response::$statusTexts[$status] ?? 'Error'),
      ],
    ];

    $response = new CacheableJsonResponse($body, $status, $exception->getHeaders());
    $response->addCacheableDependency($cacheability);
    // RequestEvent::setResponse() stops propagation, which is what keeps
    // core's HTML subscriber from replacing this with an error page.
    $event->setResponse($response);
  }

  /**
   * Whether this request asked for the envelope format.
   */
  private static function wantsEnvelope(Request $request): bool {
    return $request->query->get(MainContentViewSubscriber::WRAPPER_FORMAT) === EnvelopeMainContentRenderer::FORMAT;
  }

}
