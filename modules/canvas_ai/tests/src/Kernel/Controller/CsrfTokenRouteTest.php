<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Kernel\Controller;

use Drupal\canvas_ai\CanvasAiPermissions;
use Drupal\canvas_ai\Controller\CsrfTokenController;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the Canvas AI CSRF token endpoint.
 *
 * @see https://www.drupal.org/i/3550891
 */
#[Group('canvas_ai')]
#[RunTestsInSeparateProcesses]
final class CsrfTokenRouteTest extends CanvasKernelTestBase {

  use RequestTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas_ai',
    'ai',
    'ai_agents',
    // Removes an AI service that Canvas AI injects, simulating a partial or
    // version-mismatched AI stack install.
    'canvas_ai_missing_service_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');

    $user = $this->createUser([CanvasAiPermissions::USE_CANVAS_AI]);
    \assert($user instanceof AccountInterface);
    $this->setCurrentUser($user);
  }

  /**
   * The token endpoint must work even when the wider AI stack is unavailable.
   *
   * Fetching the CSRF token is the first request the AI wizard makes on mount,
   * with a GET (see the aiExtension AiWizard component). Its controller only
   * needs the core CSRF token generator, so a missing AI service must not turn
   * it into "The controller ... is not callable" (a 500).
   */
  public function testTokenEndpointDoesNotRequireAiStack(): void {
    // Guard the reproduction: the AI service Canvas AI injects is really gone.
    $this->assertFalse(
      $this->container->has('ai_agents.agent_status_poller'),
      'The test fixture must remove an AI service to reproduce issue #3550891.',
    );

    // Use GET, the method the AI wizard uses to fetch the token.
    $response = $this->request(
      Request::create(Url::fromRoute('canvas_ai.csrf_token')->toString(), 'GET'),
    );

    $this->assertSame(200, $response->getStatusCode());
    $this->assertNotEmpty((string) $response->getContent());
  }

  /**
   * The token controller must stay decoupled from the AI stack.
   *
   * Issue #3550891 was caused by the token route resolving a controller that
   * injects the whole AI stack. Lock the invariant structurally so a future
   * change that re-injects AI services here fails loudly, independent of which
   * specific AI service happens to be missing at runtime.
   */
  public function testTokenControllerOnlyDependsOnCsrfTokenGenerator(): void {
    $constructor = (new \ReflectionClass(CsrfTokenController::class))->getConstructor();
    $this->assertNotNull($constructor);
    $parameter_types = \array_map(
      static fn (\ReflectionParameter $parameter): ?string => $parameter->getType() instanceof \ReflectionNamedType ? $parameter->getType()->getName() : NULL,
      $constructor->getParameters(),
    );
    $this->assertSame([CsrfTokenGenerator::class], $parameter_types);
  }

}
