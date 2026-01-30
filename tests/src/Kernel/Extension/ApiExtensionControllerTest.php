<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Extension;

use Drupal\canvas\Extension\CanvasExtensionTypeEnum;
use Drupal\canvas\Entity\Page;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Session\AccountInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\canvas\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\Tests\canvas\Traits\CreateTestJsComponentTrait;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\canvas\Traits\OpenApiSpecTrait;
use Drupal\Tests\system\Functional\Cache\AssertPageCacheContextsAndTagsTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

#[Group('canvas')]
#[CoversFunction('ApiExtensionControllers::list')]
class ApiExtensionControllerTest extends KernelTestBase {

  use ContribStrictConfigSchemaTestTrait;
  use GenerateComponentConfigTrait;
  use OpenApiSpecTrait;
  use AssertPageCacheContextsAndTagsTrait;
  use CreateTestJsComponentTrait;
  use UserCreationTrait;
  use RequestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas',
    'canvas_test_extension',
    'canvas_test_extension_multiple',
    'user',
    'media',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);

    $user = $this->createUser();
    \assert($user instanceof AccountInterface);
    $this->setCurrentUser($user);
  }

  public function testList(): void {
    $module_location = $this->getModulePath('canvas_test_extension');

    $response = $this->request(Request::create('/canvas/api/v0/extensions'));
    $this->assertEquals(200, $response->getStatusCode(), 'Response status code is 200 OK');
    \assert($response instanceof CacheableJsonResponse);
    $this->assertSame([
      'user.permissions',
    ], $response->getCacheableMetadata()->getCacheContexts());
    $this->assertSame([
      'http_response',
    ], $response->getCacheableMetadata()->getCacheTags());
    $this->assertSame(Cache::PERMANENT, $response->getCacheableMetadata()->getCacheMaxAge());

    $json = static::decodeResponse($response);
    self::assertCount(3, $json);
    self::assertArrayHasKey('canvas_test_extension', $json);
    self::assertArrayHasKey('canvas_test_extension_multiple', $json);
    self::assertArrayHasKey('canvas_test_yet_another_extension', $json);

    $canvas_extension = $json['canvas_test_extension'];
    self::assertSame('canvas_test_extension', $canvas_extension['id']);
    self::assertSame('Canvas Test Extension', $canvas_extension['name']);
    self::assertSame('Demonstrates what a Canvas extension can do', $canvas_extension['description']);
    self::assertSame('/' . $module_location . '/index.html', $canvas_extension['url']);
    self::assertSame('/' . $module_location . '/icon.svg', $canvas_extension['icon']);
    self::assertSame(CanvasExtensionTypeEnum::Canvas->value, $canvas_extension['type']);
    self::assertSame('1.0', $canvas_extension['api_version']);

    // Assert we don't expose the security permissions, that's a security risk.
    self::assertArrayNotHasKey('permissions', $canvas_extension);
  }

}
