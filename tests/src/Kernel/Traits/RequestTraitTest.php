<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Traits;

use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Tests the RequestTrait request handler and request stack management.
 */
#[CoversClass(RequestTrait::class)]
#[Group('canvas')]
#[RunTestsInSeparateProcesses]
final class RequestTraitTest extends CanvasKernelTestBase {

  use RequestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [];

  private RequestStack $requestStack;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $request_stack = $this->container->get('request_stack');
    self::assertInstanceOf(RequestStack::class, $request_stack);
    $this->requestStack = $request_stack;
  }

  /**
   * Creates a request with an inline controller that succeeds or fails.
   */
  private static function createRequest(bool $should_succeed): Request {
    $request = Request::create('/canvas/request-trait-test');
    $request->attributes->set(
      RouteObjectInterface::CONTROLLER_NAME,
      $should_succeed
        ? static fn(): Response => new Response('', Response::HTTP_OK)
        : static fn(): Response => throw new AccessDeniedHttpException('Denied for testing.')
    );
    return $request;
  }

  /**
   * Executes a request and asserts expected success or access denial.
   */
  private function executeRequest(bool $request_should_succeed): void {
    if ($request_should_succeed) {
      $this->request(self::createRequest(TRUE));
      return;
    }

    try {
      $this->request(self::createRequest(FALSE));
      $this->fail('Expected access denied exception.');
    }
    catch (AccessDeniedHttpException $exception) {
      self::assertSame('Denied for testing.', $exception->getMessage());
    }
  }

  /**
   * Asserts that current request stack state matches the expected initial state.
   */
  private function assertCurrentRequestMatchesInitial(?Request $initial_request): void {
    $current_request = $this->requestStack->getCurrentRequest();
    if ($initial_request === NULL) {
      self::assertNull($current_request);
    }
    else {
      self::assertInstanceOf(Request::class, $current_request);
    }
    self::assertSame($initial_request, $current_request);
  }

  /**
   * Tests request stack restoration after request.
   *
   * First argument controls request outcome:
   *   TRUE => success with HTTP 200
   *   FALSE => request terminates early with the access denied exception
   * Second argument controls initial state:
   * FALSE keeps kernel bootstrap request, TRUE forces current request to NULL.
   */
  #[TestWith([TRUE, FALSE])]
  #[TestWith([FALSE, FALSE])]
  #[TestWith([TRUE, TRUE])]
  #[TestWith([FALSE, TRUE])]
  public function testRequestStackStateAfterRequest(bool $request_should_succeed, bool $force_null_initial_request): void {
    $bootstrap_request = $this->requestStack->getCurrentRequest();
    self::assertInstanceOf(Request::class, $bootstrap_request);
    $initial_request = $bootstrap_request;
    if ($force_null_initial_request) {
      while ($this->requestStack->getCurrentRequest() !== NULL) {
        $this->requestStack->pop();
      }
      $initial_request = $this->requestStack->getCurrentRequest();
      self::assertNull($initial_request);
    }

    try {
      $this->executeRequest($request_should_succeed);
      $this->assertCurrentRequestMatchesInitial($initial_request);
    }
    finally {
      if ($force_null_initial_request && $this->requestStack->getCurrentRequest() === NULL) {
        // KernelTestBase::tearDown() expects an active request with session.
        $this->requestStack->push($bootstrap_request);
      }
    }
  }

}
