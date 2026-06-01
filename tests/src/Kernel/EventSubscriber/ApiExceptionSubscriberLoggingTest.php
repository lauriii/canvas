<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\EventSubscriber;

use ColinODell\PsrTestLogger\TestLogger;
use Drupal\canvas\EventSubscriber\ApiExceptionSubscriber;
use Drupal\Core\EventSubscriber\ExceptionLoggingSubscriber;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Route;

/**
 * Tests that exceptions on Canvas API routes are both logged and JSON-encoded.
 *
 * ApiExceptionSubscriber and core's ExceptionLoggingSubscriber both listen on
 * KernelEvents::EXCEPTION; ApiExceptionSubscriber sets a response, which stops
 * propagation. It must therefore run at a lower priority than the logging
 * subscriber, or exceptions on Canvas API routes are never logged.
 *
 * The triggered exception is a 5xx, so core also calls error_log(); the
 * tests redirect the SAPI error log to /dev/null so PHPUnit's failOnWarning
 * does not turn that write into a failure.
 *
 * @see https://www.drupal.org/project/canvas/issues/3538825
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[CoversClass(ApiExceptionSubscriber::class)]
final class ApiExceptionSubscriberLoggingTest extends CanvasKernelTestBase {

  /**
   * ApiExceptionSubscriber must run at a lower priority than the logger.
   */
  public function testRunsAfterExceptionLoggingSubscriber(): void {
    $event_dispatcher = $this->container->get('event_dispatcher');
    \assert($event_dispatcher instanceof EventDispatcherInterface);
    $api_priority = self::exceptionPriority($event_dispatcher, ApiExceptionSubscriber::class);
    $logging_priority = self::exceptionPriority($event_dispatcher, ExceptionLoggingSubscriber::class);
    self::assertLessThan(
      $logging_priority,
      $api_priority,
      \sprintf(
        'ApiExceptionSubscriber (priority %d) must have a strictly lower KernelEvents::EXCEPTION priority than core ExceptionLoggingSubscriber (priority %d) so exceptions on Canvas API routes are logged before the JSON response stops propagation.',
        $api_priority,
        $logging_priority,
      ),
    );
  }

  /**
   * Returns the KernelEvents::EXCEPTION listener priority for a subscriber.
   *
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   The event dispatcher to query.
   * @param class-string $subscriber_class
   *   The subscriber class to find the listener for.
   *
   * @return int
   *   The listener's KernelEvents::EXCEPTION priority.
   */
  private static function exceptionPriority(EventDispatcherInterface $event_dispatcher, string $subscriber_class): int {
    foreach ($event_dispatcher->getListeners(KernelEvents::EXCEPTION) as $listener) {
      if (\is_array($listener) && isset($listener[0]) && \is_object($listener[0]) && $listener[0] instanceof $subscriber_class) {
        \assert(\is_callable($listener));
        $priority = $event_dispatcher->getListenerPriority(KernelEvents::EXCEPTION, $listener);
        \assert($priority !== NULL);
        return $priority;
      }
    }
    self::fail(\sprintf('No KernelEvents::EXCEPTION listener found for %s.', $subscriber_class));
  }

  /**
   * An uncaught exception on a Canvas API route is logged and JSON-encoded.
   *
   * Synthesizes a KernelEvents::EXCEPTION dispatch on a request whose
   * `_route` attribute starts with `canvas.api.`. ApiExceptionSubscriber only
   * inspects the route name (read via the `route_match` service from the
   * current request stack), so the route does not have to exist in the
   * router. Going via the dispatcher avoids the need for a fixture module
   * with a throwing controller.
   */
  public function testExceptionIsLoggedAndJsonEncoded(): void {
    // Core's ExceptionLoggingSubscriber logs uncaught 500s to the `php`
    // channel.
    // @see \Drupal\Core\EventSubscriber\ExceptionLoggingSubscriber::onError()
    $logger = new TestLogger();
    $this->container->get(LoggerChannelFactoryInterface::class)
      ->get('php')
      ->addLogger($logger);

    // ExceptionLoggingSubscriber::onError() also calls error_log() for
    // critical (5xx) exceptions. PHPUnit (failOnWarning=true) turns SAPI
    // error log output into a test failure, so silence it for the duration of
    // this test by redirecting the SAPI error log to /dev/null. The logger
    // channel call above is unaffected and still records the exception.
    $previous_error_log = ini_set('error_log', '/dev/null');

    $exception_message = 'Canvas API test exception was thrown.';
    $request = Request::create('/canvas/api/v0/test');
    $request->attributes->set('_route', 'canvas.api.test');
    $request->attributes->set('_route_object', new Route('/canvas/api/v0/test'));
    $request_stack = $this->container->get('request_stack');
    $request_stack->push($request);

    try {
      $event_dispatcher = $this->container->get('event_dispatcher');
      \assert($event_dispatcher instanceof EventDispatcherInterface);
      $http_kernel = $this->container->get('http_kernel');
      \assert($http_kernel instanceof HttpKernelInterface);
      $event = new ExceptionEvent(
        $http_kernel,
        $request,
        HttpKernelInterface::MAIN_REQUEST,
        new \Exception($exception_message),
      );
      $event_dispatcher->dispatch($event, KernelEvents::EXCEPTION);
    }
    finally {
      $request_stack->pop();
      ini_set('error_log', $previous_error_log);
    }

    // ApiExceptionSubscriber ran.
    $response = $event->getResponse();
    self::assertInstanceOf(JsonResponse::class, $response);
    self::assertSame(500, $response->getStatusCode());
    $content = $response->getContent();
    self::assertIsString($content);
    self::assertArrayHasKey('message', \json_decode($content, TRUE, flags: \JSON_THROW_ON_ERROR));

    // ExceptionLoggingSubscriber also ran: the exception was logged. The
    // logged message is a placeholder template; the exception text is in the
    // `@message` context value.
    // @see \Drupal\Core\Utility\Error::decodeException()
    self::assertTrue(
      $logger->hasRecordThatPasses(
        static fn (array $record): bool => ($record['context']['@message'] ?? NULL) === $exception_message,
      ),
      'The exception thrown on the Canvas API route was logged.',
    );
  }

}
