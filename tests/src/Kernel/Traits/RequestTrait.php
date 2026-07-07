<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Traits;

use Drupal\canvas\AutoSave\Workspace\AutoSaveWorkspace;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

trait RequestTrait {

  /**
   * Passes a request to the HTTP kernel and returns a response.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response.
   *
   * @throws \Exception
   */
  protected function request(Request $request): Response {
    // Reset the request stack.
    // \Drupal\KernelTests\KernelTestBase::bootKernel() pushes a bogus request
    // to boot the kernel, but it is also needed for any URL generation in tests
    // to work. We also need to reset the request stack every time we make a
    // request.
    $request_stack = $this->container->get(RequestStack::class);
    $previous_requests = [];
    while ($request_stack->getCurrentRequest() !== NULL) {
      $previous_requests[] = $request_stack->pop();
    }

    $http_kernel = $this->container->get(HttpKernelInterface::class);
    self::assertInstanceOf(HttpKernelInterface::class, $http_kernel);

    try {
      $response = $http_kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, FALSE);
      $content = $response->getContent();
      self::assertNotFalse($content);
      $this->setRawContent($content);

      self::assertInstanceOf(TerminableInterface::class, $http_kernel);
      $http_kernel->terminate($request, $response);

      return $response;
    } finally {
      // Always clean up the request stack, even if an exception was thrown.
      // Drupal's kernel middleware (KernelPreHandle) and Symfony's HttpKernel
      // both push the request onto the stack. We need to remove any lingering
      // requests left after the request handling completes or fails.
      while ($request_stack->getCurrentRequest() !== NULL) {
        $request_stack->pop();
      }

      // Restore the previous request stack state that existed before this
      // request was processed.
      foreach ($previous_requests as $previous_request) {
        \assert($previous_request instanceof Request);
        $request_stack->push($previous_request);
      }

      // When the controller throws (this harness handles requests with
      // catch: FALSE), kernel terminate never runs, so the auto-save workspace
      // activated for Canvas API routes would leak into subsequent test code.
      // In production, exceptions become responses and terminate runs.
      // @see \Drupal\canvas\EventSubscriber\AutoSave\AutoSaveWorkspaceActivationSubscriber::onKernelTerminate()
      if ($this->container->has('workspaces.manager')) {
        $workspace_manager = $this->container->get('workspaces.manager');
        if ($workspace_manager->hasActiveWorkspace() && $workspace_manager->getActiveWorkspace()?->id() === AutoSaveWorkspace::ID) {
          $workspace_manager->switchToLive();
        }
      }
    }
  }

  protected static function decodeResponse(Response $response): array {
    self::assertInstanceOf(JsonResponse::class, $response);
    self::assertIsString($response->getContent());
    self::assertJson($response->getContent());
    return \json_decode($response->getContent(), TRUE);
  }

}
