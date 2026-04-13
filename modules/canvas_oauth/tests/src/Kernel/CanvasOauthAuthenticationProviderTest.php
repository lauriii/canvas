<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_oauth\Kernel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\canvas\Entity\AssetLibrary;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\Folder;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Entity\Pattern;
use Drupal\canvas_oauth\Authentication\Provider\CanvasOauthAuthenticationProvider;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Tests the Canvas OAuth authentication provider.
 */
#[CoversClass(CanvasOauthAuthenticationProvider::class)]
#[Group('canvas_oauth')]
class CanvasOauthAuthenticationProviderTest extends CanvasKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'consumers',
    'canvas_oauth',
    'simple_oauth',
    'serialization',
  ];

  /**
   * The authentication provider being tested.
   *
   * @var \Drupal\canvas_oauth\Authentication\Provider\CanvasOauthAuthenticationProvider
   */
  protected CanvasOauthAuthenticationProvider $authProvider;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->authProvider = $this->container->get(CanvasOauthAuthenticationProvider::class);
  }

  /**
   * Data provider for testing authentication provider logic on all Canvas routes.
   *
   * @return array<int, array{
   *   0: string,
   *   1: array<string>,
   *   2: bool
   *   }> Array of test cases where:
   *   - Index 0: Route name
   *   - Index 1: Route parameters
   *   - Index 2: Expected result of applies() method
   */
  public static function dataProviderRoutes(): array {
    $generate_per_config_entity_type_test_case = function (string $config_entity_type_id, bool $expected_applies): array {
      return [
        ['canvas.api.config.delete', ['canvas_config_entity_type_id' => $config_entity_type_id], $expected_applies],
        ['canvas.api.config.get', ['canvas_config_entity_type_id' => $config_entity_type_id], $expected_applies],
        ['canvas.api.config.list', ['canvas_config_entity_type_id' => $config_entity_type_id], $expected_applies],
        ['canvas.api.config.patch', ['canvas_config_entity_type_id' => $config_entity_type_id], $expected_applies],
        ['canvas.api.config.post', ['canvas_config_entity_type_id' => $config_entity_type_id], $expected_applies],
      ];
    };
    return [
      ['entity.component.audit', [], FALSE],
      ['entity.component.delete_form', [], FALSE],
      ['entity.component.disable', [], FALSE],
      ['entity.component.enable', [], FALSE],
      ['canvas.api.auto-save.get', [], FALSE],
      ['canvas.api.auto-save.post', [], FALSE],
      ['canvas.api.config.auto-save.get', [], FALSE],
      ['canvas.api.config.auto-save.get.css', [], FALSE],
      ['canvas.api.config.auto-save.get.js', [], FALSE],
      ['canvas.api.config.auto-save.patch', [], FALSE],
      ['canvas.api.config.delete', [], FALSE],
      ['canvas.api.config.get', [], FALSE],
      ['canvas.api.config.list', [], FALSE],
      ['canvas.api.config.patch', [], FALSE],
      ['canvas.api.config.post', [], FALSE],
      ...$generate_per_config_entity_type_test_case(Component::ENTITY_TYPE_ID, TRUE),
      ...$generate_per_config_entity_type_test_case(JavaScriptComponent::ENTITY_TYPE_ID, TRUE),
      ...$generate_per_config_entity_type_test_case(Pattern::ENTITY_TYPE_ID, FALSE),
      ...$generate_per_config_entity_type_test_case(Folder::ENTITY_TYPE_ID, FALSE),
      ...$generate_per_config_entity_type_test_case(Folder::ENTITY_TYPE_ID, FALSE),
      ...$generate_per_config_entity_type_test_case(AssetLibrary::ENTITY_TYPE_ID, TRUE),
      ...$generate_per_config_entity_type_test_case('non-existent', FALSE),
      ['canvas.api.content.auto-save.patch', [], FALSE],
      ['canvas.api.content.create', [], TRUE],
      ['canvas.api.content.delete', [], TRUE],
      ['canvas.api.content.get', ['canvas_page' => "1"], TRUE],
      ['canvas.api.content.get.by_uuid', ['canvas_page' => "550e8400-e29b-41d4-a716-446655440000"], TRUE],
      ['canvas.api.content.list', [], TRUE],
      ['canvas.api.content.patch', ['canvas_page' => "1"], TRUE],
      ['canvas.api.form.component_instance', [], FALSE],
      ['canvas.api.form.content_entity', [], FALSE],
      ['canvas.api.layout.get', [], FALSE],
      ['canvas.api.layout.patch', [], FALSE],
      ['canvas.api.layout.post', [], FALSE],
      ['canvas.api.log_error', [], FALSE],
      ['canvas.component.status', [], FALSE],
      ['canvas.boot.entity', [], FALSE],
      ['canvas.api.artifacts.upload', [], TRUE],
      ['canvas.api.media.upload', [], TRUE],
      ['canvas.api.push.complete', [], TRUE],
      ['canvas.api.push.fail', [], TRUE],
      ['canvas.api.push.start', [], TRUE],
    ];
  }

  /**
   * Tests whether the authentication provider applies to a route.
   *
   * @legacy-covers ::applies
   */
  #[DataProvider('dataProviderRoutes')]
  public function testApplies(string $route_name, array $parameters, bool $expected_apply): void {
    $route = new Route($this->container->get('router.route_provider')->getRouteByName($route_name)->getPath());
    $request = new Request();
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $route_name);
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    $request->attributes->set('_raw_variables', new InputBag($parameters));

    $this->assertFalse(
      $this->authProvider->applies($request),
      'The authentication provider should NOT apply without an access token.'
    );

    $request->headers->set('Authorization', 'Bearer token-123');
    $this->assertEquals(
      $this->authProvider->applies($request),
      $expected_apply,
      $expected_apply ? 'The authentication provider should apply' : 'The authentication provider should NOT apply'
    );
  }

}
