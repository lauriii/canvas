<?php

namespace Drupal\experience_builder\EventSubscriber;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\ParamConverter\ParamNotConvertedException;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Handle exceptions for Experience Builder API routes.
 */
final class ApiExceptionSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * Handles exceptions and converts them to JSON responses.
   *
   * @param \Symfony\Component\HttpKernel\Event\ExceptionEvent $event
   *   The event to process.
   */
  public function onException(ExceptionEvent $event): void {
    // When param conversion fails, the more detailed exception is wrapped in
    // another.
    // @see \Drupal\Core\Routing\Enhancer\ParamConversionEnhancer::onException()
    $previous_exception = $event->getThrowable()->getPrevious();

    // Only handle XB API routes. Special care is needed for 404s caused by
    // requests to individual config entities that do not exist. This is not a
    // challenge in the generic (HTTP) exception handling because that
    // determined by the (wrapper) format, whereas XB API routes *always* return
    // a JSON response.
    // @see \Drupal\Core\EventSubscriber\HttpExceptionSubscriberBase::onException()
    // @todo Consider adding a `_format` requirement to all XB API routes, that
    // might allow this to be simplified.
    $route_name = $this->routeMatch->getRouteName() ?? ($previous_exception instanceof ParamNotConvertedException ? $previous_exception->getRouteName() : NULL);
    if (str_starts_with($route_name ?? '', 'experience_builder.api.')) {
      $response = [];
      $exception = $event->getThrowable();

      $status = match (TRUE) {
        $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
        default => Response::HTTP_INTERNAL_SERVER_ERROR,
      };

      // Generate a JSON response with a message when the status is not 404
      if ($status !== 404) {
        $response['message'] = $exception->getMessage();
      }

      // Generate a JSON response containing details when the status is 500, if
      // the current user has access to it.
      if ($status === 500) {
        // The stack trace may contain sensitive information. Only show it to
        // authorized users.
        // @see \Drupal\jsonapi\Normalizer\HttpExceptionNormalizer::buildErrorObjects()
        $is_verbose_reporting = $this->configFactory->get('system.logging')->get('error_level') === ERROR_REPORTING_DISPLAY_VERBOSE;
        $site_report_access = $this->currentUser->hasPermission('access site reports');
        if ($site_report_access && $is_verbose_reporting) {
          $response += [
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTrace(),
          ];
        }
      }

      if ($exception instanceof CacheableDependencyInterface) {
        $event->setResponse(
          (new CacheableJsonResponse($response, $status))
            ->addCacheableDependency($exception)
        );
      }
      else {
        $event->setResponse(new JsonResponse($response, $status));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Lower than the priority of \Drupal\Core\EventSubscriber\ExceptionJsonSubscriber.
    $events[KernelEvents::EXCEPTION][] = ['onException', 50];
    return $events;
  }

}
