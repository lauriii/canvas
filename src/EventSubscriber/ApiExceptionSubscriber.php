<?php

namespace Drupal\experience_builder\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
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
    if (str_starts_with($this->routeMatch->getRouteName() ?? '', 'experience_builder.api.')) {
      $response = [];
      $exception = $event->getThrowable();
      $response['message'] = $exception->getMessage();

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

      $event->setResponse(new JsonResponse($response, Response::HTTP_INTERNAL_SERVER_ERROR));
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
