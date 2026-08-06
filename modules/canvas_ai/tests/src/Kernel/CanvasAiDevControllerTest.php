<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Kernel;

use Drupal\canvas_ai\CanvasAiPermissions;
use Drupal\canvas_dev_ai\Controller\CanvasDevAiBuilder;
use Drupal\Core\Asset\AttachedAssets;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Tests the canvas_dev_ai AI controller access and its drupalSettings flag.
 *
 * @see \Drupal\Tests\canvas_ai\Kernel\Agents\CanvasComponentAgentEndToEndTest
 */
#[Group('canvas_ai')]
#[CoversClass(CanvasDevAiBuilder::class)]
final class CanvasAiDevControllerTest extends CanvasKernelTestBase {

  use RequestTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas_ai',
    'ai',
    'ai_agents',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // canvas_dev_ai's shipped settings reference ai_agent entities from
    // canvas_ai's config/install, and its schema constraints require them to
    // exist, so they must be installed before canvas_dev_ai is.
    $this->installConfig(['canvas_ai']);
  }

  /**
   * Tests that the `aiDevMode` flag follows the module install state.
   */
  public function testAiDevModeFlagFollowsInstallState(): void {
    $this->installSchema('user', ['users_data']);
    $this->assertArrayNotHasKey('aiDevMode', $this->alterJsSettings()['canvas']);

    $this->container->get(ModuleInstallerInterface::class)->install(['canvas_dev_ai']);
    $this->refreshContainer();
    $this->assertTrue($this->alterJsSettings()['canvas']['aiDevMode']);

    $this->container->get(ModuleInstallerInterface::class)->uninstall(['canvas_dev_ai']);
    $this->refreshContainer();
    $this->assertArrayNotHasKey('aiDevMode', $this->alterJsSettings()['canvas']);
  }

  /**
   * Tests that the controller rejects a request with an invalid CSRF token.
   */
  public function testControllerRejectsInvalidCsrfToken(): void {
    $this->container->get(ModuleInstallerInterface::class)->install(['canvas_dev_ai']);
    $this->refreshContainer();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->setUpCurrentUser(permissions: [CanvasAiPermissions::USE_CANVAS_AI]);

    $request = Request::create('/admin/api/canvas/ai-dev', 'POST');
    $request->headers->set('X-CSRF-Token', 'invalid-token');

    $this->expectException(AccessDeniedHttpException::class);
    $this->expectExceptionMessage('Invalid CSRF token');
    $this->request($request);
  }

  /**
   * Re-fetches the container after a module install or uninstall rebuild.
   */
  private function refreshContainer(): void {
    $container = \Drupal::getContainer();
    \assert($container instanceof ContainerBuilder);
    $this->container = $container;
  }

  /**
   * Runs the js_settings alter hooks on a minimal Canvas settings array.
   */
  private function alterJsSettings(): array {
    $settings = ['canvas' => ['aiExtensionAvailable' => TRUE]];
    $assets = new AttachedAssets();
    $this->container->get(ModuleHandlerInterface::class)->alter('js_settings', $settings, $assets);
    return $settings;
  }

}
